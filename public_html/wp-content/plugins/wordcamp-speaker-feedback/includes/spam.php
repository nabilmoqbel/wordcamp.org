<?php

namespace WordCamp\SpeakerFeedback\Spam;

use WP_Error;
use Grunion_Contact_Form_Plugin;
use function WordCamp\SpeakerFeedback\CommentMeta\get_feedback_meta_field_schema;

defined( 'WPINC' ) || die();

/**
 * Check a feedback comment against WP's blacklist and Akismet.
 *
 * @param array $comment_data
 * @param array $comment_meta
 *
 * @return string One of three values: 'spam', 'not spam', or 'discard' (for really egregious spam).
 */
function spam_check( array $comment_data, array $comment_meta = array() ) {
	$meta = $comment_data['comment_meta'] ?? $comment_meta;

	// Inject feedback meta strings as the comment content for the purposes of checking for spam.
	$comment_data['comment_content'] = get_consolidated_meta_string( $meta );

	if ( is_blacklisted( $comment_data ) ) {
		return 'spam';
	}

	$akismet_check = is_akismet_spam( $comment_data );

	if ( is_wp_error( $akismet_check ) ) {
		return 'discard';
	} elseif ( true === $akismet_check ) {
		return 'spam';
	}

	return 'not spam';
}

/**
 * Check a feedback comment against WP's blacklist.
 *
 * @param array $comment_data
 *
 * @return bool
 */
function is_blacklisted( array $comment_data ) {
	$defaults     = array(
		'comment_author'       => '',
		'comment_author_email' => '',
		'comment_author_url'   => '',
		'comment_content'      => '',
		'user_ip'              => $_SERVER['REMOTE_ADDR'],
		'user_agent'           => $_SERVER['HTTP_USER_AGENT'],
	);
	$comment_data = wp_parse_args( $comment_data, $defaults );

	$blacklisted = wp_blacklist_check(
		$comment_data['comment_author'],
		$comment_data['comment_author_email'],
		$comment_data['comment_author_url'],
		$comment_data['comment_content'],
		$comment_data['user_ip'],
		$comment_data['user_agent']
	);

	return $blacklisted;
}

/**
 * Check a feedback comment against Akismet, using methods from Jetpack's Contact Form module.
 *
 * Akismet's default methods are too opinionated about comments to useful to us here. Rolling our own methods
 * seems unnecessary since Jetpack's are quite serviceable and should always be available on WordCamp sites.
 *
 * @param array $comment_data
 *
 * @return bool|WP_Error
 */
function is_akismet_spam( array $comment_data ) {
	if ( ! class_exists( 'Grunion_Contact_Form_Plugin' ) ) {
		return false;
	}

	$grunion = Grunion_Contact_Form_Plugin::init();

	$prepared_data = $grunion->prepare_for_akismet( $comment_data );

	return $grunion->is_spam_akismet( false, $prepared_data );
}

/**
 * Consolidate all the string meta values into one string.
 *
 * This should only be used to check the meta content for spam. The concatenated meta strings won't make
 * sense to display without the context of the questions they are answering.
 *
 * @param array $meta
 *
 * @return string
 */
function get_consolidated_meta_string( array $meta ) {
	$schema = array_keys( get_feedback_meta_field_schema() );
	$string = '';

	foreach ( $schema as $key => $props ) {
		if ( 'string' !== $props['type'] ) {
			continue;
		}

		if ( isset( $meta[ $key ] ) ) {
			if ( is_array( $meta[ $key ] ) ) {
				$value = $meta[ $key ][0];
			} else {
				$value = $meta[ $key ];
			}

			$string .= "{$value}\n\n";
		}
	}

	return $string;
}
