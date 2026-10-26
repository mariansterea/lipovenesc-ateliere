<?php
/**
 * Events: post type (public, with its own page), category taxonomy, meta boxes,
 * list columns, duplicate to several dates, cancel an event.
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'ABSPATH' ) || exit;

class LAT_Workshops {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . LAT_Helpers::CPT_WS, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manage_' . LAT_Helpers::CPT_WS . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . LAT_Helpers::CPT_WS . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'manage_edit-' . LAT_Helpers::CPT_WS . '_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'list_order' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_lat_duplicate', array( __CLASS__, 'duplicate' ) );
		add_action( 'admin_post_lat_cancel_event', array( __CLASS__, 'cancel_event' ) );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'edit_form_after_title', array( __CLASS__, 'after_title' ) );
	}

	public static function register() {
		$slug = sanitize_title( (string) LAT_Helpers::get( 'slug' ) );
		register_post_type(
			LAT_Helpers::CPT_WS,
			array(
				'labels'              => array(
					'name'               => 'Evenimente',
					'singular_name'      => 'Eveniment',
					'menu_name'          => 'Evenimente',
					'all_items'          => 'Toate evenimentele',
					'add_new'            => 'Adaugă eveniment',
					'add_new_item'       => 'Adaugă eveniment nou',
					'edit_item'          => 'Editează evenimentul',
					'view_item'          => 'Vezi evenimentul',
					'not_found'          => 'Niciun eveniment încă.',
					'featured_image'     => 'Imaginea evenimentului',
					'set_featured_image' => 'Setează imaginea',
				),
				'public'              => true,
				'publicly_queryable'  => true,
				'exclude_from_search' => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => true,
				'menu_position'       => 56,
				'menu_icon'           => 'dashicons-tickets-alt',
				'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'has_archive'         => false,
				'rewrite'             => array(
					'slug'       => $slug ? $slug : 'eveniment',
					'with_front' => false,
				),
				'show_in_rest'        => true,
			)
		);
		register_taxonomy(
			LAT_Helpers::TAX,
			LAT_Helpers::CPT_WS,
			array(
				'labels'            => array(
					'name'          => 'Categorii',
					'singular_name' => 'Categorie',
					'add_new_item'  => 'Adaugă categorie',
					'edit_item'     => 'Editează categoria',
					'menu_name'     => 'Categorii',
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'rewrite'           => false,
			)
		);
	}

	public static function title_placeholder( $text, $post ) {
		return LAT_Helpers::CPT_WS === $post->post_type ? 'Numele evenimentului (ex. Pomana porcului de Ignat)' : $text;
	}

	public static function assets() {
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->post_type, array( LAT_Helpers::CPT_WS, LAT_Helpers::CPT_REG ), true ) ) {
			wp_enqueue_style( 'lat-admin', LAT_URL . 'assets/css/admin.css', array(), LAT_VERSION );
			wp_enqueue_script( 'lat-admin', LAT_URL . 'assets/js/admin.js', array( 'jquery' ), LAT_VERSION, true );
		}
	}

	public static function meta_boxes() {
		// Detaliile stau imediat sub titlu (vezi after_title), nu sub editor.
		$scr = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ctx = ( $scr && method_exists( $scr, 'is_block_editor' ) && $scr->is_block_editor() ) ? 'normal' : 'lat_top';
		add_meta_box( 'lat_details', 'Detaliile evenimentului', array( __CLASS__, 'box' ), LAT_Helpers::CPT_WS, $ctx, 'high' );
		add_meta_box( 'lat_status', 'Înscrieri', array( __CLASS__, 'box_status' ), LAT_Helpers::CPT_WS, 'side', 'default' );
		add_meta_box( 'lat_embed', 'Promovare și embed', array( __CLASS__, 'box_embed' ), LAT_Helpers::CPT_WS, 'side', 'default' );
		// Categoria se alege din cardurile din „Detaliile evenimentului”.
		remove_meta_box( LAT_Helpers::TAX . 'div', LAT_Helpers::CPT_WS, 'side' );
	}

	public static function after_title( $post ) {
		if ( LAT_Helpers::CPT_WS !== $post->post_type ) {
			return;
		}
		do_meta_boxes( get_current_screen(), 'lat_top', $post );
		echo '<h2 class="latd-desc-h">Descrierea evenimentului <small>apare pe pagina evenimentului; poza se pune din „Imaginea evenimentului”, în dreapta</small></h2>';
	}

	public static function box( $post ) {
		$m   = LAT_Helpers::ws_raw( $post->ID );
		$eff = LAT_Helpers::ws_meta( $post->ID );
		$cat = $eff['cat'];
		$c   = $cat ? LAT_Helpers::cat_meta( $cat->term_id ) : LAT_Helpers::cat_defaults();
		wp_nonce_field( 'lat_ws_save', 'lat_ws_nonce' );
		$cats = LAT_Helpers::categories();
		?>
		<div class="latd-ed">
			<section class="latd-ed-sec">
				<h3><span>1</span> Categoria</h3>
				<div class="latd-catpick">
					<?php foreach ( $cats as $t ) : $cm = LAT_Helpers::cat_meta( $t->term_id ); ?>
						<label style="--c:<?php echo esc_attr( $cm['color'] ); ?>"><input type="radio" name="lat_cat_pick" value="<?php echo (int) $t->term_id; ?>" <?php checked( $cat && (int) $cat->term_id === (int) $t->term_id ); ?>><span><b><?php echo esc_html( $t->name ); ?></b><small><?php echo esc_html( ( $cm['free'] ? 'gratuit' : LAT_Helpers::price_label( $cm ) ) . ' · max ' . (int) $cm['capacity'] . ' locuri' ); ?></small></span></label>
					<?php endforeach; ?>
				</div>
				<p class="latd-hint">Din categorie vin prețul, locurile, modul de rezervare și culoarea. Mai jos le poți schimba doar pentru acest eveniment.</p>
			</section>

			<section class="latd-ed-sec">
				<h3><span>2</span> Când și unde</h3>
				<div class="latd-row4">
					<label class="latd-f"><span>Data</span><input type="date" name="lat[date]" value="<?php echo esc_attr( $m['date'] ); ?>" required></label>
					<label class="latd-f"><span>De la</span><input type="time" name="lat[start]" value="<?php echo esc_attr( $m['start'] ); ?>"></label>
					<label class="latd-f"><span>Până la</span><input type="time" name="lat[end]" value="<?php echo esc_attr( $m['end'] ); ?>"></label>
					<label class="latd-f latd-f-wide"><span>Locație</span><input type="text" name="lat[location]" value="<?php echo esc_attr( $m['location'] ); ?>"></label>
				</div>
				<p class="latd-hint">Același eveniment la mai multe date: după salvare, folosește „Duplică la alte date” din dreapta.</p>
			</section>

			<section class="latd-ed-sec">
				<h3><span>3</span> Participanți</h3>
				<div class="latd-row4">
					<label class="latd-f"><span>Minim</span><input type="number" min="0" name="lat[min]" value="<?php echo esc_attr( $m['min'] ); ?>" placeholder="<?php echo (int) $c['min']; ?>"></label>
					<label class="latd-f"><span>Maxim (locuri)</span><input type="number" min="1" name="lat[capacity]" value="<?php echo esc_attr( $m['capacity'] ); ?>" placeholder="<?php echo (int) $c['capacity']; ?>"></label>
					<label class="latd-f latd-f-wide"><span>Ce se numără ca loc</span><select name="lat[count]">
						<option value="inherit" <?php selected( $m['count'], 'inherit' ); ?>>ca în categorie (<?php echo 'children' === $c['count'] ? 'doar copiii' : 'toți participanții'; ?>)</option>
						<option value="all" <?php selected( $m['count'], 'all' ); ?>>toți participanții (adulți + copii)</option>
						<option value="children" <?php selected( $m['count'], 'children' ); ?>>doar copiii</option>
					</select></label>
				</div>
				<p class="latd-hint">Gol = valoarea din categorie (scrisă gri). Sub minim, pe site apare „se confirmă la X înscriși”; cu <?php echo (int) LAT_Helpers::get( 'min_alert_h' ); ?> ore înainte primești alertă dacă minimul nu e atins.</p>
			</section>

			<section class="latd-ed-sec">
				<h3><span>4</span> Preț și rezervare</h3>
				<div class="latd-row4">
					<label class="latd-f"><span>Acces</span><select name="lat[free]" class="lat-free-select">
						<option value="inherit" <?php selected( (string) $m['free'], 'inherit' ); ?>>ca în categorie (<?php echo $c['free'] ? 'gratuit' : 'cu plată'; ?>)</option>
						<option value="0" <?php selected( (string) $m['free'], '0' ); ?>>cu plată</option>
						<option value="1" <?php selected( (string) $m['free'], '1' ); ?>>gratuit</option>
					</select></label>
					<label class="latd-f latd-f-wide lat-row-price"><span>Mod de rezervare</span><?php echo LAT_Categories::mode_select( 'lat[mode]', $m['mode'], 'ca în categorie (' . ( 'buy' === $c['mode'] ? 'Cumpără bilet' : 'Rezervă' ) . ')' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
				</div>
				<div class="lat-row-price latd-price">
					<label class="latd-check"><input type="hidden" name="lat[inherit_price]" value="0"><input type="checkbox" name="lat[inherit_price]" value="1" class="lat-inherit-price" <?php checked( $m['inherit_price'] ); ?>> Prețul categoriei: <b><?php echo esc_html( LAT_Helpers::price_label( $c ) ); ?></b></label>
					<div class="lat-price-own latd-row4" <?php echo $m['inherit_price'] ? 'hidden' : ''; ?>>
						<label class="latd-f"><span>Adult (lei)</span><input type="text" name="lat[price_adult]" value="<?php echo esc_attr( $m['price_adult'] ); ?>" placeholder="80"></label>
						<label class="latd-f"><span>Copil (lei)</span><input type="text" name="lat[price_child]" value="<?php echo esc_attr( $m['price_child'] ); ?>" placeholder="60"></label>
						<p class="latd-hint latd-f-wide">Copil gol = prețul de adult. 0 la adult = adulții însoțitori nu plătesc.</p>
					</div>
					<?php if ( LAT_Helpers::price_missing( $eff ) ) : ?>
						<p class="latd-warn"><strong>Lipsește prețul.</strong> Nici evenimentul, nici categoria nu au preț: pe site apare „Preț în curând” și înscrierea merge pe „Rezervă”.</p>
					<?php endif; ?>
				</div>
				<p class="latd-hint lat-row-price">„Cumpără bilet”: plata online acum, prin WooCommerce; locul se ține <?php echo (int) LAT_Helpers::get( 'hold_minutes' ); ?> de minute până la plată. „Rezervă”: locul se ține, plata se face la fermă.</p>
			</section>

			<section class="latd-ed-sec">
				<h3><span>5</span> Pentru participanți</h3>
				<div class="latd-row4">
					<label class="latd-f"><span>Vârstă recomandată</span><input type="text" name="lat[ages]" value="<?php echo esc_attr( $m['ages'] ); ?>" placeholder="<?php echo esc_attr( $c['ages'] ? $c['ages'] : '4–12 ani' ); ?>"></label>
					<label class="latd-f"><span>Text pe buton</span><input type="text" name="lat[cta]" value="<?php echo esc_attr( $m['cta'] ); ?>" placeholder="<?php echo esc_attr( $c['cta'] ? $c['cta'] : 'Rezervă / Cumpără bilet' ); ?>"></label>
					<label class="latd-f latd-f-wide"><span>Cine susține (artist, trupă, meșter, gazdă)</span><input type="text" name="lat[performer]" value="<?php echo esc_attr( $m['performer'] ); ?>" placeholder="Ferma Lipovenesc"></label>
					<label class="latd-f latd-f-wide"><span>Ce să aducă</span><textarea rows="2" name="lat[bring]" placeholder="Cizme de cauciuc, haine care se pot murdări"><?php echo esc_textarea( $m['bring'] ); ?></textarea></label>
				</div>
			</section>

			<section class="latd-ed-sec">
				<h3><span>6</span> Înscrieri</h3>
				<div class="latd-row4">
					<label class="latd-switch"><input type="hidden" name="lat[open]" value="0"><input type="checkbox" name="lat[open]" value="1" <?php checked( $m['open'] ); ?>><i></i> Înscrieri deschise</label>
					<label class="latd-f"><span>Se închid cu (ore înainte)</span><input type="number" min="0" name="lat[deadline]" value="<?php echo (int) $m['deadline']; ?>"></label>
					<label class="latd-f"><span>Maxim persoane / înscriere</span><input type="number" min="1" name="lat[max_per]" value="<?php echo (int) $m['max_per']; ?>"></label>
				</div>
			</section>
		</div>
		<?php
	}

	public static function box_status( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>Salvați evenimentul ca să primiți înscrieri.</p>';
			return;
		}
		$m     = LAT_Helpers::ws_meta( $post->ID );
		$taken = LAT_Helpers::places_taken( $post->ID );
		$wait  = LAT_Helpers::waitlist_count( $post->ID );
		$state = LAT_Helpers::ws_state( $post->ID );
		if ( ! empty( $m['cancelled'] ) ) {
			echo '<p><strong style="color:#b32d2e">Eveniment anulat.</strong> Înscrierile au fost anulate și participanții anunțați.</p>';
		}
		printf( '<p><strong>%d</strong> din %d locuri ocupate · stare: %s</p>', (int) $taken, (int) $m['capacity'], esc_html( self::state_label( $state ) ) );
		if ( $m['min'] > 0 ) {
			$people = LAT_Helpers::people_confirmed( $post->ID );
			printf( '<p>%s</p>', $people >= $m['min'] ? '<span style="color:#1a7f37">Minimul de ' . (int) $m['min'] . ' participanți e atins.</span>' : 'Minim ' . (int) $m['min'] . ' participanți: deocamdată ' . (int) $people . '.' );
		}
		if ( $wait ) {
			printf( '<p>%d înscrieri pe lista de așteptare</p>', (int) $wait );
		}
		if ( $m['free'] ) {
			echo '<p>Acces: <strong>gratuit</strong> (înscriere cu „Rezervă”)</p>';
		} else {
			printf( '<p>Mod: <strong>%s</strong> · %s%s</p>', 'buy' === $m['mode_eff'] ? 'Cumpără bilet' : 'Rezervă', esc_html( LAT_Helpers::price_label( $m ) ), 'buy' === $m['mode'] && 'buy' !== $m['mode_eff'] ? ' <span class="lat-muted">(' . ( LAT_Helpers::price_missing( $m ) ? 'lipsește prețul' : 'WooCommerce indisponibil' ) . ', se folosește Rezervă)</span>' : '' );
		}
		printf( '<p><a class="button" href="%s">Lista participanților</a> <a class="button" href="%s">Check-in</a></p>', esc_url( admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_WS . '&page=lat-participants&workshop=' . $post->ID ) ), esc_url( admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_WS . '&page=lat-checkin&workshop=' . $post->ID ) ) );
		printf( '<p><a href="%s">Toate înscrierile</a></p>', esc_url( admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_REG . '&lat_ws=' . $post->ID ) ) );
		if ( empty( $m['cancelled'] ) && 'past' !== $state ) {
			printf(
				'<hr><p><a class="lat-danger" href="%s" onclick="return confirm(\'Anulezi evenimentul? Toți participanții primesc email, iar plățile online se rambursează.\')">Anulează evenimentul</a></p>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lat_cancel_event&id=' . $post->ID ), 'lat_cancel_' . $post->ID ) )
			);
		}
	}

	public static function box_embed( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>Disponibil după salvare.</p>';
			return;
		}
		$url = LAT_Helpers::ws_url( $post->ID );
		echo '<p>Pagina evenimentului:<br><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a></p>';
		echo '<p>Cardul cu formular, într-un articol sau pagină:<br><code>[lipovenesc_eveniment id="' . (int) $post->ID . '"]</code></p>';
		$cat = LAT_Helpers::ws_category( $post->ID );
		if ( $cat ) {
			echo '<p>Toate evenimentele din categorie:<br><code>[lipovenesc_evenimente categorie="' . esc_html( $cat->slug ) . '"]</code></p>';
		}
		echo '<p class="lat-muted">Lista completă: <code>[lipovenesc_evenimente]</code> · Cardurile celor 3 categorii: <code>[lipovenesc_categorii]</code> · Următoarele 3: <code>[lipovenesc_evenimente layout="urmatoarele" limit="3"]</code></p>';
		// Câmpurile stau în formularul mare de editare (un <form> în alt <form> ar rupe salvarea).
		echo '<hr><p><strong>Duplică la alte date</strong></p>';
		echo '<textarea name="lat_dup_dates" rows="3" class="widefat" placeholder="2026-11-08&#10;2026-11-15&#10;2026-11-22"></textarea>';
		echo '<p class="description">O dată pe rând (AAAA-LL-ZZ), apoi apăsați „Actualizează”. Se creează copii ca ciorne, cu aceleași ore, prețuri și locuri.</p>';
	}

	public static function state_label( $state ) {
		$labels = array(
			'open'      => 'înscrieri deschise',
			'full'      => 'complet',
			'closed'    => 'înscrieri închise',
			'past'      => 'a avut loc',
			'cancelled' => 'anulat',
		);
		return isset( $labels[ $state ] ) ? $labels[ $state ] : $state;
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['lat_ws_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lat_ws_nonce'] ) ), 'lat_ws_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$in   = isset( $_POST['lat'] ) && is_array( $_POST['lat'] ) ? wp_unslash( $_POST['lat'] ) : array();
		$old  = LAT_Helpers::ws_raw( $post_id );
		$d    = LAT_Helpers::ws_defaults();
		$time = function ( $v, $fallback ) {
			return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v ) ? $v : $fallback;
		};
		$date = isset( $in['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $in['date'] ) ? $in['date'] : '';
		$opt  = function ( $v ) {
			return '' === trim( (string) $v ) ? '' : absint( $v );
		};

		$data = array(
			'date'          => $date,
			'start'         => $time( isset( $in['start'] ) ? $in['start'] : '', $d['start'] ),
			'end'           => $time( isset( $in['end'] ) ? $in['end'] : '', $d['end'] ),
			'location'      => sanitize_text_field( isset( $in['location'] ) ? $in['location'] : '' ),
			'capacity'      => $opt( isset( $in['capacity'] ) ? $in['capacity'] : '' ),
			'min'           => $opt( isset( $in['min'] ) ? $in['min'] : '' ),
			'count'         => isset( $in['count'] ) && in_array( $in['count'], array( 'inherit', 'all', 'children' ), true ) ? $in['count'] : 'inherit',
			'free'          => isset( $in['free'] ) && in_array( (string) $in['free'], array( '0', '1' ), true ) ? (int) $in['free'] : 'inherit',
			'price_adult'   => sanitize_text_field( isset( $in['price_adult'] ) ? $in['price_adult'] : '' ),
			'price_child'   => sanitize_text_field( isset( $in['price_child'] ) ? $in['price_child'] : '' ),
			'inherit_price' => empty( $in['inherit_price'] ) ? 0 : 1,
			'mode'          => isset( $in['mode'] ) && ( 'inherit' === $in['mode'] || isset( LAT_Helpers::MODES[ $in['mode'] ] ) ) ? $in['mode'] : 'inherit',
			'ages'          => sanitize_text_field( isset( $in['ages'] ) ? $in['ages'] : '' ),
			'bring'         => sanitize_textarea_field( isset( $in['bring'] ) ? $in['bring'] : '' ),
			'cta'           => sanitize_text_field( isset( $in['cta'] ) ? $in['cta'] : '' ),
			'performer'     => sanitize_text_field( isset( $in['performer'] ) ? $in['performer'] : '' ),
			'deadline'      => absint( isset( $in['deadline'] ) ? $in['deadline'] : 0 ),
			'max_per'       => max( 1, absint( isset( $in['max_per'] ) ? $in['max_per'] : 1 ) ),
			'open'          => empty( $in['open'] ) ? 0 : 1,
			'cancelled'     => (int) $old['cancelled'],
			'product_id'    => (int) $old['product_id'],
		);
		if ( '' !== $data['capacity'] ) {
			$data['capacity'] = max( 1, (int) $data['capacity'] );
		}
		update_post_meta( $post_id, '_lat', $data );
		if ( isset( $_POST['lat_cat_pick'] ) && absint( $_POST['lat_cat_pick'] ) && term_exists( absint( $_POST['lat_cat_pick'] ), LAT_Helpers::TAX ) ) {
			wp_set_object_terms( $post_id, array( absint( $_POST['lat_cat_pick'] ) ), LAT_Helpers::TAX );
		}

		// Copii la alte date, cerute din căsuța „Promovare și embed”.
		if ( ! empty( $_POST['lat_dup_dates'] ) ) {
			$raw   = sanitize_textarea_field( wp_unslash( $_POST['lat_dup_dates'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce-ul formularului e verificat mai sus.
			$dates = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $raw ) ) );
			$n     = 0;
			remove_action( 'save_post_' . LAT_Helpers::CPT_WS, array( __CLASS__, 'save' ), 10 );
			foreach ( $dates as $date ) {
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && self::copy( $post_id, $date ) ) {
					$n++;
				}
			}
			add_action( 'save_post_' . LAT_Helpers::CPT_WS, array( __CLASS__, 'save' ), 10, 2 );
			set_transient( 'lat_notice_' . get_current_user_id(), $n . ' copii create ca ciorne (Evenimente → Ciorne). Verificați-le și publicați-le.', 60 );
		}
		update_post_meta( $post_id, '_lat_start', $date ? $date . ' ' . $data['start'] : '' );

		// Keep the WooCommerce product in sync (created lazily, only for paid events).
		if ( class_exists( 'LAT_Woo' ) ) {
			LAT_Woo::sync_product( $post_id );
		}
		// More places may free the waitlist.
		LAT_Registrations::promote_waitlist( $post_id );
	}

	/* ---------- Admin list ---------- */

	public static function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				continue;
			}
			if ( 'title' === $key ) {
				$new['lat_color'] = '';
			}
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['lat_when']   = 'Data';
				$new['lat_places'] = 'Locuri';
				$new['lat_mode']   = 'Mod';
			}
		}
		return $new;
	}

	public static function column( $column, $post_id ) {
		$m = LAT_Helpers::ws_meta( $post_id );
		if ( 'lat_color' === $column ) {
			echo '<span class="lat-swatch" style="background:' . esc_attr( $m['cat_color'] ) . '" title="' . esc_attr( $m['cat_name'] ) . '"></span>';
		} elseif ( 'lat_when' === $column ) {
			echo $m['date'] ? esc_html( LAT_Helpers::date_label( $m['date'] ) . ', ' . $m['start'] . '–' . $m['end'] ) : '—';
			$state = LAT_Helpers::ws_state( $post_id );
			if ( 'open' !== $state ) {
				echo '<br><em>' . esc_html( self::state_label( $state ) ) . '</em>';
			}
		} elseif ( 'lat_places' === $column ) {
			$taken = LAT_Helpers::places_taken( $post_id );
			$wait  = LAT_Helpers::waitlist_count( $post_id );
			$pct   = min( 100, round( $taken / max( 1, (int) $m['capacity'] ) * 100 ) );
			printf(
				'<a href="%s">%d / %d</a>%s%s<div class="lat-bar"><span style="width:%d%%"></span></div>',
				esc_url( admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_WS . '&page=lat-participants&workshop=' . $post_id ) ),
				(int) $taken,
				(int) $m['capacity'],
				$wait ? ' · ' . (int) $wait . ' în așteptare' : '',
				$m['min'] > 0 && ! LAT_Helpers::min_reached( $post_id ) ? ' · <span class="lat-warn">sub minim ' . (int) $m['min'] . '</span>' : '',
				(int) $pct
			);
		} elseif ( 'lat_mode' === $column ) {
			echo esc_html( $m['free'] ? 'Gratuit' : ( 'buy' === $m['mode_eff'] ? 'Bilet online' : 'Rezervare' ) );
			echo '<br><span class="' . ( LAT_Helpers::price_missing( $m ) ? 'lat-warn' : 'lat-muted' ) . '">' . esc_html( LAT_Helpers::price_label( $m ) ) . '</span>';
		}
	}

	public static function sortable( $columns ) {
		$columns['lat_when'] = 'lat_when';
		return $columns;
	}

	public static function list_order( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || LAT_Helpers::CPT_WS !== $query->get( 'post_type' ) ) {
			return;
		}
		$orderby = $query->get( 'orderby' );
		if ( ! $orderby || 'lat_when' === $orderby ) {
			$query->set(
				'meta_query',
				array(
					'relation' => 'OR',
					'lat_s'    => array(
						'key'     => '_lat_start',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_lat_start',
						'compare' => 'NOT EXISTS',
					),
				)
			);
			$query->set( 'orderby', 'lat_s' );
			$query->set( 'order', $query->get( 'order' ) ? $query->get( 'order' ) : 'DESC' );
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( LAT_Helpers::CPT_WS === $post->post_type ) {
			$actions['lat_dup']  = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lat_duplicate&id=' . $post->ID ), 'lat_dup_' . $post->ID ) ) . '">Duplică</a>';
			$actions['lat_list'] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_WS . '&page=lat-participants&workshop=' . $post->ID ) ) . '">Participanți</a>';
		}
		return $actions;
	}

	public static function copy( $source_id, $date, $status = 'draft' ) {
		$post = get_post( $source_id );
		$new  = wp_insert_post(
			array(
				'post_type'    => LAT_Helpers::CPT_WS,
				'post_status'  => $status,
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
			)
		);
		if ( ! $new || is_wp_error( $new ) ) {
			return 0;
		}
		$meta               = LAT_Helpers::ws_raw( $source_id );
		$meta['date']       = $date;
		$meta['cancelled']  = 0;
		$meta['product_id'] = 0;
		update_post_meta( $new, '_lat', $meta );
		update_post_meta( $new, '_lat_start', $date ? $date . ' ' . $meta['start'] : '' );
		$thumb = get_post_thumbnail_id( $source_id );
		if ( $thumb ) {
			set_post_thumbnail( $new, $thumb );
		}
		wp_set_object_terms( $new, wp_get_object_terms( $source_id, LAT_Helpers::TAX, array( 'fields' => 'ids' ) ), LAT_Helpers::TAX );
		return $new;
	}

	public static function duplicate() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'lat_dup_' . $id );
		if ( ! get_post( $id ) || LAT_Helpers::CPT_WS !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'Evenimentul nu a fost găsit.' );
		}
		$new = self::copy( $id, '' );
		wp_safe_redirect( admin_url( 'post.php?post=' . $new . '&action=edit' ) );
		exit;
	}

	public static function cancel_event() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'lat_cancel_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'Nu ai drepturi pentru această acțiune.' );
		}
		$meta              = LAT_Helpers::ws_raw( $id );
		$meta['cancelled'] = 1;
		$meta['open']      = 0;
		update_post_meta( $id, '_lat', $meta );
		$n = LAT_Registrations::cancel_all( $id, 'event' );
		set_transient( 'lat_notice_' . get_current_user_id(), 'Evenimentul a fost anulat. ' . (int) $n . ' înscrieri anulate și anunțate.', 60 );
		wp_safe_redirect( admin_url( 'post.php?post=' . $id . '&action=edit' ) );
		exit;
	}
}
