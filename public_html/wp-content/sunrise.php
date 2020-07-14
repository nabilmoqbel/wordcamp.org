<?php

namespace WordCamp\Sunrise;
defined( 'WPINC' ) || die();


/*
 * Matches `2020-foo.narnia.wordcamp.org/`, with or without additional `REQUEST_URI` params.
 */
const PATTERN_YEAR_DOT_CITY_DOMAIN_PATH = '
	@ ^
	( \d{4} [\w-]* )           # Capture the year, plus any optional extra identifier.
	\.
	( [\w-]+ )                 # Capture the city.
	\.
	( wordcamp | buddycamp )   # Capture the second-level domain.
	\.
	( org | test )             # Capture the top level domain.
	/
	@ix
';

/*
 * Matches `narnia.wordcamp.org/2020-foo/`, with or without additional `REQUEST_URI` params.
 */
const PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH = '
	@ ^
	( [\w-]+ )                 # Capture the city.
	\.
	( wordcamp | buddycamp )   # Capture the second-level domain.
	\.
	( org | test )             # Capture the top-level domain.
	( / \d{4} [\w-]* / )       # Capture the site path (the year, plus any optional extra identifier).
	@ix
';

/*
 * Matches a request URI like `/2020/2019/save-the-date-for-wordcamp-vancouver-2020/`.
 */
const PATTERN_CITY_SLASH_YEAR_REQUEST_URI_WITH_DUPLICATE_DATE = '
	@ ^
	( / \d{4} [\w-]* / )   # Capture the site path (the year, plus any optional extra identifier).

	(                      # Capture the `/%year%/%monthnum%/%day%/` permastruct tags.
		[0-9]{4} /         # The year is required.

		(?:                # The month and day are optional.
			[0-9]{2} /
		){0,2}
	)

	(.+)                   # Capture the slug.
	$ @ix
';


/*
 * Allow legacy CLI scripts in local dev environments to override the server hostname.
 *
 * This makes it possible to run bin scripts in local environments that use different domain names (e.g., wordcamp.dev)
 * without having to swap the config values back and and forth.
 */
if ( 'cli' === php_sapi_name() && defined( 'CLI_HOSTNAME_OVERRIDE' ) ) {
	$_SERVER['HTTP_HOST'] = str_replace( 'wordcamp.org', CLI_HOSTNAME_OVERRIDE, $_SERVER['HTTP_HOST'] );
}

// Redirecting would interfere with bin scripts, unit tests, etc.
if ( php_sapi_name() !== 'cli' ) {
	main();
}


/**
 * Preempt `ms_load_current_site_and_network()` in order to set the correct site.
 */
function main() {
	list(
		'domain' => $domain,
		'path'   => $path
	) = guess_requested_domain_path();

	add_action( 'template_redirect', __NAMESPACE__ . '\redirect_duplicate_year_permalinks_to_post_slug' );

	$status_code = 301;
	$redirect = site_redirects( $domain, $_SERVER['REQUEST_URI'] );

	if ( ! $redirect ) {
		$redirect = get_city_slash_year_url( $domain, $_SERVER['REQUEST_URI'] );
	}

	if ( ! $redirect ) {
		$redirect = unsubdomactories_redirects( $domain, $_SERVER['REQUEST_URI'] );
	}

	// has to run before get_canonical_year_url() b/c that would redirect it to the wrong site, and wouldn't add the path
	// but run late though b/c it also does a db query in some cases
	if ( ! $redirect ) {
		$redirect = get_corrected_root_relative_url( $domain, $path, $_SERVER['REQUEST_URI'], $_SERVER['HTTP_REFERER'] ?? '' );

		//var_dump($redirect);die('done');

		if ( $redirect ) {
			//document reason for this
			// wait though should this be a 301 redirect? can't because there could be links from 2020.europe and 2021.europe
			// so it has to be a 302
			$status_code = 302;
		}
	}

	// Do this one last, because it sometimes executes a database query.
	if ( ! $redirect ) {
		$redirect = get_canonical_year_url( $domain, $path );
	}

	if ( ! $redirect ) {
		return;
	}

	header( 'Location: ' . $redirect, true, $status_code );
	die();
}

/**
 * Get the TLD for the current environment.
 *
 * @return string
 */
function get_top_level_domain() {
	return 'local' === WORDCAMP_ENVIRONMENT ? 'test' : 'org';
}

/**
 * Parse the `$wpdb->blogs` `domain` and `path` out of the requested URL.
 *
 * This is only an educated guess, and cannot work in all cases (e.g., `central.wordcamp.org/2020` (year archive
 * page). It should only be used in situations where WP functions/globals aren't available yet, and should be
 * verified in whatever context you use it, e.g., with a database query. That's not done here because it wouldn't
 * be performant, and this is good enough to short-circuit the need for that in most situations.
 *
 * @return array
 */
function guess_requested_domain_path() {
	$request_path = trailingslashit( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

	$is_slash_year_site = preg_match(
		PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH,
		$_SERVER['HTTP_HOST'] . $request_path,
		$matches
	);

	$domain    = filter_var( $_SERVER['HTTP_HOST'], FILTER_VALIDATE_DOMAIN );
	$site_path = $is_slash_year_site ? $matches[4] : '/';

	return array(
		'domain' => $domain,
		'path'   => $site_path,
	);
}

/**
 * Get redirect URLs for root site requests and for hardcoded redirects.
 *
 * @todo Split this into two functions because these aren't related to each other.
 *
 * @param string $domain
 * @param string $request_uri
 *
 * @return string
 */
function site_redirects( $domain, $request_uri ) {
	$tld              = get_top_level_domain();
	$domain_redirects = get_domain_redirects();
	$redirect         = false;

	// If it's a front end request to the root site, redirect to Central.
	// todo This could be simplified, see https://core.trac.wordpress.org/ticket/42061#comment:15.
	if ( in_array( $domain, array( "wordcamp.$tld", "buddycamp.$tld" ), true )
		 && ! is_network_admin()
		 && ! is_admin()
		 && ! preg_match( '/^\/(?:wp\-admin|wp\-login|wp\-cron|wp\-json|xmlrpc)\.php/i', $request_uri )
	) {
		$redirect = sprintf( '%s%s', NOBLOGREDIRECT, $request_uri );

	} elseif ( isset( $domain_redirects[ $domain ] ) ) {
		$new_url = $domain_redirects[ $domain ];

		// Central has a different content structure than other WordCamp sites, so don't include the request URI
		// if that's where we're going.
		if ( "central.wordcamp.$tld" !== $new_url ) {
			$new_url .= $request_uri;
		}

		$redirect = "https://$new_url";
	}

	return $redirect;
}

/**
 * Centralized place to define domain-based redirects.
 *
 * Used by sunrise.php and WordCamp_Lets_Encrypt_Helper::rest_callback_domains.
 *
 * @return array
 */
function get_domain_redirects() {
	$tld     = get_top_level_domain();
	$central = "central.wordcamp.$tld";

	$redirects = array(
		// Central redirects.
		"bg.wordcamp.$tld"   => $central,
		"utah.wordcamp.$tld" => $central,

		// Language redirects.
		"ca.2014.mallorca.wordcamp.$tld" => "2014-ca.mallorca.wordcamp.$tld",
		"de.2014.mallorca.wordcamp.$tld" => "2014-de.mallorca.wordcamp.$tld",
		"es.2014.mallorca.wordcamp.$tld" => "2014-es.mallorca.wordcamp.$tld",
		"fr.2011.montreal.wordcamp.$tld" => "2011-fr.montreal.wordcamp.$tld",
		"fr.2012.montreal.wordcamp.$tld" => "2012-fr.montreal.wordcamp.$tld",
		"fr.2013.montreal.wordcamp.$tld" => "2013-fr.montreal.wordcamp.$tld",
		"fr.2014.montreal.wordcamp.$tld" => "2014-fr.montreal.wordcamp.$tld",
		"2014.fr.montreal.wordcamp.$tld" => "2014-fr.montreal.wordcamp.$tld",
		"fr.2013.ottawa.wordcamp.$tld"   => "2013-fr.ottawa.wordcamp.$tld",

		// Year & name change redirects.
		"2006.wordcamp.$tld"                      => "sf.wordcamp.$tld/2006",
		"2007.wordcamp.$tld"                      => "sf.wordcamp.$tld/2007",
		"2012.torontodev.wordcamp.$tld"           => "2012-dev.toronto.wordcamp.$tld",
		"2013.windsor.wordcamp.$tld"              => "2013.lancaster.wordcamp.$tld",
		"2014.lima.wordcamp.$tld"                 => "2014.peru.wordcamp.$tld",
		"2014.london.wordcamp.$tld"               => "2015.london.wordcamp.$tld",
		"2016.pune.wordcamp.$tld"                 => "2017.pune.wordcamp.$tld",
		"2016.bristol.wordcamp.$tld"              => "2017.bristol.wordcamp.$tld",
		"2017.cusco.wordcamp.$tld"                => "2018.cusco.wordcamp.$tld",
		"2017.dayton.wordcamp.$tld"               => "2018.dayton.wordcamp.$tld",
		"2017.niagara.wordcamp.$tld"              => "2018.niagara.wordcamp.$tld",
		"2017.saintpetersburg.wordcamp.$tld"      => "2018.saintpetersburg.wordcamp.$tld",
		"2017.zilina.wordcamp.$tld"               => "2018.zilina.wordcamp.$tld",
		"2018.wurzburg.wordcamp.$tld"             => "2018.wuerzburg.wordcamp.$tld",
		"2019.lisbon.wordcamp.$tld"               => "2019.lisboa.wordcamp.$tld",
		"2018.kolkata.wordcamp.$tld"              => "2019.kolkata.wordcamp.$tld",
		"2018.montclair.wordcamp.$tld"            => "2019.montclair.wordcamp.$tld",
		"2018.pune.wordcamp.$tld"                 => "2019.pune.wordcamp.$tld",
		"2018.dc.wordcamp.$tld"                   => "2019.dc.wordcamp.$tld",
		"2019.sevilla.wordcamp.$tld"              => "2019-developers.sevilla.wordcamp.$tld",
		"2019.telaviv.wordcamp.$tld"              => "2020.telaviv.wordcamp.$tld",
		"2020-barcelona.publishers.wordcamp.$tld" => "barcelona.wordcamp.$tld/2020",
		"2020.losangeles.wordcamp.$tld"           => "2020.la.wordcamp.$tld",
		"2020.bucharest.wordcamp.$tld"            => "2021.bucharest.wordcamp.$tld",
		"philly.wordcamp.$tld"                    => "philadelphia.wordcamp.$tld",
		"2010.philly.wordcamp.$tld"               => "philadelphia.wordcamp.$tld/2010",
		"2011.philly.wordcamp.$tld"               => "philadelphia.wordcamp.$tld/2011",
		"2012.philly.wordcamp.$tld"               => "philadelphia.wordcamp.$tld/2012",
		"2014.philly.wordcamp.$tld"               => "philadelphia.wordcamp.$tld/2014",
		"2015.philly.wordcamp.$tld"               => "philadelphia.wordcamp.$tld/2015",
		"2017.philly.wordcamp.$tld"               => "philadelphia.wordcamp.$tld/2017",
		"2018.philly.wordcamp.$tld"               => "philadelphia.wordcamp.$tld/2018",
		"2019.philly.wordcamp.$tld"               => "philadelphia.wordcamp.$tld/2019",

		/*
		 * External domains.
		 *
		 * Unlike the others, these should keep the actual TLD in the array key, because they don't exist in the database.
		 */
		'wordcampsf.org' => "sf.wordcamp.$tld",
		'wordcampsf.com' => "sf.wordcamp.$tld",
	);

	// The array values are treated like a domain, and will be slashed by the caller.
	array_walk( $redirects, 'untrailingslashit' );

	return $redirects;
}

/**
 * Redirects from `year.city.wordcamp.org` to `city.wordcamp.org/year`.
 *
 * This is needed so that old external links will redirect to the current URL structure. New cities don't need to
 * be added to this list, only the ones that were migrated from the old structure to the new structure in July 2020.
 *
 * See https://make.wordpress.org/community/2020/03/03/proposal-for-wordcamp-sites-seo-fixes/
 *
 * @param string $domain
 * @param string $request_uri
 *
 * @return string
 */
function get_city_slash_year_url( $domain, $request_uri ) {
	$tld = get_top_level_domain();

	$redirect_cities = array(
		'barcelona', 'chicago', 'columbus', 'geneve', 'philly', 'philadelphia', 'publishers',
		'athens', 'atlanta', 'austin', 'brighton', 'europe', 'nyc', 'newyork', 'organizers', 'rhodeisland', 'sf',
		'cincinnati', 'dayton', 'denmark', 'finland', 'india', 'seattle', 'sunshinecoast', 'testing', 'varna',
	);

	if ( ! preg_match( PATTERN_YEAR_DOT_CITY_DOMAIN_PATH, $domain . $request_uri, $matches ) ) {
		return false;
	}

	$year = $matches[1];
	$city = strtolower( $matches[2] );

	if ( ! in_array( $city, $redirect_cities ) ) {
		return false;
	}

	return sprintf( 'https://%s.wordcamp.%s/%s%s', $city, $tld, $year, $request_uri );
}

/**
 * Redirects from city.wordcamp.org/year to year.city.wordcamp.org.
 *
 * This reverses the 2014 migration, so that sites use the year.city format again. Now that we've redoing the
 * migration, cities will be moved out of `$redirect_cities` until none remain.
 *
 * @param string $domain
 * @param string $request_uri
 *
 * @return string|false
 */
function unsubdomactories_redirects( $domain, $request_uri ) {
	$redirect_cities = array(
		'russia', 'london', 'tokyo', 'portland', 'sofia', 'miami',
		'montreal', 'phoenix', 'slc', 'boston', 'norway', 'orlando', 'dallas', 'melbourne',
		'oc', 'la', 'vegas', 'capetown', 'victoria', 'birmingham', 'birminghamuk', 'ottawa', 'maine',
		'albuquerque', 'sacramento', 'toronto', 'calgary', 'porto', 'tampa', 'sevilla',
		'seoul', 'paris', 'osaka', 'kansascity', 'curitiba', 'buffalo', 'baroda', 'sandiego', 'nepal', 'raleigh',
		'baltimore', 'sydney', 'providence', 'dfw', 'copenhagen', 'lisboa', 'kansai',
		'biarritz', 'charleston', 'buenosaires', 'krakow', 'vienna', 'grandrapids', 'hamilton', 'minneapolis',
		'stlouis', 'edinburgh', 'winnipeg', 'northcanton', 'portoalegre', 'sanantonio', 'prague',
		'denver', 'slovakia', 'salvador', 'maui', 'hamptonroads', 'houston', 'warsaw', 'belgrade', 'mumbai',
		'belohorizonte', 'lancasterpa', 'switzerland', 'romania', 'saratoga', 'fayetteville',
		'bournemouth', 'hanoi', 'saopaulo', 'cologne', 'louisville', 'mallorca', 'annarbor', 'manchester',
		'laspenitas', 'israel', 'ventura', 'vancouver', 'peru', 'auckland', 'norrkoping', 'netherlands',
		'hamburg', 'nashville', 'connecticut', 'sheffield', 'wellington', 'omaha', 'milwaukee', 'lima',
		'asheville', 'riodejaneiro', 'wroclaw', 'santarosa', 'edmonton', 'lancaster', 'kenya',
		'malaga', 'lithuania', 'detroit', 'kobe', 'reno', 'indonesia', 'transylvania', 'mexico', 'nicaragua',
		'gdansk', 'bologna', 'milano', 'catania', 'modena', 'stockholm', 'pune', 'jerusalem', 'philippines',
		'newzealand', 'cuttack', 'ponce', 'jabalpur', 'singapore', 'poznan', 'richmond', 'goldcoast', 'caguas',
		'savannah', 'ecuador', 'boulder', 'rdu', 'nc', 'lyon', 'scranton', 'brisbane', 'easttroy',
		'croatia', 'cantabria', 'greenville', 'jacksonville', 'nuremberg', 'berlin', 'memphis', 'jakarta',
		'pittsburgh', 'nola', 'neo', 'antwerp', 'helsinki', 'vernon', 'frankfurt', 'torino', 'bilbao', 'peoria',
		'gdynia', 'lehighvalley', 'lahore', 'bratislava', 'rochester', 'okc',
	);

	$tld = 'local' === WORDCAMP_ENVIRONMENT ? 'test' : 'org';

	// Return if already on a 4th-level domain (e.g., 2020.narnia.wordcamp.org).
	if ( ! preg_match( "#^([a-z0-9-]+)\.wordcamp\.$tld$#i", $domain, $matches ) ) {
		return false;
	}

	$city = strtolower( $matches[1] );
	if ( ! in_array( $city, $redirect_cities, true ) ) {
		return false;
	}

	// If can't pick a year out of the path, return.
	// Extra alpha characters are included, for sites like `seattle.wordcamp.org/2015-beginners`.
	if ( ! preg_match( '#^/(\d{4}[a-z0-9-]*)#i', $request_uri, $matches ) ) {
		return false;
	}

	$year        = strtolower( $matches[1] );
	$pattern     = '#' . preg_quote( $year, '#' ) . '#';
	$path        = preg_replace( $pattern, '', $request_uri, 1 );
	$path        = str_replace( '//', '/', $path );
	$redirect_to = sprintf( "https://%s.%s.wordcamp.$tld%s", $year, $city, $path );

	return $redirect_to;
}

/**
 * Get the URL of the newest site for a given city.
 *
 * For example, `seattle.wordcamp.org` -> `seattle.wordcamp.org/2020`.
 *
 * Redirecting the city root to this URL makes it easier for attendees to find the correct site.
 *
 * @param string $domain
 * @param string $path
 *
 * @return string|false
 */
function get_canonical_year_url( $domain, $path ) {
	global $wpdb;

	// todo return early here if it's a city/year site?
	// wait, it must be already b/c otherwise it'd be an infinite loop right?
	// not aware of any problems being caused, but seems like good idea for safety

	$tld       = get_top_level_domain();
	$cache_key = 'current_blog_' . $domain;

	/**
	 * Read blog details from the cache key and set one for the current
	 * domain if exists to prevent lookups by core later.
	 */
	$current_blog = wp_cache_get( $cache_key, 'site-options' );

	if ( $current_blog ) {
		return false;
	}

	$current_blog = get_blog_details(
		array(
			'domain' => $domain,
			'path'   => $path,
		),
		false
	);

	if ( $current_blog ) {
		wp_cache_set( $cache_key, $current_blog, 'site-options' );
		// ^ isn't used by core or anywhere else in the codebase, so would only help if we had memcache
		// should probably change this to be a transient, because the query below uses `filesort`, which is pretty bad
		// also update the `cache_get()` above

		return false;
	}

	// Return early if not a third- or fourth-level domain, e.g., city.wordcamp.org, year.city.wordcamp.org.
	$domain_parts = explode( '.', $domain );

	if ( 2 >= count( $domain_parts ) ) {
		return false;
	}

	// Default clause for retrieving the most recent year for a city.
	$like = "%.{$domain}";

	// Special cases where the redirect shouldn't go to next year's camp until this year's camp is over.
	switch ( $domain ) {
		case "europe.wordcamp.$tld":
			if ( time() <= strtotime( '2020-06-07' ) ) {
				return "https://europe.wordcamp.$tld/2020/";
			}
			break;

		case "us.wordcamp.$tld":
			if ( time() <= strtotime( '2019-11-30' ) ) {
				return "https://2019.us.wordcamp.$tld/";
			}
			break;
	}

	$latest = $wpdb->get_row( $wpdb->prepare( "
		SELECT `domain`, `path`
		FROM $wpdb->blogs
		WHERE
			domain = %s OR -- Match city/year format.
			domain LIKE %s -- Match year.city format.
		ORDER BY path DESC, domain DESC
		LIMIT 1;",
		$domain,
		$like
	) );

	return $latest ? 'https://' . $latest->domain . $latest->path : false;
}

/**
 * Redirect `/year-foo/%year%/%monthnum%/%day%/%postname%/` permalinks to `/%postname%/`.
 *
 * `year-foo` is the _site_ slug, while `%year%` is part of the _post_ slug. This makes sure that URLs on old sites
 * won't have two years in them after the migration, which would look confusing.
 *
 * See https://make.wordpress.org/community/2014/12/18/while-working-on-the-new-url-structure-project/.
 *
 * Be aware that this does create a situation where posts and pages can have conflicting slugs, see
 * https://core.trac.wordpress.org/ticket/13459.
 */
function redirect_duplicate_year_permalinks_to_post_slug() {
	$current_blog_details = get_blog_details( null, false );

	$redirect_url = get_post_slug_url_without_duplicate_dates(
		is_404(),
		get_option( 'permalink_structure' ),
		$current_blog_details->domain,
		$current_blog_details->path,
		$_SERVER['REQUEST_URI']
	);

	if ( ! $redirect_url ) {
		return;
	}

	wp_safe_redirect( esc_url_raw( $redirect_url ), 301 );
	die();
}

/**
 * Build the redirect URL for a duplicate-date URL.
 *
 * See `redirect_duplicate_year_permalinks_to_post_slug()`.
 *
 * @param bool   $is_404
 * @param string $permalink_structure
 * @param string $domain
 * @param string $path
 * @param string $request_uri
 *
 * @return bool|string
 */
function get_post_slug_url_without_duplicate_dates( $is_404, $permalink_structure, $domain, $path, $request_uri ) {
	if ( ! $is_404 ) {
		return false;
	}

	if ( '/%postname%/' !== $permalink_structure ) {
		return false;
	}

	if ( ! preg_match( PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH, $domain . $path ) ) {
		return false;
	}

	if ( ! preg_match( PATTERN_CITY_SLASH_YEAR_REQUEST_URI_WITH_DUPLICATE_DATE, $request_uri, $matches ) ) {
		return false;
	}

	return sprintf(
		'https://%s%s%s',
		$domain,
		$path,
		$matches[3]
	);
}





















/*
 *
 * searched db for examples, came up with tons. looks like we'll need to fix these b/c too much would be broken otherwise
 * results: https://a8c.slack.com/archives/C8H02V2TV/p1594253427105000
 *
 * posts:  wp db search 'href="/' $( wp db tables '*_posts' --network --format=list ) --network --table_column_once
 *      probably need to use --all-tables there on sandbox
 *
 * css: wp db search "url('/" $( wp db tables '*_posts' --all-tables --format=list ) --all-tables --table_column_once
 *      ( note that --network doesn't work for some reason, need --all-tables )
 *      need to limit to just published posts, not revisions
 *      need to check single quotes around href value instead of double - well, do in the code to fix it, but when just searching for examples you prob don't need to worry about that

*      could update them for security (https://www.paulirish.com/2010/the-protocol-relative-url/) but prob not worth it
 * there thousands of hits, so this isn't an edge case. maybe check w/ alex to see if he still wants to avoid it
 *
 * menu: wp db search '^/' $( wp db tables '*_postmeta' --all-tables --format=list ) --all-tables --table_column_once --regex
 *
 * think about single site considerations for core? ways to have a `%%SITEPATH%%` token when using `/foo` links, or something?
 *      or anything else that can be pulled out of this to help the wider community?
 *
 *
 *
	// post, page, other cpts   -> post_content, post_excerpt - `<a href="/tickets">` dont assume there aren't extra attributes though, maybe dont even assume that href is the first attr
	// session cpt              -> above plus `Link to slides` postmeta
	// nav_menu_item            -> postmeta _menu_item_url. can just look at postmeta directly, don't have to get_posts.
	// custom_css               -> url() single/double quotes, don't assume not other things around it
		// also `../../foo.jpg` links? dont think so, but search to see if any exampples

	// comments                 -> comment_content - don't need to worry? search to verify. or don't bother

	// options                  -> probably can't do general, but search for absolute and relative urls on prod 'cause maybe some specific options we can look at
	// widgets                  -> maybe only the html and text ones?
		// ugh `widget_text` is serialized array of all of them
		// widget_custom_html

 */



// document why this is needed - before url migration, links like `/tickets` would go to `2020.europe.wordcamp.org/tickets`, but now it goes to `europe.wordcamp.org/tickets`
// can't know which site to redirect that to w/out bad seo consequence and complicated fragileness
// better to fix them
// done dynamically to b/c safer. can always fix bugs if needed
//
// todo ------- this is the versino that checks the request referrer
// explain that the site used to be `2020.europe.wordcamp.org`, and had links as `/tickets`. that worked fine before migration, but after it points to `europe.wordcamp.org/tickets`, so need to get `2020/tickets`
//
// // this will stop working for images if we ever disable ms-files

// this creates situation where a site could have linked to `https://city.wordcamp.org/`, to intentionally have it be redirected to the latest site
// but then now it'd be redirected to the older site's homepage, because of the referrer
// probably an edge case that's not worth fixing

// this won't catch things that don't pass through wp, like links to CSS and JS files
	// there shouldn't be any manual links to those, though, since we don't allow unfiltered HTML
	// maybe on some pre-2009 sites with custom themes, but don't worry about those
function get_corrected_root_relative_url( $domain, $path, $request_uri, $referer ) {
	// if there's a referral from a subsite
	// and the current request uri doesn't have a subsite
	// and it's just a city root url, and doesn't match the city/year or year.city
	// then redirect

	// todo document why retuning in each of these cases
	// current site is not a root site
	if ( preg_match( PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH, $domain . $path ) ) {
		return false;
	}

	// current site is not a root site
	if ( preg_match( PATTERN_YEAR_DOT_CITY_DOMAIN_PATH, $domain . $path ) ) {
		return false;
	}

	// maybe those 2 above should check that it _does_ match `{city}.wordcamp.org/{anything}`, not that it _doesn't_ match the year.city or city/year formats.
		// that'd make it more precise and future proof if we change formats again sometime
	// also make sure it's not a request to the root wordcamp.ogr site, like https://wordcamp.org/schedule

	// referrer isn't a new url format site
	$referer_parts = parse_url( $referer );

	if ( ! isset( $referer_parts['host'], $referer_parts['path'] ) ) {
		return false;
	}

	$modified_referer = $referer_parts['host'] . $referer_parts['path'];

	if ( ! preg_match( PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH, $modified_referer ) ) {
		return false;
	}

	// if $request_uri doesn't start with '/', return ?
		// or it always will, regardless of whether or not it's the type of link you're targeting?

	// handle case where $request_uri is empty string - that means the link was to `/` so it wants the homepage, right?





	// canonical is also doing this
	// should set cache key like them?
	// but would need to make it transient
	// or maybe call this in main() and have it passed in?
	// but only once reach the point where it's needed
	$current_blog = get_blog_details(
		array(
			'domain' => $domain,
			'path'   => $path,
		),
		false
	);

	// but how to know the current site id b/c not active yet? have to look at the referral and do a db lookup?
		// or is it the id of the site being redirected to? i guess they're the same right?
//	if ( $current_blog->blog_id <= 1341 ) {
//		// update # when migration done
//		return false;
//	}

	// maybe just pass in the referreal domain and path instead of current url. can get those from guess_requested_doan_path?
		// would need to refactor to pass in $_SERVER vars, but that'd be more correct anyway.

	// or jjust parse date out of ref url, and return if it's lower then 2021
	//  // have to account for the -extra identifier it
	//// existing pattern might do that for you



	// if the current url doesn't match the pattern for `city.wordcamp.org/{something}` - can't be the home page of the city site, has to have some kind of subpage
		// return
		// er, wait, does it have to have a subpage? what about links to just `/` that were meant for to go to `2018.europe.wordcamp.org/` ?



	// what about CSS though, will it work for that?
		// edge case of an edge case, so prob not that important
		// but looks like jpgs are passing through sunrise, at least in dev.
		// test on prod, also test css
	// could use `the_content` though, and check if it's a `custom_css` post type
	// just make note about that in the docblock as a todo

	// use postmeta or menu-specific filters to get nav links?
		// probably have a single model function that does the string-replace, but multiple callers, each for a specific type of data

	// maybe update the db with the new string in some cases?

	// maybe move this stuff to a new file? because if it runs after sunrise?
	// like sunrise-hooks or something? but as an mu-plugin? ugh naming is hard



	// don't worry about broken css/js links on old custom themes b/c not worth it
	// but do still check 2006/2007.sf though, b/c matt has sentimental attachment to them


   // write unit tests
		// test referrer  has extra stuff after the site path

	return untrailingslashit( $referer ) .  $request_uri ;
		// add traliing slash around request_uri ?
			// if don't, then will have an extra redirect for pages like `/tickets` to go to `/tickets/`
			// but don't want it for images like `/files/foo.jpg`
			// also don't want it for things like '/tickets?foo=5' ?

	// todo breaks when referrer is subpage like /2014/stuff/, it redirects to /2014/stuff/tickets instead of /2014/tickets
}












/// unused older attempts below this line
///
///

//
//rename relative links
//+       // return early if site id < 1375 or whatever
//+       // return if url doesn't match city/year pattern
//+       // write unit tests
//
// todo ------- this seems like an older idea that wasn't fleshed out. might be some useful comments, but look at todo_ref_version() and add_site_path...() first
//function rename_relative_links() {
	// multidimen so include if it's a link, post, etc?

	// this runs before the db values are changed; is that what you want?

	/*

	well, frak.
	relative links like / in nav, post content, etc will break
	have to fix before migrating any other sites
	also have to fix retroactively for sites that were already migrated

	find examples
		nav menu start with `/`
		links in posts start w/ `/`
			only updated published ones, or revisions too? drafts too?
		any other type places that could have links?
			widgets
			css - omg

		options - impossible to parse out?

		look at 2020.europe

		need to match "href="/{anything except another /} but not href="//{anything}

	 */


	/* todo try to ignore false positives like this:
	.pointy {
	background: transparent url('//2019.philadelphia.wordcamp.org/files/20

	i mean, it's a correct match, but shouldn't be changed. maybe regex to start with ^/files for images
	maybe dont match double slashes?

	it's a protocol-less url, to avoid http vs https issues. it has the full domain in there though, so it should be fine.
	prob need to add an extra regex to detect it though. don't wanna modify the main ones b/c this is an edge case
	prob just check if first 2 chars are `//` and return if that
	*/

	// https://regex101.com/r/3qzF2w/2/ for test cases
		// todo update

	// todo probably do this dynamically on old sites, rather than modifying the db?
		// that'd be safer and might be able to have the same effect


	// a href in all of them, except for css which is url()
//	$posts = get_posts( array(
//		// all types, or maybe hardcode a list
//	) );
//
//	foreach ( $posts as $post ) {
//		preg_match( 'todo', $post->post_content, $relative_links );
//
//		foreach ( $relative_links as $link ) {
//			$new_link        = 'todo';
//			$renamed_links[] = array( $post->post_type, $link, $new_link );
//
//
//
//			if ( ! $dry_run ) {
//				// rename it -- preg_replace() ?
//
//				// when updating, make sure don't hit false posivitves
//				// should replace multiple times
//				// maybe do them all at once, or maybe do an update for each one?
//
//				// probably replace with absolute links, to avoid ambiguoity. seems like that'd reduce the chance of false posivies now and in future
//			}
//		}
//	}

	// also options

	// maybe lots of functions, for each type?
	// just change on the fly, myube this main func just registers actions?
	// want funcs to be testable though

	// document why this is needed - before url migration, links like `/tickets` would go to `2020.europe.wordcamp.org/tickets`, but now it goes to `europe.wordcamp.org/tickets`
	// can't know which site to redirect that to w/out bad seo consequence and complicated fragileness
	// better to fix them
	// done dynamically to b/c safer. can always fix bugs if needed
//}


/* if do go forward, look for existing tools to rewrite links in post_content. feel like have maybe seen some things before.
 *      those were probably for domain name changes, but maybe something for relative links that can adapt. or might find something that's just lke this.
 *      `wp help search-replace` - might be better to do in php though so have context of things like home_url(), PATTERN_*, etc
 * we use this when manually changing url: `wp search-replace '2016.foo.wordcamp.org' '2017.foo.wordcamp.org' --url=$(wp site url {site-id}) --all-tables-with-prefix --dry-run`
 *      might need to be run on each site rather than all at once. should work w/ serialized values
*/


//add_filter( 'the_content', __NAMESPACE__ . '\add_site_path_hook_wrapper' );
	// todo ------- this is the version that's trying to rename content on the fly
	// disabled b/c seems like more complicated than the referral version, but might need to come back to it if the referral version doesn't work out
//function add_site_path_hook_wrapper( $string ) {
//	// return early if in wp_admin, so don't overwrite database values ever? also edit context in rest api?
//	switch( did_action() ) {
//		case 'the_content':
//			$type = 'post_content';
//	}
//
//	return add_site_path_to_root_urls( $string, $type );
//
//
//	// this is all pretty ugly, so once you have a rough idea of what you think is best, post it in slack to get feedback from others
//		// other people will come at it from different angles, and often think of something you didn't
//}
//
//function add_site_path_to_root_urls( $string, $type ) {
//
//	$site_path_url = preg_replace( '', '$replace_todo_huh', $string );
//
//	// have to preg_match, then for each one add an array item w/ the replacement, then preg_repalce?
//
//	// maybe it's simpler than all that? maybe can just replace `href="/{not site path}{anything}` with `href="/{site_path}{whatever was here before}`
//
//	return $site_path_url;
//}


