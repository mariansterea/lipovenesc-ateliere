<?php
/**
 * Plugin Name:       Lipovenesc Evenimente
 * Description:       Evenimente în curte cu înscriere online: categorii cu reguli proprii (minim și maxim participanți, prețuri adult/copil), rezervare sau bilete plătite prin WooCommerce, bilete cu cod QR și check-in, listă de așteptare, confirmări și reamintiri pe email, pagini proprii de eveniment cu date structurate, shortcode-uri pentru embed în articole.
 * Version:           2.1.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Daniela & Marian Sterea
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://lipovenesc.ro/lipovenesc-ateliere
 * Text Domain:       lipovenesc-ateliere
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'ABSPATH' ) || exit;

define( 'LAT_VERSION', '2.1.2' );
define( 'LAT_FILE', __FILE__ );
define( 'LAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'LAT_URL', plugin_dir_url( __FILE__ ) );

require_once LAT_DIR . 'includes/class-lat-helpers.php';
require_once LAT_DIR . 'includes/class-lat-categories.php';
require_once LAT_DIR . 'includes/class-lat-workshops.php';
require_once LAT_DIR . 'includes/class-lat-registrations.php';
require_once LAT_DIR . 'includes/class-lat-emails.php';
require_once LAT_DIR . 'includes/class-lat-rest.php';
require_once LAT_DIR . 'includes/class-lat-frontend.php';
require_once LAT_DIR . 'includes/class-lat-tickets.php';
require_once LAT_DIR . 'includes/class-lat-woo.php';
require_once LAT_DIR . 'includes/class-lat-settings.php';
require_once LAT_DIR . 'includes/class-lat-participants.php';

register_activation_hook(
	__FILE__,
	function () {
		update_option( 'lat_flush_rewrite', 1 );
	}
);
register_deactivation_hook(
	__FILE__,
	function () {
		LAT_Emails::unschedule();
		flush_rewrite_rules();
	}
);

add_action(
	'plugins_loaded',
	function () {
		LAT_Categories::init();
		LAT_Workshops::init();
		LAT_Registrations::init();
		LAT_Emails::init();
		LAT_Rest::init();
		LAT_Frontend::init();
		LAT_Tickets::init();
		LAT_Woo::init();
		LAT_Settings::init();
		LAT_Participants::init();
	}
);

// Rewrite rules refresh once after activation, an update, or a slug change.
add_action(
	'init',
	function () {
		if ( get_option( 'lat_flush_rewrite' ) || get_option( 'lat_version' ) !== LAT_VERSION ) {
			flush_rewrite_rules();
			delete_option( 'lat_flush_rewrite' );
			update_option( 'lat_version', LAT_VERSION );
		}
	},
	99
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_WS . '&page=lat-settings' ) ) . '">Setări</a>' );
		return $links;
	}
);
