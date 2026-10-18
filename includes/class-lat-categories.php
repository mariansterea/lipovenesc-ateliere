<?php
/**
 * Event categories with their own rules: minimum / maximum participants, prices,
 * booking mode, colour and image. Events inherit these unless they override them.
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'ABSPATH' ) || exit;

class LAT_Categories {

	public static function init() {
		add_action( LAT_Helpers::TAX . '_add_form_fields', array( __CLASS__, 'add_fields' ) );
		add_action( LAT_Helpers::TAX . '_edit_form_fields', array( __CLASS__, 'edit_fields' ) );
		add_action( 'created_' . LAT_Helpers::TAX, array( __CLASS__, 'save' ) );
		add_action( 'edited_' . LAT_Helpers::TAX, array( __CLASS__, 'save' ) );
		add_filter( 'manage_edit-' . LAT_Helpers::TAX . '_columns', array( __CLASS__, 'columns' ) );
		add_filter( 'manage_' . LAT_Helpers::TAX . '_custom_column', array( __CLASS__, 'column' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'seed' ) );
	}

	/**
	 * First run: create the three default categories so the page is never empty.
	 */
	public static function seed() {
		if ( get_option( 'lat_cats_seeded' ) ) {
			return;
		}
		$defaults = array(
			array( 'Ateliere', 'ateliere', 'Ateliere practice pentru familii și copii: meșteșuguri, animale, grădină.', '#4f6246', 1 ),
			array( 'Gastronomie', 'gastronomie', 'Mese tematice și seri cu bucate locale, în curtea fermei.', '#a1653a', 2 ),
			array( 'Concerte și seri în curte', 'concerte', 'Concerte, proiecții de film și seri culturale sub cerul liber.', '#3f5d7a', 3 ),
		);
		foreach ( $defaults as $d ) {
			if ( term_exists( $d[1], LAT_Helpers::TAX ) ) {
				continue;
			}
			$t = wp_insert_term( $d[0], LAT_Helpers::TAX, array( 'slug' => $d[1] ) );
			if ( ! is_wp_error( $t ) ) {
				$meta          = LAT_Helpers::cat_defaults();
				$meta['intro'] = $d[2];
				$meta['color'] = $d[3];
				$meta['order'] = $d[4];
				update_term_meta( $t['term_id'], '_lat_cat', $meta );
			}
		}
		update_option( 'lat_cats_seeded', 1 );
	}

	public static function assets( $hook ) {
		if ( in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
			$screen = get_current_screen();
			if ( $screen && LAT_Helpers::TAX === $screen->taxonomy ) {
				wp_enqueue_media();
				wp_enqueue_style( 'lat-admin', LAT_URL . 'assets/css/admin.css', array(), LAT_VERSION );
				wp_enqueue_script( 'lat-admin', LAT_URL . 'assets/js/admin.js', array( 'jquery' ), LAT_VERSION, true );
			}
		}
	}

	/* ---------- Fields ---------- */

	private static function fields( $c ) {
		$img = $c['image_id'] ? wp_get_attachment_image_url( (int) $c['image_id'], 'medium' ) : '';
		return array(
			'intro'       => array( 'Descriere scurtă', '<textarea name="lat_cat[intro]" rows="2" class="large-text">' . esc_textarea( $c['intro'] ) . '</textarea><p class="description">Apare pe cardul categoriei și sub titlul listei filtrate.</p>' ),
			'image'       => array( 'Imagine', '<div class="lat-media"><input type="hidden" name="lat_cat[image_id]" value="' . (int) $c['image_id'] . '"><img src="' . esc_url( $img ) . '" alt="" ' . ( $img ? '' : 'hidden' ) . '><button type="button" class="button lat-media-pick">Alege imaginea</button> <button type="button" class="button-link lat-media-clear" ' . ( $img ? '' : 'hidden' ) . '>Scoate</button></div><p class="description">Fotografia mare a categoriei (ca în secțiunea cu trei coloane de pe homepage).</p>' ),
			'color'       => array( 'Culoare', '<input type="color" name="lat_cat[color]" value="' . esc_attr( $c['color'] ) . '"><p class="description">Banda colorată a cardurilor din această categorie.</p>' ),
			'people'      => array( 'Participanți', 'minim <input type="number" min="0" class="small-text" name="lat_cat[min]" value="' . (int) $c['min'] . '"> · maxim <input type="number" min="1" class="small-text" name="lat_cat[capacity]" value="' . (int) $c['capacity'] . '"> <select name="lat_cat[count]"><option value="all" ' . selected( $c['count'], 'all', false ) . '>se numără toți (adulți + copii)</option><option value="children" ' . selected( $c['count'], 'children', false ) . '>se numără doar copiii</option></select><p class="description">Minim 0 = evenimentul are loc oricum. Dacă minimul nu e atins cu ' . (int) LAT_Helpers::get( 'min_alert_h' ) . ' ore înainte, primiți o alertă pe email.</p>' ),
			'price'       => array( 'Preț implicit', 'Adult <input type="text" class="small-text" name="lat_cat[price_adult]" value="' . esc_attr( $c['price_adult'] ) . '" placeholder="80"> lei · Copil <input type="text" class="small-text" name="lat_cat[price_child]" value="' . esc_attr( $c['price_child'] ) . '" placeholder="60"> lei<p class="description">Gol = gratuit. 0 la adult = adulții însoțitori nu plătesc. Orice eveniment poate suprascrie prețul.</p>' ),
			'mode'        => array( 'Mod de rezervare', self::mode_select( 'lat_cat[mode]', $c['mode'] ) ),
			'ages'        => array( 'Vârstă recomandată', '<input type="text" class="regular-text" name="lat_cat[ages]" value="' . esc_attr( $c['ages'] ) . '" placeholder="4–12 ani">' ),
			'cta'         => array( 'Text buton (opțional)', '<input type="text" class="regular-text" name="lat_cat[cta]" value="' . esc_attr( $c['cta'] ) . '" placeholder="Rezervă / Cumpără bilet">' ),
			'order'       => array( 'Ordine', '<input type="number" class="small-text" name="lat_cat[order]" value="' . (int) $c['order'] . '"><p class="description">Ordinea cardurilor de categorie (1, 2, 3…).</p>' ),
		);
	}

	public static function mode_select( $name, $value, $inherit_label = '' ) {
		$html = '<select name="' . esc_attr( $name ) . '">';
		if ( $inherit_label ) {
			$html .= '<option value="inherit" ' . selected( $value, 'inherit', false ) . '>' . esc_html( $inherit_label ) . '</option>';
		}
		foreach ( LAT_Helpers::MODES as $k => $label ) {
			$html .= '<option value="' . esc_attr( $k ) . '" ' . selected( $value, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select>';
		if ( ! LAT_Helpers::woo_active() ) {
			$html .= '<p class="description">„Cumpără bilet” cere WooCommerce activ (cu Stripe sau alt procesator). Până atunci, evenimentele cad automat pe „Rezervă”.</p>';
		}
		return $html;
	}

	public static function add_fields() {
		wp_nonce_field( 'lat_cat_save', 'lat_cat_nonce' );
		foreach ( self::fields( LAT_Helpers::cat_defaults() ) as $key => $f ) {
			echo '<div class="form-field lat-cat-field"><label>' . esc_html( $f[0] ) . '</label>' . $f[1] . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
	}

	public static function edit_fields( $term ) {
		wp_nonce_field( 'lat_cat_save', 'lat_cat_nonce' );
		foreach ( self::fields( LAT_Helpers::cat_meta( $term->term_id ) ) as $key => $f ) {
			echo '<tr class="form-field lat-cat-field"><th scope="row">' . esc_html( $f[0] ) . '</th><td>' . $f[1] . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public static function save( $term_id ) {
		if ( ! isset( $_POST['lat_cat_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lat_cat_nonce'] ) ), 'lat_cat_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$in    = isset( $_POST['lat_cat'] ) && is_array( $_POST['lat_cat'] ) ? wp_unslash( $_POST['lat_cat'] ) : array();
		$color = sanitize_hex_color( isset( $in['color'] ) ? $in['color'] : '' );
		$data  = array(
			'intro'       => sanitize_textarea_field( isset( $in['intro'] ) ? $in['intro'] : '' ),
			'color'       => $color ? $color : LAT_Helpers::cat_defaults()['color'],
			'image_id'    => absint( isset( $in['image_id'] ) ? $in['image_id'] : 0 ),
			'min'         => absint( isset( $in['min'] ) ? $in['min'] : 0 ),
			'capacity'    => max( 1, absint( isset( $in['capacity'] ) ? $in['capacity'] : 20 ) ),
			'count'       => isset( $in['count'] ) && 'children' === $in['count'] ? 'children' : 'all',
			'price_adult' => sanitize_text_field( isset( $in['price_adult'] ) ? $in['price_adult'] : '' ),
			'price_child' => sanitize_text_field( isset( $in['price_child'] ) ? $in['price_child'] : '' ),
			'mode'        => isset( $in['mode'] ) && isset( LAT_Helpers::MODES[ $in['mode'] ] ) ? $in['mode'] : 'reserve',
			'ages'        => sanitize_text_field( isset( $in['ages'] ) ? $in['ages'] : '' ),
			'cta'         => sanitize_text_field( isset( $in['cta'] ) ? $in['cta'] : '' ),
			'order'       => absint( isset( $in['order'] ) ? $in['order'] : 0 ),
		);
		update_term_meta( $term_id, '_lat_cat', $data );
	}

	/* ---------- List columns ---------- */

	public static function columns( $columns ) {
		$new = array();
		foreach ( $columns as $k => $v ) {
			if ( 'description' === $k ) {
				continue;
			}
			$new[ $k ] = $v;
			if ( 'name' === $k ) {
				$new['lat_rules'] = 'Reguli';
			}
		}
		return $new;
	}

	public static function column( $out, $column, $term_id ) {
		if ( 'lat_rules' !== $column ) {
			return $out;
		}
		$c     = LAT_Helpers::cat_meta( $term_id );
		$parts = array(
			'<span class="lat-swatch" style="background:' . esc_attr( $c['color'] ) . '"></span>',
			esc_html( ( $c['min'] ? 'min ' . (int) $c['min'] . ' · ' : '' ) . 'max ' . (int) $c['capacity'] ),
			esc_html( LAT_Helpers::price_label( $c ) ),
			esc_html( 'buy' === $c['mode'] ? 'Cumpără bilet' : 'Rezervă' ),
		);
		return implode( ' · ', $parts );
	}
}
