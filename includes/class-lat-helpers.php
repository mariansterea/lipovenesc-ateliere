<?php
/**
 * Shared helpers: settings, workshop data, places.
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'ABSPATH' ) || exit;

class LAT_Helpers {

	const OPTION  = 'lat_settings';
	const CPT_WS  = 'lat_workshop';
	const CPT_REG = 'lat_registration';
	const TAX     = 'lat_category';

	const STATUS_LABELS = array(
		'confirmed' => 'Confirmată',
		'waitlist'  => 'Listă de așteptare',
		'cancelled' => 'Anulată',
	);

	public static function defaults() {
		return array(
			'notify_email'  => get_option( 'admin_email' ),
			'contact_phone' => '',
			'gdpr_text'     => 'Sunt de acord ca datele mele să fie folosite pentru gestionarea înscrierii.',
			'info_text'     => "Vă rugăm să ajungeți cu 10 minute înainte.\nRecomandăm haine și încălțăminte potrivite pentru fermă.",
			'reminders'     => 1,
			'waitlist'      => 1,
			'accent'        => '#2f5d50',
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$s = self::settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	public static function now() {
		return current_datetime();
	}

	/* ---------- Workshops ---------- */

	public static function ws_defaults() {
		return array(
			'date'        => '',
			'start'       => '10:00',
			'end'         => '12:00',
			'location'    => 'Ferma Lipovenesc',
			'capacity'    => 20,
			'count'       => 'all',
			'price_adult' => '',
			'price_child' => '',
			'ages'        => '',
			'bring'       => '',
			'deadline'    => 24,
			'max_per'     => 10,
			'open'        => 1,
		);
	}

	public static function ws_meta( $id ) {
		$meta = get_post_meta( $id, '_lat', true );
		return wp_parse_args( is_array( $meta ) ? $meta : array(), self::ws_defaults() );
	}

	/**
	 * Places one registration takes: everyone, or only the children.
	 */
	public static function places_for( $meta, $adults, $children ) {
		return 'children' === $meta['count'] ? (int) $children : (int) $adults + (int) $children;
	}

	public static function places_taken( $id ) {
		$meta  = self::ws_meta( $id );
		$regs  = get_posts(
			array(
				'post_type'   => self::CPT_REG,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_lat_workshop',
						'value' => (int) $id,
					),
					array(
						'key'   => '_lat_status',
						'value' => 'confirmed',
					),
				),
			)
		);
		$taken = 0;
		foreach ( $regs as $rid ) {
			$taken += self::places_for( $meta, get_post_meta( $rid, '_lat_adults', true ), get_post_meta( $rid, '_lat_children', true ) );
		}
		return $taken;
	}

	public static function places_left( $id ) {
		return max( 0, (int) self::ws_meta( $id )['capacity'] - self::places_taken( $id ) );
	}

	public static function waitlist_count( $id ) {
		return count(
			get_posts(
				array(
					'post_type'   => self::CPT_REG,
					'post_status' => 'publish',
					'numberposts' => -1,
					'fields'      => 'ids',
					'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'   => '_lat_workshop',
							'value' => (int) $id,
						),
						array(
							'key'   => '_lat_status',
							'value' => 'waitlist',
						),
					),
				)
			)
		);
	}

	/**
	 * "open" | "full" | "closed" (deadline passed / registrations off) | "past".
	 */
	public static function ws_state( $id ) {
		$m     = self::ws_meta( $id );
		$now   = self::now()->format( 'Y-m-d H:i' );
		$start = $m['date'] . ' ' . $m['start'];
		if ( ! $m['date'] || $start <= $now ) {
			return 'past';
		}
		$cutoff = self::now()->modify( '+' . (int) $m['deadline'] . ' hours' )->format( 'Y-m-d H:i' );
		if ( empty( $m['open'] ) || $start <= $cutoff ) {
			return 'closed';
		}
		return self::places_left( $id ) > 0 ? 'open' : 'full';
	}

	public static function price_total( $meta, $adults, $children ) {
		$pa = self::num( $meta['price_adult'] );
		$pc = '' === trim( (string) $meta['price_child'] ) ? $pa : self::num( $meta['price_child'] );
		if ( null === $pa && null === $pc ) {
			return null;
		}
		return (float) $pa * (int) $adults + (float) $pc * (int) $children;
	}

	public static function num( $v ) {
		$v = str_replace( ',', '.', trim( (string) $v ) );
		return is_numeric( $v ) ? (float) $v : null;
	}

	public static function money( $v ) {
		if ( null === $v ) {
			return '';
		}
		return ( floor( $v ) == $v ? number_format( $v, 0, ',', '.' ) : number_format( $v, 2, ',', '.' ) ) . ' lei'; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
	}

	public static function price_label( $meta ) {
		$pa = trim( (string) $meta['price_adult'] );
		$pc = trim( (string) $meta['price_child'] );
		if ( '' === $pa && '' === $pc ) {
			return 'Gratuit';
		}
		$fmt = function ( $p ) {
			return is_numeric( str_replace( ',', '.', $p ) ) ? self::money( self::num( $p ) ) : $p;
		};
		if ( '' === $pc || $pc === $pa ) {
			return $fmt( $pa ) . ' / persoană';
		}
		if ( '' === $pa || '0' === $pa ) {
			return $fmt( $pc ) . ' / copil · adulți gratuit';
		}
		return 'Adult ' . $fmt( $pa ) . ' · Copil ' . $fmt( $pc );
	}

	/**
	 * Upcoming published workshops, soonest first.
	 */
	public static function upcoming_ids( $limit = -1 ) {
		return get_posts(
			array(
				'post_type'   => self::CPT_WS,
				'post_status' => 'publish',
				'numberposts' => $limit,
				'fields'      => 'ids',
				'meta_key'    => '_lat_start', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'     => 'meta_value',
				'order'       => 'ASC',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_lat_start',
						'value'   => self::now()->format( 'Y-m-d H:i' ),
						'compare' => '>',
					),
				),
			)
		);
	}

	public static function date_label( $ymd, $with_year = true ) {
		$ts = strtotime( $ymd . ' 12:00:00' );
		if ( ! $ts ) {
			return $ymd;
		}
		$days = array( 'duminică', 'luni', 'marți', 'miercuri', 'joi', 'vineri', 'sâmbătă' );
		$day  = $days[ (int) gmdate( 'w', $ts ) ];
		return mb_strtoupper( mb_substr( $day, 0, 1 ) ) . mb_substr( $day, 1 ) . ', ' . (int) gmdate( 'j', $ts ) . ' ' . self::month( (int) gmdate( 'n', $ts ) ) . ( $with_year ? ' ' . gmdate( 'Y', $ts ) : '' );
	}

	public static function month( $n ) {
		$months = array( '', 'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie' );
		return isset( $months[ $n ] ) ? $months[ $n ] : '';
	}

	public static function people_label( $adults, $children ) {
		$a = (int) $adults;
		$c = (int) $children;
		return $a . ( 1 === $a ? ' adult' : ' adulți' ) . ', ' . $c . ( 1 === $c ? ' copil' : ' copii' );
	}

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
