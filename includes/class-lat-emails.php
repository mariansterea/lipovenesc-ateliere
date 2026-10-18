<?php
/**
 * Emails (confirmation with ticket, payment pending, waitlist, promotion, cancellation,
 * event cancelled, reminders, minimum-participants alert) and the participant cancel page.
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
		add_action( 'lat_registration_event_cancelled', array( __CLASS__, 'on_event_cancelled' ), 10, 2 );
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
		add_action( 'template_redirect', array( __CLASS__, 'cancel_page' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
	}

	public static function schedules( $s ) {
		$s['lat_ten_minutes'] = array(
			'interval' => 600,
			'display'  => 'La 10 minute (Lipovenesc Evenimente)',
		);
		return $s;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
		wp_clear_scheduled_hook( LAT_Registrations::CRON_HOLDS );
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
			'Eveniment' => get_the_title( $r['workshop'] ),
			'Data'      => LAT_Helpers::date_label( $m['date'] ),
			'Ora'       => $m['start'] . ' – ' . $m['end'],
			'Locație'   => $m['location'],
			'Persoane'  => LAT_Helpers::people_label( $r['adults'], $r['children'] ) . ( $r['ages'] ? ' (vârste: ' . $r['ages'] . ')' : '' ),
		);
		if ( '' !== (string) $r['total'] && null !== $r['total'] ) {
			$rows['Total'] = LAT_Helpers::money( (float) $r['total'] ) . ( $r['paid'] ? ' (plătit online)' : ( 'pending' === $r['status'] ? ' (de plătit online)' : ' (plata la fermă)' ) );
		}
		$rows['Cod bilet'] = LAT_Helpers::ticket_code( $r['token'] );
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

	private static function button( $url, $label, $primary = true ) {
		$style = $primary
			? 'display:inline-block;background:' . esc_attr( LAT_Helpers::get( 'accent' ) ) . ';color:#fff;padding:12px 18px;border-radius:6px;text-decoration:none;font-weight:600'
			: 'display:inline-block;color:' . esc_attr( LAT_Helpers::get( 'accent' ) ) . ';padding:10px 0;text-decoration:underline';
		return '<a href="' . esc_url( $url ) . '" style="' . $style . '">' . esc_html( $label ) . '</a>';
	}

	private static function ticket_block( $r ) {
		return '<p style="margin:20px 0">' . self::button( LAT_Helpers::ticket_url( $r['token'] ), 'Vezi biletul (cod QR)' ) . '</p>'
			. '<p>' . self::button( LAT_Tickets::ics_url( $r['token'] ), 'Adaugă în calendar (.ics)', false ) . ' &nbsp; ' . self::button( self::gcal_url( $r ), 'Google Calendar', false ) . '</p>';
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
		return '<div style="font-family:Georgia,\'Times New Roman\',serif;max-width:560px;margin:0 auto;color:#2b2b2b;line-height:1.5">'
			. '<h2 style="margin:0 0 16px;font-weight:600">' . esc_html( $title ) . '</h2>' . $body
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

	private static function notify_admins( $subject, $title, $body, $reply = '' ) {
		foreach ( self::admin_emails() as $to ) {
			self::send( $to, $subject, self::wrap( $title, $body ), $reply );
		}
	}

	private static function admin_body( $r, $lead ) {
		$m = LAT_Helpers::ws_meta( $r['workshop'] );
		return '<p>' . $lead . '</p>' . self::summary( $r )
			. '<p><strong>' . esc_html( $r['name'] ) . '</strong><br>Telefon: ' . esc_html( $r['phone'] ) . '<br>Email: ' . esc_html( $r['email'] ) . '</p>'
			. ( $r['notes'] ? '<p>Observații: ' . nl2br( esc_html( $r['notes'] ) ) . '</p>' : '' )
			. '<p>Locuri ocupate: ' . (int) LAT_Helpers::places_taken( $r['workshop'] ) . ' din ' . (int) $m['capacity'] . ( $m['min'] > 0 ? ' · minim ' . (int) $m['min'] . ', confirmate ' . (int) LAT_Helpers::people_confirmed( $r['workshop'] ) : '' ) . '</p>'
			. '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_WS . '&page=lat-participants&workshop=' . $r['workshop'] ) ) . '">Lista participanților</a></p>';
	}

	/* ---------- Events ---------- */

	/**
	 * Confirmed (reserve or paid) or waitlisted. Pending payments get their own email.
	 */
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
					'<p>Bună, ' . esc_html( $r['name'] ) . '! Evenimentul este momentan complet, dar v-am trecut pe lista de așteptare. Dacă se eliberează locuri, vă anunțăm pe email.</p>' . self::summary( $r ) . self::cancel_line( $r, 'cererea' )
				),
				self::reply_to()
			);
			self::notify_admins( 'Listă de așteptare: ' . $title . ' – ' . $r['name'], 'Înscriere – ' . $title, self::admin_body( $r, 'Înscriere nouă pe <strong>lista de așteptare</strong>:' ), $r['email'] );
			return;
		}
		if ( 'pending' === $r['status'] ) {
			self::on_pending( $id );
			return;
		}
		$m    = LAT_Helpers::ws_meta( $r['workshop'] );
		$lead = $r['paid']
			? '<p>Bună, ' . esc_html( $r['name'] ) . '! Plata a fost primită și biletele sunt confirmate. Vă așteptăm!</p>'
			: '<p>Bună, ' . esc_html( $r['name'] ) . '! Locurile sunt rezervate. Vă așteptăm!</p>';
		if ( $m['min'] > 0 && ! LAT_Helpers::min_reached( $r['workshop'] ) ) {
			$lead .= '<p style="background:#fff4e0;padding:10px 12px;border-radius:8px">Evenimentul are loc dacă se strâng minimum ' . (int) $m['min'] . ' participanți. Dacă nu, vă anunțăm cu cel puțin ' . (int) LAT_Helpers::get( 'min_alert_h' ) . ' de ore înainte' . ( $r['paid'] ? ' și rambursăm integral' : '' ) . '.</p>';
		}
		self::send(
			$r['email'],
			( $r['paid'] ? 'Biletele dumneavoastră: ' : 'Rezervare confirmată: ' ) . $title,
			self::wrap( $r['paid'] ? 'Bilete confirmate' : 'Rezervare confirmată', $lead . self::summary( $r ) . self::ticket_block( $r ) . self::info_block() . self::cancel_line( $r ) ),
			self::reply_to()
		);
		self::notify_admins( ( $r['paid'] ? 'Bilete plătite: ' : 'Rezervare nouă: ' ) . $title . ' – ' . $r['name'], 'Înscriere – ' . $title, self::admin_body( $r, $r['paid'] ? 'Bilete <strong>plătite online</strong>:' : 'Rezervare nouă (plata la fermă):' ), $r['email'] );
	}

	public static function on_pending( $id ) {
		$r = LAT_Registrations::get( $id );
		if ( ! $r || 'pending' !== $r['status'] ) {
			return;
		}
		$pay = '';
		if ( $r['order_id'] && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $r['order_id'] );
			if ( $order ) {
				$pay = $order->get_checkout_payment_url();
			}
		}
		$minutes = max( 1, (int) ceil( ( $r['hold_until'] - time() ) / 60 ) );
		self::send(
			$r['email'],
			'Finalizați plata: ' . get_the_title( $r['workshop'] ),
			self::wrap(
				'Locurile sunt ținute ' . $minutes . ' de minute',
				'<p>Bună, ' . esc_html( $r['name'] ) . '! Am rezervat locurile; biletele se confirmă după plată.</p>' . self::summary( $r )
				. ( $pay ? '<p style="margin:20px 0">' . self::button( $pay, 'Plătește acum' ) . '</p>' : '' )
				. '<p style="font-size:13px;color:#667085">Dacă plata nu se face în acest interval, locurile se eliberează automat.</p>'
			),
			self::reply_to()
		);
	}

	public static function on_promoted( $id ) {
		$r = LAT_Registrations::get( $id );
		if ( ! $r ) {
			return;
		}
		$title = get_the_title( $r['workshop'] );
		if ( 'pending' === $r['status'] ) {
			// Paid event: a hold of 24 h and a payment link.
			if ( ! $r['order_id'] && class_exists( 'LAT_Woo' ) ) {
				LAT_Woo::create_order( $id );
				$r = LAT_Registrations::get( $id );
			}
			$pay = '';
			if ( $r['order_id'] && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $r['order_id'] );
				$pay   = $order ? $order->get_checkout_payment_url() : '';
			}
			self::send(
				$r['email'],
				'S-a eliberat un loc! ' . $title,
				self::wrap( 'Aveți loc la eveniment', '<p>Bună, ' . esc_html( $r['name'] ) . '! S-au eliberat locuri. Le ținem pentru dumneavoastră 24 de ore; biletele se confirmă după plată.</p>' . self::summary( $r ) . ( $pay ? '<p style="margin:20px 0">' . self::button( $pay, 'Plătește acum' ) . '</p>' : '' ) . self::cancel_line( $r ) ),
				self::reply_to()
			);
		} else {
			self::send(
				$r['email'],
				'S-a eliberat un loc! Înscriere confirmată: ' . $title,
				self::wrap( 'Aveți loc la eveniment!', '<p>Bună, ' . esc_html( $r['name'] ) . '! S-au eliberat locuri și înscrierea dumneavoastră de pe lista de așteptare este acum <strong>confirmată</strong>.</p>' . self::summary( $r ) . self::ticket_block( $r ) . self::info_block() . self::cancel_line( $r ) ),
				self::reply_to()
			);
		}
		self::notify_admins( 'Promovat de pe lista de așteptare: ' . $r['name'], 'Loc ocupat de pe lista de așteptare', self::summary( $r ) . '<p>' . esc_html( $r['name'] ) . ', ' . esc_html( $r['phone'] ) . '</p>' );
	}

	public static function on_status( $id, $status, $old ) {
		$r = LAT_Registrations::get( $id );
		if ( ! $r || did_action( 'lat_participant_cancelled' ) ) {
			return;
		}
		if ( 'confirmed' === $status && in_array( $old, array( 'waitlist', 'pending' ), true ) ) {
			if ( 'pending' === $old ) {
				self::on_created( $id );
			} else {
				self::on_promoted( $id );
			}
		} elseif ( 'cancelled' === $status ) {
			self::send(
				$r['email'],
				'Înscriere anulată: ' . get_the_title( $r['workshop'] ),
				self::wrap( 'Înscriere anulată', '<p>Bună, ' . esc_html( $r['name'] ) . '. Înscrierea de mai jos a fost anulată.' . ( $r['paid'] ? ' Dacă ați plătit online, suma se rambursează pe același card în câteva zile.' : '' ) . ' Pentru alte evenimente, ne găsiți oricând pe site.</p>' . self::summary( $r ) ),
				self::reply_to()
			);
		}
	}

	public static function on_event_cancelled( $id, $reason ) {
		$r = LAT_Registrations::get( $id );
		if ( ! $r || ! is_email( $r['email'] ) ) {
			return;
		}
		$title = get_the_title( $r['workshop'] );
		self::send(
			$r['email'],
			'Eveniment anulat: ' . $title,
			self::wrap(
				'Ne pare rău, evenimentul a fost anulat',
				'<p>Bună, ' . esc_html( $r['name'] ) . '. Evenimentul de mai jos nu mai are loc.' . ( $r['paid'] ? ' Suma plătită se rambursează integral pe același card, în câteva zile lucrătoare.' : '' ) . ' Vă așteptăm la următoarele evenimente din curte.</p>' . self::summary( $r ) . self::info_block()
			),
			self::reply_to()
		);
	}

	/* ---------- Cron: reminders + minimum-participants alert ---------- */

	public static function cron() {
		self::reminders();
		self::min_alerts();
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
				self::wrap( 'Ne vedem curând!', '<p>Bună, ' . esc_html( $r['name'] ) . '! Vă reamintim de eveniment:</p>' . self::summary( $r ) . self::ticket_block( $r ) . self::info_block() . self::cancel_line( $r ) ),
				self::reply_to()
			);
		}
	}

	/**
	 * Events under their minimum, starting within min_alert_h hours: one email to the farm.
	 */
	public static function min_alerts() {
		$hours = max( 1, (int) LAT_Helpers::get( 'min_alert_h' ) );
		$now   = LAT_Helpers::now();
		$ws    = get_posts(
			array(
				'post_type'   => LAT_Helpers::CPT_WS,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_lat_start',
						'value'   => array( $now->format( 'Y-m-d H:i' ), $now->modify( '+' . $hours . ' hours' )->format( 'Y-m-d H:i' ) ),
						'compare' => 'BETWEEN',
					),
				),
			)
		);
		foreach ( $ws as $id ) {
			if ( get_post_meta( $id, '_lat_min_alerted', true ) ) {
				continue;
			}
			$m = LAT_Helpers::ws_meta( $id );
			if ( $m['min'] < 1 || ! empty( $m['cancelled'] ) || LAT_Helpers::min_reached( $id ) ) {
				continue;
			}
			update_post_meta( $id, '_lat_min_alerted', 1 );
			$people = LAT_Helpers::people_confirmed( $id );
			self::notify_admins(
				'Sub minim: ' . get_the_title( $id ) . ' (' . $people . '/' . $m['min'] . ')',
				'Evenimentul nu a atins minimul de participanți',
				'<p><strong>' . esc_html( get_the_title( $id ) ) . '</strong>, ' . esc_html( LAT_Helpers::date_label( $m['date'] ) . ' ' . $m['start'] ) . ': ' . (int) $people . ' participanți confirmați din minimum ' . (int) $m['min'] . '.</p>'
				. '<p>Puteți lăsa evenimentul să aibă loc sau îl anulați din pagina lui (participanții primesc email și rambursare automată).</p>'
				. '<p><a href="' . esc_url( admin_url( 'post.php?post=' . $id . '&action=edit' ) ) . '">Deschide evenimentul</a></p>'
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
		if ( in_array( LAT_Helpers::ws_state( $r['workshop'] ), array( 'past', 'cancelled' ), true ) ) {
			self::page( 'Evenimentul nu mai poate fi anulat', '<p>Această înscriere nu mai poate fi modificată online. Sunați-ne dacă e nevoie.</p>' );
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_POST['lat_confirm_cancel'] ) ) {
			do_action( 'lat_participant_cancelled', $r['id'] );
			LAT_Registrations::set_status( $r['id'], 'cancelled' );
			if ( $r['order_id'] && class_exists( 'LAT_Woo' ) && ! $r['paid'] ) {
				LAT_Woo::cancel_order( $r['order_id'] );
			}
			self::notify_admins( 'Înscriere anulată de participant: ' . $r['name'], 'Participantul a anulat', self::summary( $r ) . '<p>' . esc_html( $r['name'] ) . ', ' . esc_html( $r['phone'] ) . '</p>' . ( $r['paid'] ? '<p><strong>Plătise online</strong>: decideți rambursarea din comanda #' . (int) $r['order_id'] . '.</p>' : '' ) . '<p>Locurile au fost eliberate' . ( LAT_Helpers::get( 'waitlist' ) ? ' (și oferite automat celor de pe lista de așteptare, dacă există)' : '' ) . '.</p>' );
			self::send( $r['email'], 'Înscriere anulată: ' . get_the_title( $r['workshop'] ), self::wrap( 'Înscriere anulată', '<p>Înscrierea a fost anulată. Mulțumim că ne-ați anunțat!</p>' . self::summary( $r ) ) );
			self::page( 'Înscriere anulată', '<p>Mulțumim că ne-ați anunțat! Locurile au fost eliberate.' . ( $r['paid'] ? ' Pentru rambursare vă contactăm noi.' : '' ) . '</p>' );
		}
		// phpcs:enable
		self::page(
			'Anulați înscrierea?',
			self::summary( $r ) . '<form method="post"><input type="hidden" name="lat_confirm_cancel" value="1"><p><button type="submit" style="background:#b32d2e;color:#fff;border:0;padding:12px 18px;border-radius:6px;font-size:15px;cursor:pointer">Da, anulează înscrierea</button></p></form>'
		);
	}

	private static function page( $title, $html, $code = 200 ) {
		wp_die(
			'<div style="font-family:Georgia,serif;max-width:520px"><h2>' . esc_html( $title ) . '</h2>' . $html . '</div>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $title ),
			array( 'response' => (int) $code )
		);
	}
}
