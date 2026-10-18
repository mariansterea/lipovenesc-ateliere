<?php
/**
 * Plugin Name:       Lipovenesc Ateliere
 * Description:       Calendar de ateliere de sezon cu înscriere online: locuri limitate, adulți și copii, preț calculat, listă de așteptare, confirmări și reamintiri pe email, anulare de către participant și lista participanților gata de printat.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Daniela & Marian Sterea
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://lipovenesc.ro/lipovenesc-ateliere
 * Text Domain:       lipovenesc-ateliere
 */

defined( 'ABSPATH' ) || exit;

define( 'LAT_VERSION', '1.0.0' );
define( 'LAT_FILE', __FILE__ );
define( 'LAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'LAT_URL', plugin_dir_url( __FILE__ ) );

require_once LAT_DIR . 'includes/class-lat-helpers.php';
require_once LAT_DIR . 'includes/class-lat-workshops.php';
require_once LAT_DIR . 'includes/class-lat-registrations.php';
require_once LAT_DIR . 'includes/class-lat-emails.php';
require_once LAT_DIR . 'includes/class-lat-rest.php';
require_once LAT_DIR . 'includes/class-lat-frontend.php';
require_once LAT_DIR . 'includes/class-lat-settings.php';
require_once LAT_DIR . 'includes/class-lat-participants.php';

register_deactivation_hook( __FILE__, array( 'LAT_Emails', 'unschedule' ) );

add_action(
	'plugins_loaded',
	function () {
		LAT_Workshops::init();
		LAT_Registrations::init();
		LAT_Emails::init();
		LAT_Rest::init();
		LAT_Frontend::init();
		LAT_Settings::init();
		LAT_Participants::init();
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_WS . '&page=lat-settings' ) ) . '">Setări</a>' );
		return $links;
	}
);
