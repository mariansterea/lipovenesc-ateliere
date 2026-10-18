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
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . LAT_Helpers::CPT_WS, 'Setări ateliere', 'Setări', 'manage_options', 'lat-settings', array( __CLASS__, 'render' ) );
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

	public static function sanitize( $in ) {
		$in     = is_array( $in ) ? $in : array();
		$accent = sanitize_hex_color( isset( $in['accent'] ) ? $in['accent'] : '' );
		return array(
			'notify_email'  => implode( ', ', array_filter( array_map( 'sanitize_email', explode( ',', isset( $in['notify_email'] ) ? (string) $in['notify_email'] : '' ) ) ) ),
			'contact_phone' => sanitize_text_field( isset( $in['contact_phone'] ) ? $in['contact_phone'] : '' ),
			'gdpr_text'     => wp_kses_post( isset( $in['gdpr_text'] ) ? $in['gdpr_text'] : '' ),
			'info_text'     => sanitize_textarea_field( isset( $in['info_text'] ) ? $in['info_text'] : '' ),
			'reminders'     => empty( $in['reminders'] ) ? 0 : 1,
			'waitlist'      => empty( $in['waitlist'] ) ? 0 : 1,
			'accent'        => $accent ? $accent : LAT_Helpers::defaults()['accent'],
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
			<h1>Setări ateliere</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'lat_settings_group' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="lat-notify">Notificări înscrieri</label></th>
						<td><input type="text" id="lat-notify" class="regular-text" name="<?php echo esc_attr( self::name( 'notify_email' ) ); ?>" value="<?php echo esc_attr( $s['notify_email'] ); ?>">
						<p class="description">Una sau mai multe adrese, separate prin virgulă.</p></td>
					</tr>
					<tr>
						<th><label for="lat-phone">Telefon de contact</label></th>
						<td><input type="text" id="lat-phone" class="regular-text" name="<?php echo esc_attr( self::name( 'contact_phone' ) ); ?>" value="<?php echo esc_attr( $s['contact_phone'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="lat-info">Informații utile</label></th>
						<td><textarea id="lat-info" rows="4" class="large-text" name="<?php echo esc_attr( self::name( 'info_text' ) ); ?>"><?php echo esc_textarea( $s['info_text'] ); ?></textarea>
						<p class="description">Apar în emailul de confirmare și în reamintire (acces, parcare, ținută).</p></td>
					</tr>
					<tr>
						<th>Opțiuni</th>
						<td>
							<label><input type="hidden" name="<?php echo esc_attr( self::name( 'waitlist' ) ); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr( self::name( 'waitlist' ) ); ?>" value="1" <?php checked( $s['waitlist'] ); ?>> Listă de așteptare când atelierul e complet (locurile eliberate se ocupă automat, în ordinea înscrierii)</label><br><br>
							<label><input type="hidden" name="<?php echo esc_attr( self::name( 'reminders' ) ); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr( self::name( 'reminders' ) ); ?>" value="1" <?php checked( $s['reminders'] ); ?>> Email de reamintire cu o zi înainte</label>
						</td>
					</tr>
					<tr>
						<th><label for="lat-gdpr">Text acord GDPR</label></th>
						<td><textarea id="lat-gdpr" rows="2" class="large-text" name="<?php echo esc_attr( self::name( 'gdpr_text' ) ); ?>"><?php echo esc_textarea( $s['gdpr_text'] ); ?></textarea>
						<p class="description">Poți pune link: &lt;a href="/politica-de-confidentialitate"&gt;politica de confidențialitate&lt;/a&gt;</p></td>
					</tr>
					<tr>
						<th><label for="lat-accent">Culoare principală</label></th>
						<td><input type="color" id="lat-accent" name="<?php echo esc_attr( self::name( 'accent' ) ); ?>" value="<?php echo esc_attr( $s['accent'] ); ?>"></td>
					</tr>
				</table>
				<h2>Cum îl folosești</h2>
				<ol>
					<li><strong>Ateliere → Categorii</strong>: opțional, ex. Primăvară, Vară, Toamnă, Iarnă sau Animale, Grădină, Bucătărie.</li>
					<li><strong>Ateliere → Adaugă atelier</strong>: titlu, descriere, imagine, dată, locuri, preț.</li>
					<li>Pune <code>[lipovenesc_ateliere]</code> într-o pagină. Pentru o singură categorie: <code>[lipovenesc_ateliere categorie="toamna"]</code>.</li>
					<li>Același atelier la altă dată: în lista de ateliere, „Duplică”.</li>
				</ol>
				<?php submit_button( 'Salvează setările' ); ?>
			</form>
		</div>
		<?php
	}
}
