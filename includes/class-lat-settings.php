<?php
/**
 * Settings page.
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'ABSPATH' ) || exit;

class LAT_Settings {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'update_option_' . LAT_Helpers::OPTION, array( __CLASS__, 'flush' ), 10, 2 );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . LAT_Helpers::CPT_WS, 'Setări evenimente', 'Setări', 'manage_options', 'lat-settings', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting(
			'lat_settings_group',
			LAT_Helpers::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function flush( $old, $new ) {
		if ( ! is_array( $old ) || ! isset( $old['slug'] ) || $old['slug'] !== $new['slug'] ) {
			update_option( 'lat_flush_rewrite', 1 );
		}
	}

	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$d   = LAT_Helpers::defaults();
		$hex = function ( $k ) use ( $in, $d ) {
			$c = sanitize_hex_color( isset( $in[ $k ] ) ? $in[ $k ] : '' );
			return $c ? $c : $d[ $k ];
		};
		$slug = sanitize_title( isset( $in['slug'] ) ? $in['slug'] : '' );
		return array(
			'notify_email'  => implode( ', ', array_filter( array_map( 'sanitize_email', explode( ',', isset( $in['notify_email'] ) ? (string) $in['notify_email'] : '' ) ) ) ),
			'contact_phone' => sanitize_text_field( isset( $in['contact_phone'] ) ? $in['contact_phone'] : '' ),
			'gdpr_text'     => wp_kses_post( isset( $in['gdpr_text'] ) ? $in['gdpr_text'] : '' ),
			'info_text'     => sanitize_textarea_field( isset( $in['info_text'] ) ? $in['info_text'] : '' ),
			'ticket_note'   => sanitize_textarea_field( isset( $in['ticket_note'] ) ? $in['ticket_note'] : '' ),
			'reminders'     => empty( $in['reminders'] ) ? 0 : 1,
			'waitlist'      => empty( $in['waitlist'] ) ? 0 : 1,
			'woo'           => empty( $in['woo'] ) ? 0 : 1,
			'accent'        => $hex( 'accent' ),
			'paper'         => $hex( 'paper' ),
			'ink'           => $hex( 'ink' ),
			'font_head'     => sanitize_text_field( isset( $in['font_head'] ) ? $in['font_head'] : '' ),
			'hold_minutes'  => max( 5, absint( isset( $in['hold_minutes'] ) ? $in['hold_minutes'] : $d['hold_minutes'] ) ),
			'min_alert_h'   => max( 1, absint( isset( $in['min_alert_h'] ) ? $in['min_alert_h'] : $d['min_alert_h'] ) ),
			'slug'          => $slug ? $slug : $d['slug'],
			'list_page'     => absint( isset( $in['list_page'] ) ? $in['list_page'] : 0 ),
		);
	}

	private static function name( $key ) {
		return LAT_Helpers::OPTION . '[' . $key . ']';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = LAT_Helpers::settings();
		?>
		<div class="wrap">
			<h1>Setări evenimente</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'lat_settings_group' ); ?>
				<h2>Contact și emailuri</h2>
				<table class="form-table">
					<tr>
						<th><label for="lat-notify">Notificări înscrieri</label></th>
						<td><input type="text" id="lat-notify" class="regular-text" name="<?php echo esc_attr( self::name( 'notify_email' ) ); ?>" value="<?php echo esc_attr( $s['notify_email'] ); ?>">
						<p class="description">Una sau mai multe adrese, separate prin virgulă. Primesc fiecare înscriere, anulările și alertele „sub minim”.</p></td>
					</tr>
					<tr>
						<th><label for="lat-phone">Telefon de contact</label></th>
						<td><input type="text" id="lat-phone" class="regular-text" name="<?php echo esc_attr( self::name( 'contact_phone' ) ); ?>" value="<?php echo esc_attr( $s['contact_phone'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="lat-info">Informații utile</label></th>
						<td><textarea id="lat-info" rows="3" class="large-text" name="<?php echo esc_attr( self::name( 'info_text' ) ); ?>"><?php echo esc_textarea( $s['info_text'] ); ?></textarea>
						<p class="description">Apar în emailul de confirmare și în reamintire (acces, parcare, ținută).</p></td>
					</tr>
					<tr>
						<th><label for="lat-ticket">Notă pe bilet</label></th>
						<td><textarea id="lat-ticket" rows="2" class="large-text" name="<?php echo esc_attr( self::name( 'ticket_note' ) ); ?>"><?php echo esc_textarea( $s['ticket_note'] ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="lat-gdpr">Text acord GDPR</label></th>
						<td><textarea id="lat-gdpr" rows="2" class="large-text" name="<?php echo esc_attr( self::name( 'gdpr_text' ) ); ?>"><?php echo esc_textarea( $s['gdpr_text'] ); ?></textarea>
						<p class="description">Poți pune link: &lt;a href="/politica-de-confidentialitate"&gt;politica de confidențialitate&lt;/a&gt;</p></td>
					</tr>
				</table>

				<h2>Reguli</h2>
				<table class="form-table">
					<tr>
						<th>Opțiuni</th>
						<td>
							<label><input type="hidden" name="<?php echo esc_attr( self::name( 'waitlist' ) ); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr( self::name( 'waitlist' ) ); ?>" value="1" <?php checked( $s['waitlist'] ); ?>> Listă de așteptare când evenimentul e complet (locurile eliberate se oferă automat, în ordinea înscrierii)</label><br><br>
							<label><input type="hidden" name="<?php echo esc_attr( self::name( 'reminders' ) ); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr( self::name( 'reminders' ) ); ?>" value="1" <?php checked( $s['reminders'] ); ?>> Email de reamintire cu o zi înainte (cu biletul)</label><br><br>
							<label><input type="hidden" name="<?php echo esc_attr( self::name( 'woo' ) ); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr( self::name( 'woo' ) ); ?>" value="1" <?php checked( $s['woo'] ); ?>> Plată online prin WooCommerce pentru evenimentele „Cumpără bilet”<?php echo class_exists( 'WooCommerce' ) ? '' : ' <em>(WooCommerce nu este activ)</em>'; ?></label>
						</td>
					</tr>
					<tr>
						<th><label for="lat-hold">Locuri ținute până la plată</label></th>
						<td><input type="number" id="lat-hold" min="5" class="small-text" name="<?php echo esc_attr( self::name( 'hold_minutes' ) ); ?>" value="<?php echo (int) $s['hold_minutes']; ?>"> minute
						<p class="description">Dacă plata nu se face în acest interval, locurile se eliberează automat.</p></td>
					</tr>
					<tr>
						<th><label for="lat-minh">Alertă „sub minim”</label></th>
						<td>cu <input type="number" id="lat-minh" min="1" class="small-text" name="<?php echo esc_attr( self::name( 'min_alert_h' ) ); ?>" value="<?php echo (int) $s['min_alert_h']; ?>"> ore înainte
						<p class="description">Primiți un email dacă un eveniment nu a atins minimul de participanți. Anularea rămâne decizia voastră (un click din pagina evenimentului).</p></td>
					</tr>
				</table>

				<h2>Aspect și pagini</h2>
				<table class="form-table">
					<tr>
						<th>Culori</th>
						<td>
							Accent <input type="color" name="<?php echo esc_attr( self::name( 'accent' ) ); ?>" value="<?php echo esc_attr( $s['accent'] ); ?>"> &nbsp;
							Fundal <input type="color" name="<?php echo esc_attr( self::name( 'paper' ) ); ?>" value="<?php echo esc_attr( $s['paper'] ); ?>"> &nbsp;
							Text <input type="color" name="<?php echo esc_attr( self::name( 'ink' ) ); ?>" value="<?php echo esc_attr( $s['ink'] ); ?>">
							<p class="description">Implicit: verde închis, crem, antracit, ca pe homepage. Fiecare categorie are în plus culoarea ei.</p>
						</td>
					</tr>
					<tr>
						<th><label for="lat-font">Font titluri (opțional)</label></th>
						<td><input type="text" id="lat-font" class="regular-text" name="<?php echo esc_attr( self::name( 'font_head' ) ); ?>" value="<?php echo esc_attr( $s['font_head'] ); ?>" placeholder="'Cormorant Garamond', Georgia, serif">
						<p class="description">Familia de font deja încărcată de temă. Gol = fontul serif implicit.</p></td>
					</tr>
					<tr>
						<th><label for="lat-slug">Adresa paginilor de eveniment</label></th>
						<td><code><?php echo esc_html( home_url( '/' ) ); ?></code><input type="text" id="lat-slug" class="regular-text" style="width:160px" name="<?php echo esc_attr( self::name( 'slug' ) ); ?>" value="<?php echo esc_attr( $s['slug'] ); ?>"><code>/numele-evenimentului/</code></td>
					</tr>
					<tr>
						<th><label for="lat-list">Pagina cu lista evenimentelor</label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => self::name( 'list_page' ),
									'id'                => 'lat-list',
									'selected'          => (int) $s['list_page'],
									'show_option_none'  => '— alegeți —',
									'option_none_value' => 0,
								)
							);
							?>
							<p class="description">Pagina în care ați pus <code>[lipovenesc_evenimente]</code>. Cardurile de categorie trimit acolo, filtrate.</p>
						</td>
					</tr>
				</table>

				<h2>Shortcode-uri</h2>
				<table class="widefat striped" style="max-width:900px">
					<tbody>
						<tr><td><code>[lipovenesc_categorii]</code></td><td>Cele trei (sau câte aveți) carduri mari de categorie, cu imagine și buton.</td></tr>
						<tr><td><code>[lipovenesc_evenimente]</code></td><td>Lista completă cu calendar și filtre. Opțiuni: <code>categorie="ateliere"</code>, <code>calendar="nu"</code>, <code>filtre="nu"</code>, <code>limit="6"</code>, <code>titlu="..."</code>.</td></tr>
						<tr><td><code>[lipovenesc_evenimente layout="urmatoarele" limit="3" link="/evenimente/"]</code></td><td>Următoarele evenimente, compact, pentru homepage sau sidebar.</td></tr>
						<tr><td><code>[lipovenesc_evenimente layout="arhiva"]</code></td><td>Evenimentele trecute.</td></tr>
						<tr><td><code>[lipovenesc_eveniment id="123"]</code></td><td>Un singur eveniment, cu formular, într-un articol de blog sau o pagină.</td></tr>
					</tbody>
				</table>
				<?php submit_button( 'Salvează setările' ); ?>
			</form>
		</div>
		<?php
	}
}
