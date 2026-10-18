<?php
/**
 * Tickets: the participant's ticket page (QR code, print / save as PDF, calendar file,
 * pay / cancel links), the .ics download, the check-in link opened by scanning the QR,
 * and the admin check-in screen.
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'ABSPATH' ) || exit;

class LAT_Tickets {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'router' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_lat_checkin_toggle', array( __CLASS__, 'toggle' ) );
	}

	public static function checkin_url( $token ) {
		return add_query_arg( 'lat_checkin', $token, home_url( '/' ) );
	}

	public static function ics_url( $token ) {
		return add_query_arg( 'lat_ics', $token, home_url( '/' ) );
	}

	/* ---------- Public routes (token = authorisation) ---------- */

	public static function router() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- secret tokens.
		if ( ! empty( $_GET['lat_bilet'] ) ) {
			self::ticket_page( sanitize_text_field( wp_unslash( $_GET['lat_bilet'] ) ) );
		}
		if ( ! empty( $_GET['lat_ics'] ) ) {
			self::ics( sanitize_text_field( wp_unslash( $_GET['lat_ics'] ) ) );
		}
		if ( ! empty( $_GET['lat_checkin'] ) ) {
			self::checkin_page( sanitize_text_field( wp_unslash( $_GET['lat_checkin'] ) ) );
		}
		// phpcs:enable
	}

	private static function not_found() {
		status_header( 404 );
		nocache_headers();
		self::shell( 'Biletul nu a fost găsit', '<p>Linkul nu este valid sau înscrierea a fost ștearsă. Pentru ajutor, sunați-ne.</p>' );
	}

	public static function ticket_page( $token ) {
		$r = LAT_Registrations::find_by_token( $token );
		if ( ! $r ) {
			self::not_found();
		}
		nocache_headers();
		$m      = LAT_Helpers::ws_meta( $r['workshop'] );
		$state  = LAT_Helpers::ws_state( $r['workshop'] );
		$title  = get_the_title( $r['workshop'] );
		$code   = LAT_Helpers::ticket_code( $r['token'] );
		$status = $r['status'];
		$image  = get_the_post_thumbnail_url( $r['workshop'], 'large' );
		$color  = $m['cat_color'];

		$badge = array(
			'confirmed' => array( 'Bilet valabil', '#1a7f37' ),
			'pending'   => array( 'Așteaptă plata', '#b26a00' ),
			'waitlist'  => array( 'Listă de așteptare', '#6b7280' ),
			'cancelled' => array( 'Anulat', '#b32d2e' ),
		);
		$b     = isset( $badge[ $status ] ) ? $badge[ $status ] : array( $status, '#6b7280' );

		$rows = array(
			array( 'Când', LAT_Helpers::date_label( $m['date'] ) . ', ' . $m['start'] . '–' . $m['end'] ),
			array( 'Unde', $m['location'] ),
			array( 'Participanți', LAT_Helpers::people_label( $r['adults'], $r['children'] ) . ( $r['ages'] ? ' (vârste: ' . $r['ages'] . ')' : '' ) ),
			array( 'Nume', $r['name'] ),
		);
		if ( '' !== (string) $r['total'] && null !== $r['total'] ) {
			$rows[] = array( 'Total', LAT_Helpers::money( (float) $r['total'] ) . ( $r['paid'] ? ' · plătit online' : ( 'pending' === $status ? ' · de plătit' : ' · plata la fermă' ) ) );
		}

		$body = '<div class="t-card">';
		if ( $image ) {
			$body .= '<img class="t-img" src="' . esc_url( $image ) . '" alt="">';
		}
		$body .= '<div class="t-head" style="--c:' . esc_attr( $color ) . '"><span class="t-cat">' . esc_html( $m['cat_name'] ? $m['cat_name'] : 'Eveniment' ) . '</span><h1>' . esc_html( $title ) . '</h1><span class="t-badge" style="background:' . esc_attr( $b[1] ) . '">' . esc_html( $b[0] ) . '</span></div>';
		$body .= '<div class="t-grid"><dl>';
		foreach ( $rows as $row ) {
			$body .= '<dt>' . esc_html( $row[0] ) . '</dt><dd>' . esc_html( $row[1] ) . '</dd>';
		}
		$body .= '</dl><div class="t-qr"><div id="qr" data-text="' . esc_attr( self::checkin_url( $r['token'] ) ) . '"></div><div class="t-code">' . esc_html( $code ) . '</div></div></div>';

		if ( 'pending' === $status && $r['order_id'] && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $r['order_id'] );
			if ( $order && $order->needs_payment() ) {
				$left = max( 0, (int) $r['hold_until'] - time() );
				$body .= '<div class="t-note t-note--warn">Locurile sunt ținute încă ' . (int) ceil( $left / 60 ) . ' minute. <a class="t-btn" href="' . esc_url( $order->get_checkout_payment_url() ) . '">Plătește acum</a></div>';
			}
		}
		if ( 'cancelled' === $state && 'cancelled' === $status ) {
			$body .= '<div class="t-note t-note--warn">Evenimentul a fost anulat de organizator. Dacă ați plătit online, suma se rambursează pe același card.</div>';
		}
		$note = trim( (string) LAT_Helpers::get( 'ticket_note' ) );
		if ( $note && 'confirmed' === $status ) {
			$body .= '<p class="t-note">' . esc_html( $note ) . '</p>';
		}
		if ( $m['bring'] ) {
			$body .= '<p class="t-note"><strong>Ce să aduceți:</strong> ' . esc_html( $m['bring'] ) . '</p>';
		}
		$body .= '<div class="t-actions no-print">';
		if ( in_array( $status, array( 'confirmed', 'pending' ), true ) && ! in_array( $state, array( 'past', 'cancelled' ), true ) ) {
			$body .= '<a class="t-btn t-btn--ghost" href="' . esc_url( self::ics_url( $r['token'] ) ) . '">Adaugă în calendar</a>';
			$body .= '<button class="t-btn t-btn--ghost" onclick="window.print()">Printează / salvează PDF</button>';
			$body .= '<a class="t-btn t-btn--ghost" href="' . esc_url( LAT_Emails::cancel_url( $r ) ) . '">Nu mai pot ajunge</a>';
		}
		$url = LAT_Helpers::ws_url( $r['workshop'] );
		if ( $url ) {
			$body .= '<a class="t-btn t-btn--ghost" href="' . esc_url( $url ) . '">Pagina evenimentului</a>';
		}
		$body .= '</div></div>';
		$phone = trim( (string) LAT_Helpers::get( 'contact_phone' ) );
		if ( $phone ) {
			$body .= '<p class="t-foot">Întrebări? <a href="tel:' . esc_attr( preg_replace( '/\s+/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a></p>';
		}
		self::shell( 'Bilet: ' . $title, $body, true );
	}

	public static function ics( $token ) {
		$r = LAT_Registrations::find_by_token( $token );
		if ( ! $r ) {
			self::not_found();
		}
		$m   = LAT_Helpers::ws_meta( $r['workshop'] );
		$tz  = wp_timezone();
		$utc = new DateTimeZone( 'UTC' );
		$s   = ( new DateTimeImmutable( $m['date'] . ' ' . $m['start'], $tz ) )->setTimezone( $utc );
		$e   = ( new DateTimeImmutable( $m['date'] . ' ' . $m['end'], $tz ) )->setTimezone( $utc );
		$esc = function ( $v ) {
			return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\;', '\,', '\n' ), (string) $v );
		};
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Ferma Lipovenesc//Evenimente//RO',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:lat-' . $r['id'] . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . $s->format( 'Ymd\THis\Z' ),
			'DTEND:' . $e->format( 'Ymd\THis\Z' ),
			'SUMMARY:' . $esc( get_the_title( $r['workshop'] ) ),
			'LOCATION:' . $esc( $m['location'] ),
			'DESCRIPTION:' . $esc( LAT_Helpers::people_label( $r['adults'], $r['children'] ) . '. Bilet: ' . LAT_Helpers::ticket_url( $r['token'] ) ),
			'URL:' . $esc( LAT_Helpers::ticket_url( $r['token'] ) ),
			'END:VEVENT',
			'END:VCALENDAR',
		);
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="eveniment-' . (int) $r['workshop'] . '.ics"' );
		echo implode( "\r\n", $lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per iCalendar rules above.
		exit;
	}

	/**
	 * Scanned by staff: marks presence. Needs a logged-in editor; otherwise shows the login.
	 */
	public static function checkin_page( $token ) {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::checkin_url( $token ) ) );
			exit;
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			self::shell( 'Fără acces', '<p>Contul dumneavoastră nu poate face check-in.</p>' );
		}
		$r = LAT_Registrations::find_by_token( $token );
		if ( ! $r ) {
			self::not_found();
		}
		nocache_headers();
		$m     = LAT_Helpers::ws_meta( $r['workshop'] );
		$title = get_the_title( $r['workshop'] );
		$ok    = 'confirmed' === $r['status'];
		$msg   = '';
		if ( $ok && ! $r['checkin'] ) {
			LAT_Registrations::toggle_checkin( $r['id'], true );
			$msg = 'Prezență înregistrată acum.';
		} elseif ( $ok && $r['checkin'] ) {
			$msg = 'Atenție: biletul a mai fost scanat la ' . wp_date( 'H:i, j M', $r['checkin'] ) . '.';
		} else {
			$msg = 'Biletul NU este valabil: ' . ( isset( LAT_Helpers::STATUS_LABELS[ $r['status'] ] ) ? LAT_Helpers::STATUS_LABELS[ $r['status'] ] : $r['status'] ) . '.';
		}
		$bg   = $ok && ! $r['checkin'] ? '#1a7f37' : ( $ok ? '#b26a00' : '#b32d2e' );
		$body = '<div class="c-flash" style="background:' . esc_attr( $bg ) . '"><strong>' . esc_html( $msg ) . '</strong></div>'
			. '<div class="t-card"><h1>' . esc_html( $r['name'] ) . '</h1><p class="c-big">' . esc_html( LAT_Helpers::people_label( $r['adults'], $r['children'] ) ) . '</p>'
			. '<dl><dt>Eveniment</dt><dd>' . esc_html( $title . ' · ' . LAT_Helpers::date_label( $m['date'], false ) . ' ' . $m['start'] ) . '</dd>'
			. '<dt>Cod</dt><dd>' . esc_html( LAT_Helpers::ticket_code( $r['token'] ) ) . '</dd>'
			. '<dt>Plată</dt><dd>' . esc_html( '' !== (string) $r['total'] && null !== $r['total'] ? LAT_Helpers::money( (float) $r['total'] ) . ( $r['paid'] ? ' · plătit online' : ' · de încasat la fermă' ) : 'gratuit' ) . '</dd>'
			. ( $r['notes'] ? '<dt>Observații</dt><dd>' . esc_html( $r['notes'] ) . '</dd>' : '' ) . '</dl>'
			. '<div class="t-actions"><a class="t-btn" href="' . esc_url( admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_WS . '&page=lat-checkin&workshop=' . (int) $r['workshop'] ) ) . '">Lista de check-in</a>'
			. '<a class="t-btn t-btn--ghost" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lat_checkin_toggle&id=' . (int) $r['id'] . '&back=scan' ), 'lat_checkin_' . (int) $r['id'] ) ) . '">' . ( $r['checkin'] || $ok ? 'Anulează prezența' : 'Marchează oricum prezent' ) . '</a></div></div>';
		self::shell( 'Check-in', $body );
	}

	/* ---------- Admin check-in list ---------- */

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . LAT_Helpers::CPT_WS, 'Check-in', 'Check-in', 'edit_posts', 'lat-checkin', array( __CLASS__, 'render_admin' ) );
	}

	public static function toggle() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'lat_checkin_' . $id );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'Nu ai drepturi pentru această acțiune.' );
		}
		LAT_Registrations::toggle_checkin( $id );
		$r = LAT_Registrations::get( $id );
		if ( isset( $_GET['back'] ) && 'scan' === $_GET['back'] && $r ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_WS . '&page=lat-checkin&workshop=' . (int) $r['workshop'] ) );
		} else {
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_WS . '&page=lat-checkin' ) );
		}
		exit;
	}

	public static function render_admin() {
		$upcoming = LAT_Helpers::upcoming_ids( 1 );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view.
		$ws_id = isset( $_GET['workshop'] ) ? absint( $_GET['workshop'] ) : ( $upcoming ? $upcoming[0] : 0 );
		$q     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		// phpcs:enable
		$events = get_posts(
			array(
				'post_type'   => LAT_Helpers::CPT_WS,
				'post_status' => 'publish',
				'numberposts' => 40,
				'meta_key'    => '_lat_start', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'     => 'meta_value',
				'order'       => 'DESC',
			)
		);
		echo '<div class="wrap lat-checkin"><h1>Check-in</h1>';
		echo '<form method="get" class="lat-checkin-bar"><input type="hidden" name="post_type" value="' . esc_attr( LAT_Helpers::CPT_WS ) . '"><input type="hidden" name="page" value="lat-checkin">';
		echo '<select name="workshop" onchange="this.form.submit()">';
		foreach ( $events as $e ) {
			$em = LAT_Helpers::ws_raw( $e->ID );
			printf( '<option value="%d" %s>%s</option>', (int) $e->ID, selected( $ws_id, $e->ID, false ), esc_html( get_the_title( $e ) . ( $em['date'] ? ' — ' . LAT_Helpers::date_label( $em['date'], false ) : '' ) ) );
		}
		echo '</select> <input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="Nume sau cod bilet (ex. AB12-CD34)"> <button class="button">Caută</button></form>';
		echo '<p class="description">Scanați codul QR de pe bilet cu camera telefonului (logat în site): prezența se bifează automat. Sau bifați manual mai jos.</p>';
		if ( ! $ws_id ) {
			echo '</div>';
			return;
		}
		$ids = get_posts(
			array(
				'post_type'   => LAT_Helpers::CPT_REG,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
				'orderby'     => 'title',
				'order'       => 'ASC',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_lat_workshop',
						'value' => (int) $ws_id,
					),
					array(
						'key'     => '_lat_status',
						'value'   => array( 'confirmed', 'pending' ),
						'compare' => 'IN',
					),
				),
			)
		);
		$present  = 0;
		$people   = 0;
		$rows     = array();
		$needle   = mb_strtolower( str_replace( '-', '', $q ) );
		foreach ( $ids as $id ) {
			$r = LAT_Registrations::get( $id );
			if ( $needle && false === mb_strpos( mb_strtolower( $r['name'] . ' ' . str_replace( '-', '', LAT_Helpers::ticket_code( $r['token'] ) ) ), $needle ) ) {
				continue;
			}
			$rows[]  = $r;
			$people += $r['adults'] + $r['children'];
			if ( $r['checkin'] ) {
				$present += $r['adults'] + $r['children'];
			}
		}
		printf( '<h2>%s · %d persoane așteptate · <span style="color:#1a7f37">%d prezente</span></h2>', esc_html( get_the_title( $ws_id ) ), (int) $people, (int) $present );
		echo '<table class="widefat striped lat-checkin-table"><thead><tr><th>Participant</th><th>Persoane</th><th>Cod</th><th>Plată</th><th>Prezență</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=lat_checkin_toggle&id=' . (int) $r['id'] ), 'lat_checkin_' . (int) $r['id'] );
			printf(
				'<tr class="%s"><td><strong>%s</strong><br><span class="lat-muted">%s</span></td><td>%s</td><td><code>%s</code></td><td>%s</td><td><a class="button %s" href="%s">%s</a></td></tr>',
				$r['checkin'] ? 'is-present' : '',
				esc_html( $r['name'] ),
				esc_html( $r['phone'] ),
				esc_html( LAT_Helpers::people_label( $r['adults'], $r['children'] ) ),
				esc_html( LAT_Helpers::ticket_code( $r['token'] ) ),
				esc_html( 'pending' === $r['status'] ? 'așteaptă plata' : ( $r['paid'] ? 'plătit online' : ( '' !== (string) $r['total'] && (float) $r['total'] > 0 ? 'de încasat ' . LAT_Helpers::money( (float) $r['total'] ) : 'gratuit' ) ) ),
				$r['checkin'] ? 'button-secondary' : 'button-primary',
				esc_url( $url ),
				$r['checkin'] ? 'Prezent ✓ (anulează)' : 'Marchează prezent'
			);
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="5">Nicio înscriere.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/* ---------- Minimal standalone page ---------- */

	private static function shell( $title, $body, $with_qr = false ) {
		$accent = sanitize_hex_color( (string) LAT_Helpers::get( 'accent' ) );
		$paper  = sanitize_hex_color( (string) LAT_Helpers::get( 'paper' ) );
		$ink    = sanitize_hex_color( (string) LAT_Helpers::get( 'ink' ) );
		$logo   = get_custom_logo();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title . ' · ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?></title>
<style>
:root{--c:<?php echo esc_html( $accent ? $accent : '#4f6246' ); ?>;--paper:<?php echo esc_html( $paper ? $paper : '#f3efe6' ); ?>;--ink:<?php echo esc_html( $ink ? $ink : '#2b2b2b' ); ?>}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:16px/1.5 Georgia,'Times New Roman',serif}
.wrap{max-width:560px;margin:0 auto;padding:20px 16px 40px}.logo{text-align:center;margin:8px 0 18px}.logo img{max-height:56px;width:auto}
.t-card{background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.08)}.t-img{display:block;width:100%;height:200px;object-fit:cover}
.t-head{padding:20px 22px 8px;border-top:6px solid var(--c)}.t-cat{display:inline-block;font:600 11px/1 system-ui,sans-serif;letter-spacing:.14em;text-transform:uppercase;color:var(--c)}
h1{font-size:26px;line-height:1.2;margin:8px 0 10px;font-weight:600}.t-badge{display:inline-block;color:#fff;font:600 12px/1 system-ui,sans-serif;letter-spacing:.06em;text-transform:uppercase;padding:7px 10px;border-radius:999px}
.t-grid{display:grid;grid-template-columns:1fr 150px;gap:16px;padding:12px 22px 18px}dl{margin:0}dt{font:600 11px/1 system-ui,sans-serif;letter-spacing:.12em;text-transform:uppercase;color:#7a7a72;margin-top:12px}dd{margin:3px 0 0;font-size:17px}
.t-qr{text-align:center;align-self:start}.t-qr svg,.t-qr img,.t-qr table{width:140px;height:140px}.t-code{font:700 15px/1.2 ui-monospace,Menlo,monospace;letter-spacing:.12em;margin-top:8px}
.t-note{margin:0 22px 14px;padding:12px 14px;background:var(--paper);border-radius:10px;font-size:15px}.t-note--warn{background:#fff4e0}
.t-actions{display:flex;flex-wrap:wrap;gap:10px;padding:4px 22px 22px}.t-btn{display:inline-block;background:var(--c);color:#fff;text-decoration:none;font:600 13px/1 system-ui,sans-serif;letter-spacing:.08em;text-transform:uppercase;padding:13px 18px;border-radius:6px;border:0;cursor:pointer}
.t-btn--ghost{background:transparent;color:var(--c);border:1.5px solid var(--c)}.t-foot{text-align:center;margin-top:18px;font-size:14px;color:#6b6b63}.t-foot a{color:inherit}
.c-flash{color:#fff;padding:18px 20px;border-radius:14px;margin-bottom:14px;font-size:18px}.c-big{font-size:22px;margin:0 0 8px}.t-card>h1,.t-card>dl,.t-card>.c-big{padding-left:22px;padding-right:22px}.t-card>h1{padding-top:20px}
@media(max-width:480px){.t-grid{grid-template-columns:1fr}.t-qr{order:-1}h1{font-size:22px}}
@media print{body{background:#fff}.no-print,.logo{display:none}.t-card{box-shadow:none;border:1px solid #ddd}.t-img{height:120px}}
</style>
</head>
<body><div class="wrap">
<?php if ( $logo ) : ?><div class="logo"><?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div><?php endif; ?>
<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
</div>
<?php if ( $with_qr ) : ?>
<script src="<?php echo esc_url( LAT_URL . 'assets/vendor/qrcode.js?v=' . LAT_VERSION ); ?>"></script>
<script>(function(){var el=document.getElementById('qr');if(!el||typeof qrcode!=='function')return;var q=qrcode(0,'M');q.addData(el.getAttribute('data-text'));q.make();el.innerHTML=q.createSvgTag({cellSize:4,margin:0,scalable:true});})();</script>
<?php endif; ?>
</body></html>
		<?php
		exit;
	}
}
