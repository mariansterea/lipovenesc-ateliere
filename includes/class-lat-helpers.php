<?php
/**
 * Shared helpers: settings, event data (with category inheritance), places, states, formatting.
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
		'pending'   => 'Așteaptă plata',
		'waitlist'  => 'Listă de așteptare',
		'cancelled' => 'Anulată',
	);

	const MODES = array(
		'reserve' => 'Rezervă (plata la fermă sau gratuit)',
		'buy'     => 'Cumpără bilet (plată online prin WooCommerce)',
	);

	/* ---------- Settings ---------- */

	public static function defaults() {
		return array(
			'notify_email'  => get_option( 'admin_email' ),
			'contact_phone' => '',
			'gdpr_text'     => 'Sunt de acord ca datele mele să fie folosite pentru gestionarea înscrierii.',
			'info_text'     => "Vă rugăm să ajungeți cu 10 minute înainte.\nRecomandăm haine și încălțăminte potrivite pentru fermă.",
			'reminders'     => 1,
			'waitlist'      => 1,
			'accent'        => '#4f6246',
			'paper'         => '#f3efe6',
			'ink'           => '#2b2b2b',
			'font_head'     => '',
			'hold_minutes'  => 30,
			'min_alert_h'   => 48,
			'ticket_note'   => 'Prezentați biletul (pe telefon sau printat) la intrarea în curte.',
			'woo'           => 1,
			'slug'          => 'eveniment',
			'list_page'     => 0,
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

	public static function woo_active() {
		return self::get( 'woo' ) && class_exists( 'WooCommerce' ) && function_exists( 'wc_create_order' );
	}

	/* ---------- Categories (term meta) ---------- */

	public static function cat_defaults() {
		return array(
			'intro'       => '',
			'color'       => '#4f6246',
			'image_id'    => 0,
			'min'         => 0,
			'capacity'    => 20,
			'count'       => 'all',
			'price_adult' => '',
			'price_child' => '',
			'mode'        => 'reserve',
			'ages'        => '',
			'cta'         => '',
			'order'       => 0,
		);
	}

	public static function cat_meta( $term_id ) {
		$meta = get_term_meta( (int) $term_id, '_lat_cat', true );
		return wp_parse_args( is_array( $meta ) ? $meta : array(), self::cat_defaults() );
	}

	/**
	 * All categories, in the order set in the admin (then by name).
	 */
	public static function categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		usort(
			$terms,
			function ( $a, $b ) {
				$oa = (int) self::cat_meta( $a->term_id )['order'];
				$ob = (int) self::cat_meta( $b->term_id )['order'];
				return $oa === $ob ? strcmp( $a->name, $b->name ) : $oa - $ob;
			}
		);
		return $terms;
	}

	/**
	 * The event's main category (first assigned, or null).
	 */
	public static function ws_category( $id ) {
		$terms = get_the_terms( $id, self::TAX );
		if ( ! is_array( $terms ) || ! $terms ) {
			return null;
		}
		return reset( $terms );
	}

	/* ---------- Events ---------- */

	/**
	 * Raw event meta. Empty string / 'inherit' means "take it from the category".
	 */
	public static function ws_defaults() {
		return array(
			'date'        => '',
			'start'       => '10:00',
			'end'         => '12:00',
			'location'    => 'Ferma Lipovenesc, Jurilovca',
			'capacity'    => '',
			'min'         => '',
			'count'       => 'inherit',
			'price_adult' => '',
			'price_child' => '',
			'inherit_price' => 1,
			'mode'        => 'inherit',
			'ages'        => '',
			'bring'       => '',
			'deadline'    => 24,
			'max_per'     => 10,
			'open'        => 1,
			'cancelled'   => 0,
			'product_id'  => 0,
			'cta'         => '',
		);
	}

	public static function ws_raw( $id ) {
		$meta = get_post_meta( $id, '_lat', true );
		return wp_parse_args( is_array( $meta ) ? $meta : array(), self::ws_defaults() );
	}

	/**
	 * Effective event meta: raw values with category defaults filled in.
	 * Adds: cat (term|null), cat_color, cat_name, cat_slug, mode_eff.
	 */
	public static function ws_meta( $id ) {
		$m    = self::ws_raw( $id );
		$term = self::ws_category( $id );
		$c    = $term ? self::cat_meta( $term->term_id ) : self::cat_defaults();

		if ( '' === (string) $m['capacity'] ) {
			$m['capacity'] = (int) $c['capacity'];
		}
		if ( '' === (string) $m['min'] ) {
			$m['min'] = (int) $c['min'];
		}
		if ( 'inherit' === $m['count'] ) {
			$m['count'] = $c['count'];
		}
		if ( ! empty( $m['inherit_price'] ) ) {
			$m['price_adult'] = $c['price_adult'];
			$m['price_child'] = $c['price_child'];
		}
		if ( 'inherit' === $m['mode'] ) {
			$m['mode'] = $c['mode'];
		}
		if ( '' === (string) $m['ages'] ) {
			$m['ages'] = $c['ages'];
		}
		if ( '' === (string) $m['cta'] ) {
			$m['cta'] = $c['cta'];
		}
		$m['capacity'] = max( 1, (int) $m['capacity'] );
		$m['min']      = max( 0, (int) $m['min'] );
		$m['cat']      = $term;
		$m['cat_name'] = $term ? $term->name : '';
		$m['cat_slug'] = $term ? $term->slug : '';
		$m['cat_color'] = $c['color'];
		$m['mode_eff'] = self::effective_mode( $m );
		return $m;
	}

	/**
	 * "buy" only when WooCommerce is usable and there is something to pay.
	 */
	public static function effective_mode( $m ) {
		if ( 'buy' === $m['mode'] && self::woo_active() && self::has_price( $m ) ) {
			return 'buy';
		}
		return 'reserve';
	}

	public static function has_price( $m ) {
		$pa = self::num( $m['price_adult'] );
		$pc = self::num( $m['price_child'] );
		return ( null !== $pa && $pa > 0 ) || ( null !== $pc && $pc > 0 );
	}

	/**
	 * Places one registration takes: everyone, or only the children.
	 */
	public static function places_for( $meta, $adults, $children ) {
		return 'children' === $meta['count'] ? (int) $children : (int) $adults + (int) $children;
	}

	private static function reg_ids( $ws_id, $statuses ) {
		return get_posts(
			array(
				'post_type'   => self::CPT_REG,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_lat_workshop',
						'value' => (int) $ws_id,
					),
					array(
						'key'     => '_lat_status',
						'value'   => (array) $statuses,
						'compare' => 'IN',
					),
				),
			)
		);
	}

	/**
	 * Confirmed places plus pending (unpaid, still on hold) places.
	 */
	public static function places_taken( $id ) {
		$meta  = self::ws_meta( $id );
		$now   = time();
		$taken = 0;
		foreach ( self::reg_ids( $id, array( 'confirmed', 'pending' ) ) as $rid ) {
			if ( 'pending' === get_post_meta( $rid, '_lat_status', true ) ) {
				$hold = (int) get_post_meta( $rid, '_lat_hold_until', true );
				if ( $hold && $hold < $now ) {
					continue; // Expired hold: released by cron, ignored here.
				}
			}
			$taken += self::places_for( $meta, get_post_meta( $rid, '_lat_adults', true ), get_post_meta( $rid, '_lat_children', true ) );
		}
		return $taken;
	}

	/**
	 * Confirmed people (adults + children), for the minimum-participants rule.
	 */
	public static function people_confirmed( $id ) {
		$n = 0;
		foreach ( self::reg_ids( $id, 'confirmed' ) as $rid ) {
			$n += (int) get_post_meta( $rid, '_lat_adults', true ) + (int) get_post_meta( $rid, '_lat_children', true );
		}
		return $n;
	}

	public static function min_reached( $id ) {
		$m = self::ws_meta( $id );
		return $m['min'] < 1 || self::people_confirmed( $id ) >= $m['min'];
	}

	public static function places_left( $id ) {
		return max( 0, (int) self::ws_meta( $id )['capacity'] - self::places_taken( $id ) );
	}

	public static function waitlist_count( $id ) {
		return count( self::reg_ids( $id, 'waitlist' ) );
	}

	/**
	 * "open" | "full" | "closed" (deadline passed / registrations off) | "past" | "cancelled".
	 */
	public static function ws_state( $id ) {
		$m = self::ws_meta( $id );
		if ( ! empty( $m['cancelled'] ) ) {
			return 'cancelled';
		}
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
		if ( ( '' === $pa || '0' === $pa ) && ( '' === $pc || '0' === $pc ) ) {
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
		if ( '0' === $pc ) {
			return $fmt( $pa ) . ' / adult · copii gratuit';
		}
		return 'Adult ' . $fmt( $pa ) . ' · Copil ' . $fmt( $pc );
	}

	/**
	 * Upcoming published events, soonest first. $args: category (slug), limit.
	 */
	public static function upcoming_ids( $limit = -1, $category = '' ) {
		$q = array(
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
		);
		if ( $category ) {
			$q['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => self::TAX,
					'field'    => 'slug',
					'terms'    => array_map( 'sanitize_title', array_map( 'trim', explode( ',', $category ) ) ),
				),
			);
		}
		return get_posts( $q );
	}

	/**
	 * Past events (archive), newest first.
	 */
	public static function past_ids( $limit = 12, $category = '' ) {
		$q = array(
			'post_type'   => self::CPT_WS,
			'post_status' => 'publish',
			'numberposts' => $limit,
			'fields'      => 'ids',
			'meta_key'    => '_lat_start', // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'     => 'meta_value',
			'order'       => 'DESC',
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => '_lat_start',
					'value'   => self::now()->format( 'Y-m-d H:i' ),
					'compare' => '<=',
				),
			),
		);
		if ( $category ) {
			$q['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => self::TAX,
					'field'    => 'slug',
					'terms'    => sanitize_title( $category ),
				),
			);
		}
		return get_posts( $q );
	}

	/* ---------- Formatting ---------- */

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

	public static function month_short( $n ) {
		$months = array( '', 'ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'noi', 'dec' );
		return isset( $months[ $n ] ) ? $months[ $n ] : '';
	}

	public static function people_label( $adults, $children ) {
		$a = (int) $adults;
		$c = (int) $children;
		if ( $c < 1 ) {
			return $a . ( 1 === $a ? ' adult' : ' adulți' );
		}
		if ( $a < 1 ) {
			return $c . ( 1 === $c ? ' copil' : ' copii' );
		}
		return $a . ( 1 === $a ? ' adult' : ' adulți' ) . ', ' . $c . ( 1 === $c ? ' copil' : ' copii' );
	}

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Public URL of an event (single page) or '' when the page is off.
	 */
	public static function ws_url( $id ) {
		$url = get_permalink( $id );
		return $url ? $url : '';
	}

	/**
	 * Ticket / manage URL for a registration (secret token = authorisation).
	 */
	public static function ticket_url( $token ) {
		return add_query_arg( 'lat_bilet', $token, home_url( '/' ) );
	}

	/**
	 * Short human code shown on tickets (first 8 chars of the token, grouped).
	 */
	public static function ticket_code( $token ) {
		$t = strtoupper( substr( (string) $token, 0, 8 ) );
		return substr( $t, 0, 4 ) . '-' . substr( $t, 4, 4 );
	}

	/**
	 * Hex colour → "r,g,b" for CSS color-mix fallbacks.
	 */
	public static function hex_rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return '79,98,70';
		}
		return hexdec( substr( $hex, 0, 2 ) ) . ',' . hexdec( substr( $hex, 2, 2 ) ) . ',' . hexdec( substr( $hex, 4, 2 ) );
	}
}
