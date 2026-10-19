<?php
/**
 * Removes plugin data on uninstall (workshops, registrations, categories, settings).
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'lat_settings' );
delete_option( 'lat_cats_seeded' );
delete_option( 'lat_version' );
delete_option( 'lat_flush_rewrite' );
wp_clear_scheduled_hook( 'lat_send_reminders' );
wp_clear_scheduled_hook( 'lat_release_holds' );

foreach ( array( 'lat_workshop', 'lat_registration' ) as $lat_type ) {
	$lat_ids = get_posts(
		array(
			'post_type'   => $lat_type,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);
	foreach ( $lat_ids as $lat_id ) {
		wp_delete_post( $lat_id, true );
	}
}

// The plugin is not loaded during uninstall, so the taxonomy must be registered to query it.
register_taxonomy( 'lat_category', 'lat_workshop' );
$lat_terms = get_terms(
	array(
		'taxonomy'   => 'lat_category',
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);
if ( ! is_wp_error( $lat_terms ) ) {
	foreach ( $lat_terms as $lat_term ) {
		wp_delete_term( $lat_term, 'lat_category' );
	}
}
