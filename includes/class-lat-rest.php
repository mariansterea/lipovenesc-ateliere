<?php
/**
 * REST endpoints for the sign-up form.
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'ABSPATH' ) || exit;

class LAT_Rest {

	const NS = 'lat/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'register' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/places',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'places' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Live places for all upcoming events (the page itself may be cached).
	 */
	public static function places( WP_REST_Request $request ) {
		$out = array();
		$ids = $request->get_param( 'ids' );
		$ids = $ids ? array_filter( array_map( 'absint', explode( ',', (string) $ids ) ) ) : LAT_Helpers::upcoming_ids();
		foreach ( $ids as $id ) {
			if ( LAT_Helpers::CPT_WS !== get_post_type( $id ) ) {
				continue;
			}
			$out[ $id ] = array(
				'left'  => LAT_Helpers::places_left( $id ),
				'state' => LAT_Helpers::ws_state( $id ),
				'text'  => LAT_Frontend::places_text( LAT_Helpers::ws_state( $id ), LAT_Helpers::places_left( $id ) ),
			);
		}
		$response = rest_ensure_response( $out );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function rate_limited() {
		$key = 'lat_rl_' . md5( LAT_Helpers::client_ip() );
		$n   = (int) get_transient( $key );
		if ( $n >= 10 ) {
			return true;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return false;
	}

	public static function register( WP_REST_Request $request ) {
		$in = $request->get_json_params();
		if ( ! is_array( $in ) ) {
			$in = $request->get_body_params();
		}
		if ( ! empty( $in['website'] ) ) {
			return new WP_Error( 'lat_spam', 'Cererea nu a putut fi trimisă.', array( 'status' => 400 ) );
		}
		if ( self::rate_limited() ) {
			return new WP_Error( 'lat_limit', 'Prea multe încercări. Încercați din nou mai târziu sau sunați-ne.', array( 'status' => 429 ) );
		}
		$clean = array();
		foreach ( array( 'workshop', 'adults', 'children', 'ages', 'name', 'phone', 'email', 'notes', 'consent', 'waitlist' ) as $f ) {
			$clean[ $f ] = isset( $in[ $f ] ) && is_scalar( $in[ $f ] ) ? (string) $in[ $f ] : '';
		}

		$result = LAT_Registrations::create( $clean );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$r = LAT_Registrations::get( $result['id'] );
		$m = LAT_Helpers::ws_meta( $r['workshop'] );
		return rest_ensure_response(
			array(
				'ok'      => true,
				'status'  => $r['status'],
				'left'    => LAT_Helpers::places_left( $r['workshop'] ),
				'pay_url' => isset( $result['pay_url'] ) ? $result['pay_url'] : '',
				'ticket'  => in_array( $r['status'], array( 'confirmed' ), true ) ? LAT_Helpers::ticket_url( $r['token'] ) : '',
				'summary' => array(
					'title'  => get_the_title( $r['workshop'] ),
					'when'   => LAT_Helpers::date_label( $m['date'] ) . ', ' . $m['start'] . '–' . $m['end'],
					'people' => LAT_Helpers::people_label( $r['adults'], $r['children'] ),
					'total'  => '' !== (string) $r['total'] && null !== $r['total'] ? LAT_Helpers::money( (float) $r['total'] ) : '',
				),
			)
		);
	}
}
