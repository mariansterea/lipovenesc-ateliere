<?php
/**
 * Update-uri din GitHub Releases (repo public), fără server propriu.
 *
 * WordPress citește antetul "Update URI: https://github.com/<owner>/<repo>" și
 * cheamă filtrul update_plugins_github.com; noi răspundem cu ultimul release.
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'ABSPATH' ) || exit;

class LAT_Updater {

	const REPO      = 'mariansterea/lipovenesc-ateliere';
	const TRANSIENT = 'lat_github_release';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'plugin_action_links_' . plugin_basename( LAT_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_init', array( __CLASS__, 'force_check' ) );
	}

	/**
	 * Ultimul release din GitHub (cache 6 ore).
	 */
	public static function release( $force = false ) {
		$cached = get_site_transient( self::TRANSIENT );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}
		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'lipovenesc-ateliere/' . LAT_VERSION . '; ' . home_url( '/' ),
				),
			)
		);
		$data = array();
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$json = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $json ) && ! empty( $json['tag_name'] ) ) {
				$package = $json['zipball_url'];
				foreach ( (array) ( isset( $json['assets'] ) ? $json['assets'] : array() ) as $a ) {
					if ( ! empty( $a['browser_download_url'] ) && preg_match( '/\.zip$/i', $a['name'] ) ) {
						$package = $a['browser_download_url'];
						break;
					}
				}
				$data = array(
					'version' => ltrim( (string) $json['tag_name'], 'vV' ),
					'package' => $package,
					'url'     => $json['html_url'],
					'notes'   => isset( $json['body'] ) ? (string) $json['body'] : '',
					'date'    => isset( $json['published_at'] ) ? substr( $json['published_at'], 0, 10 ) : '',
				);
			}
		}
		if ( empty( $data ) ) {
			$data = self::latest_tag();
		}
		if ( empty( $data ) ) {
			$data = self::main_branch();
		}
		set_site_transient( self::TRANSIENT, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Fără release și fără tag: versiunea din antetul fișierului principal de pe ramura main.
	 */
	private static function main_branch() {
		$res = wp_remote_get(
			'https://raw.githubusercontent.com/' . self::REPO . '/main/lipovenesc-ateliere.php',
			array(
				'timeout' => 10,
				'headers' => array( 'User-Agent' => 'lipovenesc-ateliere/' . LAT_VERSION . '; ' . home_url( '/' ) ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return array();
		}
		if ( ! preg_match( '/^[\s\*]*Version:\s*([0-9.]+)/mi', wp_remote_retrieve_body( $res ), $mm ) ) {
			return array();
		}
		$notes = '';
		$rd    = wp_remote_get( 'https://raw.githubusercontent.com/' . self::REPO . '/main/readme.txt', array( 'timeout' => 10 ) );
		if ( ! is_wp_error( $rd ) && 200 === wp_remote_retrieve_response_code( $rd ) ) {
			$txt = wp_remote_retrieve_body( $rd );
			$pos = strpos( $txt, '== Changelog ==' );
			if ( false !== $pos ) {
				$notes = trim( substr( $txt, $pos + 15, 1500 ) );
			}
		}
		return array(
			'version' => $mm[1],
			'package' => 'https://api.github.com/repos/' . self::REPO . '/zipball/main',
			'url'     => 'https://github.com/' . self::REPO,
			'notes'   => $notes,
			'date'    => '',
		);
	}

	/**
	 * Fără release publicat: cel mai nou tag vX.Y.Z din repo (arhiva lui).
	 */
	private static function latest_tag() {
		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/tags?per_page=30',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'lipovenesc-ateliere/' . LAT_VERSION . '; ' . home_url( '/' ),
				),
			)
		);
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return array();
		}
		$best = array();
		foreach ( (array) json_decode( wp_remote_retrieve_body( $res ), true ) as $t ) {
			if ( empty( $t['name'] ) || ! preg_match( '/^v?\d+\.\d+(\.\d+)?$/', $t['name'] ) ) {
				continue;
			}
			$v = ltrim( $t['name'], 'vV' );
			if ( empty( $best ) || version_compare( $v, $best['version'], '>' ) ) {
				$best = array(
					'version' => $v,
					'package' => 'https://api.github.com/repos/' . self::REPO . '/zipball/' . rawurlencode( $t['name'] ),
					'url'     => 'https://github.com/' . self::REPO . '/releases/tag/' . rawurlencode( $t['name'] ),
					'notes'   => '',
					'date'    => '',
				);
			}
		}
		return $best;
	}

	/**
	 * Răspuns pentru verificarea de update-uri a WordPress.
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( LAT_FILE ) !== $plugin_file ) {
			return $update;
		}
		$r = self::release();
		if ( empty( $r['version'] ) || ! version_compare( $r['version'], LAT_VERSION, '>' ) ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => 'lipovenesc-ateliere',
			'plugin'       => $plugin_file,
			'version'      => $r['version'],
			'url'          => $r['url'],
			'package'      => $r['package'],
			'icons'        => array(),
			'banners'      => array(),
			'banners_rtl'  => array(),
			'tested'       => '',
			'requires_php' => '7.4',
			'compatibility' => new stdClass(),
		);
	}

	/**
	 * Fereastra „View version details” din lista de plugin-uri.
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'lipovenesc-ateliere' !== $args->slug ) {
			return $result;
		}
		$r = self::release();
		if ( empty( $r['version'] ) ) {
			return $result;
		}
		$info                = new stdClass();
		$info->name          = 'Lipovenesc Evenimente';
		$info->slug          = 'lipovenesc-ateliere';
		$info->version       = $r['version'];
		$info->author        = 'Daniela & Marian Sterea';
		$info->homepage      = 'https://github.com/' . self::REPO;
		$info->download_link = $r['package'];
		$info->last_updated  = $r['date'];
		$info->requires      = '6.0';
		$info->requires_php  = '7.4';
		$info->sections      = array(
			'description' => 'Evenimente în curte cu înscriere online, bilete și plată prin WooCommerce.',
			'changelog'   => '<pre style="white-space:pre-wrap">' . esc_html( $r['notes'] ) . '</pre>',
		);
		return $info;
	}

	/**
	 * Arhiva de pe GitHub poate avea folderul "mariansterea-lipovenesc-ateliere-abc123"; îl redenumim în "lipovenesc-ateliere".
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['plugin'] ) || plugin_basename( LAT_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . 'lipovenesc-ateliere/';
		if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( $source, $want, true ) ) {
			return $want;
		}
		return $source;
	}

	public static function action_links( $links ) {
		$url     = wp_nonce_url( add_query_arg( 'lat_check_update', '1', admin_url( 'plugins.php' ) ), 'lat_check_update' );
		$links[] = '<a href="' . esc_url( $url ) . '">Verifică update</a>';
		return $links;
	}

	public static function force_check() {
		if ( empty( $_GET['lat_check_update'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'lat_check_update' );
		delete_site_transient( self::TRANSIENT );
		self::release( true );
		delete_site_transient( 'update_plugins' );
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}
}
