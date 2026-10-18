<?php
/**
 * Emails (confirmation, waitlist, promotion, cancellation, reminders) and the participant cancel page.
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'ABSPATH' ) || exit;

class LAT_Emails {

	const CRON = 'lat_send_reminders';

	public static function init() {
		add_action( 'lat_registration_created', array( __CLASS__, 'on_created' ) );
		add_action( 'lat_registration_status', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( 'lat_registration_promoted', array( __CLASS__, 'on_promoted' ) );
		add_action( self::CRON, array( __CLASS__, 'reminders' ) );
		add_action( 'template_redirect', array( __CLASS__, 'cancel_page' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/* ---------- Building blocks ---------- */

	public static function cancel_url( $r ) {
		return add_query_arg( 'lat_cancel', $r['token'], home_url( '/' ) );
	}

	private static function gcal_url( $r ) {
		$m   = LAT_Helpers::ws_meta( $r['workshop'] );
		$tz  = wp_timezone();
		$utc = new DateTimeZone( 'UTC' );
		$s   = ( new DateTimeImmutable( $m['date'] . ' ' . $m['start'], $tz ) )->setTimezone( $utc );
		$e   = ( new DateTimeImmutable( $m['date'] . ' ' . $m['end'], $tz ) )->setTimezone( $utc );
		return add_query_arg(
			array(
				'action'   => 'TEMPLATE',
				'text'     => rawurlencode( get_the_title( $r['workshop'] ) ),
				'dates'    => $s->format( 'Ymd\THis\Z' ) . '/' . $e->format( 'Ymd\THis\Z' ),
				'location' => rawurlencode( $m['location'] ),
			),
			'https://calendar.google.com/calendar/render'
		);
	}

	private static function summary( $r ) {
		$m    = LAT_Helpers::ws_meta( $r['workshop'] );
		$rows = array(
			'Atelier'  => get_the_title( $r['workshop'] ),
			'Data'     => LAT_Helpers::date_label( $m['date'] ),
			'Ora'      => $m['start'] . ' – ' . $m['end'],
			'Locație'  => $m['location'],
			'Persoane' => LAT_Helpers::people_label( $r['adults'], $r['children'] ) . ( $r['ages'] ? ' (vârste: ' . $r['ages'] . ')' : '' ),
		);
		if ( '' !== (string) $r['total'] ) {
			$rows['Total'] = LAT_Helpers::money( (float) $r['total'] ) . ' (plata la fața locului)';
		}
		$html = '<table cellpadding="6" style="border-collapse:collapse;font-size:15px">';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><td style="color:#667085;padding-right:16px;vertical-align:top">' . esc_html( $label ) . '</td><td><strong>' . esc_html( $value ) . '</strong></td></tr>';
		}
		$html .= '</table>';
		if ( $m['bring'] ) {
			$html .= '<p><strong>Ce să aduceți:</strong> ' . nl2br( esc_html( $m['bring'] ) ) . '</p>';
		}
		return $html;
	}

	private static function info_block() {
		$info  = trim( (string) LAT_Helpers::get( 'info_text' ) );
		$phone = trim( (string) LAT_Helpers::get( 'contact_phone' ) );
		$html  = $info ? '<p style="background:#f4f6f8;padding:12px 14px;border-radius:8px">' . nl2br( esc_html( $info ) ) . '</p>' : '';
		if ( $phone ) {
			$html .= '<p>Pentru orice întrebare: <a href="tel:' . esc_attr( preg_replace( '/\s+/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a></p>';
		}
		return $html;
	}

	private static function cancel_line( $r, $what = 'înscrierea' ) {
		return '<p style="font-size:13px;color:#667085">Nu mai puteți ajunge? <a href="' . esc_url( self::cancel_url( $r ) ) . '">Anulați ' . esc_html( $what ) . '</a>, ca să eliberați locul pentru altcineva.</p>';
	}

	private static function wrap( $title, $body ) {
		return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#1f2328;line-height:1.5">'
			. '<h2 style="margin:0 0 16px">' . esc_html( $title ) . '</h2>' . $body
			. '<p style="margin-top:28px;color:#667085;font-size:13px">' . esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '</p></div>';
	}

	private static function admin_emails() {
		return array_filter( array_map( 'trim', explode( ',', (string) LAT_Helpers::get( 'notify_email' ) ) ), 'is_email' );
	}

	private static function send( $to, $subject, $html, $reply_to = '' ) {
		if ( ! is_email( $to ) ) {
			return false;
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		return wp_mail( $to, $subject, $html, $headers );
	}

	private static function reply_to() {
		$admins = self::admin_emails();
		return $admins ? reset( $admins ) : '';
	}

	/* ---------- Events ---------- */

	public static function on_created( $id ) {
		$r = LAT_Registrations::get( $id );
		if ( ! $r ) {
			return;
		}
		$title = get_the_title( $r['workshop'] );
		if ( 'waitlist' === $r['status'] ) {
			self::send(
				$r['email'],
				'Listă de așteptare: ' . $title,
				self::wrap(
					'Sunteți pe lista de așteptare',
					'<p>Bună, ' . esc_html( $r['name'] ) . '! Atelierul este momentan complet, dar v-am trecut pe lista de așteptare. Dacă se eliberează locuri, vă confirmăm automat și primiți un email.</p>' . self::summary( $r ) . self::cancel_line( $r, 'cererea' )
				),
				self::reply_to()
			);
		} else {
			self::send(
				$r['email'],
				'Înscriere confirmată: ' . $title,
				self::wrap(
					'Înscriere confirmată',
					'<p>Bună, ' . esc_html( $r['name'] ) . '! Vă așteptăm la atelier.</p>' . self::summary( $r ) . self::info_block()
					. '<p><a href="' . esc_url( self::gcal_url( $r ) ) . '" style="display:inline-block;background:#1f2328;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">Adaugă în calendar</a></p>'
					. self::cancel_line( $r )
				),
				self::reply_to()
			);
		}

		$m     = LAT_Helpers::ws_meta( $r['workshop'] );
		$admin = '<p>' . ( 'waitlist' === $r['status'] ? 'Înscriere nouă pe <strong>lista de așteptare</strong>:' : 'Înscriere nouă:' ) . '</p>' . self::summary( $r )
			. '<p><strong>' . esc_html( $r['name'] ) . '</strong><br>Telefon: ' . esc_html( $r['phone'] ) . '<br>Email: ' . esc_html( $r['email'] ) . '</p>'
			. ( $r['notes'] ? '<p>Observații: ' . nl2br( esc_html( $r['notes'] ) ) . '</p>' : '' )
			. '<p>Locuri ocupate: ' . (int) LAT_Helpers::places_taken( $r['workshop'] ) . ' din ' . (int) $m['capacity'] . '</p>'
			. '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_WS . '&page=lat-participants&workshop=' . $r['workshop'] ) ) . '">Lista participanților</a></p>';
		foreach ( self::admin_emails() as $to ) {
			self::send( $to, ( 'waitlist' === $r['status'] ? 'Listă de așteptare: ' : 'Înscriere nouă: ' ) . $title . ' – ' . $r['name'], self::wrap( 'Înscriere – ' . $title, $admin ), $r['email'] );
		}
	}

	public static function on_promoted( $id ) {
		$r = LAT_Registrations::get( $id );
		if ( ! $r ) {
			return;
		}
		self::send(
			$r['email'],
			'S-a eliberat un loc! Înscriere confirmată: ' . get_the_title( $r['workshop'] ),
			self::wrap(
				'Aveți loc la atelier!',
				'<p>Bună, ' . esc_html( $r['name'] ) . '! S-au eliberat locuri și înscrierea dumneavoastră de pe lista de așteptare este acum <strong>confirmată</strong>.</p>' . self::summary( $r ) . self::info_block()
				. '<p><a href="' . esc_url( self::gcal_url( $r ) ) . '">Adaugă în calendar</a></p>' . self::cancel_line( $r )
			),
			self::reply_to()
		);
		foreach ( self::admin_emails() as $to ) {
			self::send( $to, 'Confirmat automat de pe lista de așteptare: ' . $r['name'], self::wrap( 'Loc ocupat de pe lista de așteptare', self::summary( $r ) . '<p>' . esc_html( $r['name'] ) . ', ' . esc_html( $r['phone'] ) . '</p>' ) );
		}
	}

	public static function on_status( $id, $status, $old ) {
		$r = LAT_Registrations::get( $id );
		if ( ! $r || did_action( 'lat_participant_cancelled' ) ) {
			return;
		}
		if ( 'confirmed' === $status && 'waitlist' === $old ) {
			self::on_promoted( $id );
		} elseif ( 'cancelled' === $status ) {
			self::send(
				$r['email'],
				'Înscriere anulată: ' . get_the_title( $r['workshop'] ),
				self::wrap( 'Înscriere anulată', '<p>Bună, ' . esc_html( $r['name'] ) . '. Înscrierea de mai jos a fost anulată. Pentru alte ateliere, ne găsiți oricând pe site.</p>' . self::summary( $r ) ),
				self::reply_to()
			);
		}
	}

	public static function reminders() {
		if ( ! LAT_Helpers::get( 'reminders' ) ) {
			return;
		}
		$now = LAT_Helpers::now();
		$ws  = get_posts(
			array(
				'post_type'   => LAT_Helpers::CPT_WS,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_lat_start',
						'value'   => array( $now->format( 'Y-m-d H:i' ), $now->modify( '+24 hours' )->format( 'Y-m-d H:i' ) ),
						'compare' => 'BETWEEN',
					),
				),
			)
		);
		if ( ! $ws ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'   => LAT_Helpers::CPT_REG,
				'post_status' => 'publish',
				'numberposts' => 200,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_lat_workshop',
						'value'   => array_map( 'intval', $ws ),
						'compare' => 'IN',
					),
					array(
						'key'   => '_lat_status',
						'value' => 'confirmed',
					),
					array(
						'key'   => '_lat_reminded',
						'value' => '0',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$r = LAT_Registrations::get( $id );
			update_post_meta( $id, '_lat_reminded', 1 );
			$m = LAT_Helpers::ws_meta( $r['workshop'] );
			self::send(
				$r['email'],
				'Reamintire: ' . get_the_title( $r['workshop'] ) . ', ' . LAT_Helpers::date_label( $m['date'], false ) . ' la ' . $m['start'],
				self::wrap( 'Ne vedem curând!', '<p>Bună, ' . esc_html( $r['name'] ) . '! Vă reamintim de atelier:</p>' . self::summary( $r ) . self::info_block() . self::cancel_line( $r ) ),
				self::reply_to()
			);
		}
	}

	/* ---------- Participant cancel page ---------- */

	public static function cancel_page() {
		// phpcs:disable WordPress.Security.NonceVerification -- the secret token is the authorisation.
		if ( empty( $_GET['lat_cancel'] ) ) {
			return;
		}
		$r = LAT_Registrations::find_by_token( sanitize_text_field( wp_unslash( $_GET['lat_cancel'] ) ) );
		if ( ! $r ) {
			self::page( 'Linkul nu este valid', '<p>Înscrierea nu a fost găsită. Pentru ajutor, contactați-ne direct.</p>', 404 );
		}
		if ( 'cancelled' === $r['status'] ) {
			self::page( 'Înscriere anulată', '<p>Această înscriere este deja anulată.</p>' );
		}
		if ( 'past' === LAT_Helpers::ws_state( $r['workshop'] ) ) {
			self::page( 'Atelierul a avut loc', '<p>Această înscriere nu mai poate fi anulată.</p>' );
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_POST['lat_confirm_cancel'] ) ) {
			do_action( 'lat_participant_cancelled', $r['id'] );
			LAT_Registrations::set_status( $r['id'], 'cancelled' );
			foreach ( self::admin_emails() as $to ) {
				self::send( $to, 'Înscriere anulată de participant: ' . $r['name'], self::wrap( 'Participantul a anulat', self::summary( $r ) . '<p>' . esc_html( $r['name'] ) . ', ' . esc_html( $r['phone'] ) . '</p><p>Locurile au fost eliberate' . ( LAT_Helpers::get( 'waitlist' ) ? ' (și oferite automat celor de pe lista de așteptare, dacă există)' : '' ) . '.</p>' ) );
			}
			self::send( $r['email'], 'Înscriere anulată: ' . get_the_title( $r['workshop'] ), self::wrap( 'Înscriere anulată', '<p>Înscrierea a fost anulată. Mulțumim că ne-ați anunțat!</p>' . self::summary( $r ) ) );
			self::page( 'Înscriere anulată', '<p>Mulțumim că ne-ați anunțat! Locurile au fost eliberate.</p>' );
		}
		// phpcs:enable
		self::page(
			'Anulați înscrierea?',
			self::summary( $r ) . '<form method="post"><input type="hidden" name="lat_confirm_cancel" value="1"><p><button type="submit" style="background:#b32d2e;color:#fff;border:0;padding:12px 18px;border-radius:6px;font-size:15px;cursor:pointer">Da, anulează înscrierea</button></p></form>'
		);
	}

	/**
	 * Minimal standalone page. $html is built only from escaped values.
	 */
	private static function page( $title, $html, $code = 200 ) {
		wp_die(
			'<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px"><h2>' . esc_html( $title ) . '</h2>' . $html . '</div>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $title ),
			array( 'response' => (int) $code )
		);
	}
}
