<?php
/**
 * Printable participant list per workshop, with CSV export.
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'ABSPATH' ) || exit;

class LAT_Participants {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_lat_csv', array( __CLASS__, 'csv' ) );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . LAT_Helpers::CPT_WS, 'Lista participanților', 'Participanți', 'edit_posts', 'lat-participants', array( __CLASS__, 'render' ) );
	}

	private static function registrations( $ws_id ) {
		$ids = get_posts(
			array(
				'post_type'   => LAT_Helpers::CPT_REG,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
				'orderby'     => 'date ID',
				'order'       => 'ASC',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_lat_workshop',
						'value' => (int) $ws_id,
					),
				),
			)
		);
		$out = array(
			'confirmed' => array(),
			'pending'   => array(),
			'waitlist'  => array(),
			'cancelled' => array(),
		);
		foreach ( $ids as $id ) {
			$r = LAT_Registrations::get( $id );
			if ( isset( $out[ $r['status'] ] ) ) {
				$out[ $r['status'] ][] = $r;
			}
		}
		return $out;
	}

	public static function render() {
		$workshops = get_posts(
			array(
				'post_type'   => LAT_Helpers::CPT_WS,
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => -1,
				'meta_key'    => '_lat_start', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'     => 'meta_value',
				'order'       => 'ASC',
			)
		);
		if ( ! $workshops ) {
			echo '<div class="wrap"><h1>Lista participanților</h1><p>Nu există încă ateliere.</p></div>';
			return;
		}
		// Default: the next upcoming workshop, else the latest one.
		$upcoming = LAT_Helpers::upcoming_ids( 1 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
		$ws_id = isset( $_GET['workshop'] ) ? absint( $_GET['workshop'] ) : ( $upcoming ? $upcoming[0] : end( $workshops )->ID );
		$m     = LAT_Helpers::ws_meta( $ws_id );
		$regs  = self::registrations( $ws_id );

		$adults   = 0;
		$children = 0;
		$total    = 0.0;
		foreach ( $regs['confirmed'] as $r ) {
			$adults   += $r['adults'];
			$children += $r['children'];
			$total    += (float) $r['total'];
		}
		?>
		<div class="wrap lat-participants">
			<h1>Lista participanților</h1>
			<form method="get" class="lat-noprint">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( LAT_Helpers::CPT_WS ); ?>">
				<input type="hidden" name="page" value="lat-participants">
				<select name="workshop" onchange="this.form.submit()">
					<?php foreach ( $workshops as $w ) : ?>
						<?php $wm = LAT_Helpers::ws_raw( $w->ID ); ?>
						<option value="<?php echo (int) $w->ID; ?>" <?php selected( $ws_id, $w->ID ); ?>><?php echo esc_html( ( $wm['date'] ? wp_date( 'j M Y', strtotime( $wm['date'] . ' 12:00' ) ) . ' — ' : '' ) . get_the_title( $w ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button" onclick="window.print()">Printează</button>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lat_csv&workshop=' . $ws_id ), 'lat_csv_' . $ws_id ) ); ?>">Export Excel (CSV)</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . LAT_Helpers::CPT_REG . '&lat_ws=' . $ws_id ) ); ?>">+ Adaugă participant</a>
			</form>

			<h2><?php echo esc_html( get_the_title( $ws_id ) ); ?></h2>
			<p><?php echo esc_html( ( $m['date'] ? LAT_Helpers::date_label( $m['date'] ) . ', ' . $m['start'] . '–' . $m['end'] : 'Fără dată' ) . ' · ' . $m['location'] ); ?></p>
			<p class="lat-summary">
				<strong><?php echo (int) LAT_Helpers::places_taken( $ws_id ); ?> / <?php echo (int) $m['capacity']; ?></strong> locuri ocupate ·
				<?php echo (int) $adults; ?> adulți · <?php echo (int) $children; ?> copii
				<?php if ( $total > 0 ) : ?>
					· total: <strong><?php echo esc_html( LAT_Helpers::money( $total ) ); ?></strong>
				<?php endif; ?>
			</p>

			<?php self::table( $regs['confirmed'], true ); ?>

			<?php if ( $regs['pending'] ) : ?>
				<h3>Așteaptă plata online (<?php echo count( $regs['pending'] ); ?>)</h3>
				<?php self::table( $regs['pending'], false ); ?>
			<?php endif; ?>
			<?php if ( $regs['waitlist'] ) : ?>
				<h3>Listă de așteptare (<?php echo count( $regs['waitlist'] ); ?>)</h3>
				<?php self::table( $regs['waitlist'], false ); ?>
			<?php endif; ?>
			<?php if ( $regs['cancelled'] ) : ?>
				<p class="lat-muted lat-noprint"><?php echo count( $regs['cancelled'] ); ?> înscrieri anulate (nu apar în listă).</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function table( $rows, $numbered ) {
		if ( ! $rows ) {
			echo '<p>Nicio înscriere confirmată încă.</p>';
			return;
		}
		?>
		<table class="widefat striped lat-table">
			<thead>
				<tr>
					<th>#</th><th>Nume</th><th>Telefon</th><th>Adulți</th><th>Copii</th><th>Vârste</th><th>Total</th><th>Cod</th><th>Observații</th><th>Prezent</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $i => $r ) : ?>
				<tr>
					<td><?php echo (int) $i + 1; ?></td>
					<td><a href="<?php echo esc_url( get_edit_post_link( $r['id'] ) ); ?>"><?php echo esc_html( $r['name'] ); ?></a></td>
					<td><?php echo esc_html( $r['phone'] ); ?></td>
					<td><?php echo (int) $r['adults']; ?></td>
					<td><?php echo (int) $r['children']; ?></td>
					<td><?php echo esc_html( $r['ages'] ); ?></td>
					<td><?php echo '' !== (string) $r['total'] && null !== $r['total'] ? esc_html( LAT_Helpers::money( (float) $r['total'] ) . ( $r['paid'] ? ' ✓' : '' ) ) : ''; ?></td>
					<td><code><?php echo esc_html( LAT_Helpers::ticket_code( $r['token'] ) ); ?></code></td>
					<td><?php echo esc_html( $r['notes'] ); ?></td>
					<td><?php echo $r['checkin'] ? '✓ ' . esc_html( wp_date( 'H:i', $r['checkin'] ) ) : '☐'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function csv() {
		$ws_id = isset( $_GET['workshop'] ) ? absint( $_GET['workshop'] ) : 0;
		check_admin_referer( 'lat_csv_' . $ws_id );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'Nu ai drepturi pentru această acțiune.' );
		}
		$m    = LAT_Helpers::ws_meta( $ws_id );
		$regs = self::registrations( $ws_id );
		$file = sanitize_file_name( 'participanti-' . ( $m['date'] ? $m['date'] . '-' : '' ) . get_post_field( 'post_name', $ws_id ) . '.csv' );

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM so Excel reads diacritics.
		fputcsv( $out, array( 'Status', 'Nume', 'Telefon', 'Email', 'Adulți', 'Copii', 'Vârste', 'Total (lei)', 'Plătit online', 'Cod bilet', 'Prezent', 'Observații', 'Înscris la' ), ';' );
		foreach ( array( 'confirmed', 'pending', 'waitlist', 'cancelled' ) as $status ) {
			foreach ( $regs[ $status ] as $r ) {
				fputcsv(
					$out,
					array(
						LAT_Helpers::STATUS_LABELS[ $status ],
						$r['name'],
						$r['phone'],
						$r['email'],
						$r['adults'],
						$r['children'],
						$r['ages'],
						'' !== (string) $r['total'] && null !== $r['total'] ? str_replace( '.', ',', (string) (float) $r['total'] ) : '',
						$r['paid'] ? 'da' : '',
						LAT_Helpers::ticket_code( $r['token'] ),
						$r['checkin'] ? 'da' : '',
						$r['notes'],
						get_the_date( 'Y-m-d H:i', $r['id'] ),
					),
					';'
				);
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
