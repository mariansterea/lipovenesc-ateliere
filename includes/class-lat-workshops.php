<?php
/**
 * Workshops: post type, category taxonomy, meta box, list columns, duplicate.
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'ABSPATH' ) || exit;

class LAT_Workshops {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . LAT_Helpers::CPT_WS, array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manage_' . LAT_Helpers::CPT_WS . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . LAT_Helpers::CPT_WS . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'manage_edit-' . LAT_Helpers::CPT_WS . '_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'list_order' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_lat_duplicate', array( __CLASS__, 'duplicate' ) );
	}

	public static function register() {
		register_post_type(
			LAT_Helpers::CPT_WS,
			array(
				'labels'          => array(
					'name'               => 'Ateliere',
					'singular_name'      => 'Atelier',
					'menu_name'          => 'Ateliere',
					'all_items'          => 'Ateliere',
					'add_new'            => 'Adaugă atelier',
					'add_new_item'       => 'Adaugă atelier nou',
					'edit_item'          => 'Editează atelierul',
					'not_found'          => 'Niciun atelier încă.',
					'featured_image'     => 'Imaginea atelierului',
					'set_featured_image' => 'Setează imaginea',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_position'   => 56,
				'menu_icon'       => 'dashicons-calendar-alt',
				'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
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
					'menu_name'     => 'Categorii',
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'hierarchical'      => true,
				'rewrite'           => false,
			)
		);
	}

	public static function assets() {
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->post_type, array( LAT_Helpers::CPT_WS, LAT_Helpers::CPT_REG ), true ) ) {
			wp_enqueue_style( 'lat-admin', LAT_URL . 'assets/css/admin.css', array(), LAT_VERSION );
		}
	}

	public static function meta_boxes() {
		add_meta_box( 'lat_details', 'Data, locuri și preț', array( __CLASS__, 'box' ), LAT_Helpers::CPT_WS, 'normal', 'high' );
		add_meta_box( 'lat_status', 'Înscrieri', array( __CLASS__, 'box_status' ), LAT_Helpers::CPT_WS, 'side', 'high' );
	}

	public static function box( $post ) {
		$m = LAT_Helpers::ws_meta( $post->ID );
		wp_nonce_field( 'lat_ws_save', 'lat_ws_nonce' );
		?>
		<table class="form-table lat-form">
			<tr>
				<th>Data și ora</th>
				<td><input type="date" name="lat[date]" value="<?php echo esc_attr( $m['date'] ); ?>" required>
					de la <input type="time" name="lat[start]" value="<?php echo esc_attr( $m['start'] ); ?>">
					până la <input type="time" name="lat[end]" value="<?php echo esc_attr( $m['end'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="lat-location">Locație</label></th>
				<td><input type="text" id="lat-location" class="regular-text" name="lat[location]" value="<?php echo esc_attr( $m['location'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="lat-capacity">Locuri disponibile</label></th>
				<td><input type="number" id="lat-capacity" min="1" class="small-text" name="lat[capacity]" value="<?php echo (int) $m['capacity']; ?>">
					<select name="lat[count]">
						<option value="all" <?php selected( $m['count'], 'all' ); ?>>se numără toți participanții (adulți + copii)</option>
						<option value="children" <?php selected( $m['count'], 'children' ); ?>>se numără doar copiii</option>
					</select></td>
			</tr>
			<tr>
				<th>Preț</th>
				<td>Adult <input type="text" class="small-text" name="lat[price_adult]" value="<?php echo esc_attr( $m['price_adult'] ); ?>" placeholder="80"> lei &nbsp;
					Copil <input type="text" class="small-text" name="lat[price_child]" value="<?php echo esc_attr( $m['price_child'] ); ?>" placeholder="60"> lei
					<p class="description">Lasă ambele goale pentru atelier gratuit. Dacă prețul de copil e gol, se folosește prețul de adult. Pune 0 la adult dacă adulții însoțitori nu plătesc.</p></td>
			</tr>
			<tr>
				<th><label for="lat-ages">Vârstă recomandată</label></th>
				<td><input type="text" id="lat-ages" class="regular-text" name="lat[ages]" value="<?php echo esc_attr( $m['ages'] ); ?>" placeholder="4–12 ani"></td>
			</tr>
			<tr>
				<th><label for="lat-bring">Ce să aducă</label></th>
				<td><textarea id="lat-bring" rows="2" class="large-text" name="lat[bring]" placeholder="Cizme de cauciuc, haine care se pot murdări"><?php echo esc_textarea( $m['bring'] ); ?></textarea></td>
			</tr>
			<tr>
				<th>Înscrieri</th>
				<td>
					<label><input type="hidden" name="lat[open]" value="0"><input type="checkbox" name="lat[open]" value="1" <?php checked( $m['open'] ); ?>> Deschise</label><br><br>
					se închid cu <input type="number" min="0" class="small-text" name="lat[deadline]" value="<?php echo (int) $m['deadline']; ?>"> ore înainte ·
					maximum <input type="number" min="1" class="small-text" name="lat[max_per]" value="<?php echo (int) $m['max_per']; ?>"> persoane pe o înscriere
				</td>
			</tr>
		</table>
		<p class="description">Descrierea atelierului se scrie în editorul de mai sus. „Rezumatul” (dacă îl completezi) apare pe cardul din calendar. Pentru același atelier la altă dată, folosește „Duplică” din lista de ateliere.</p>
		<?php
	}

	public static function box_status( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>Salvează atelierul ca să primești înscrieri.</p>';
			return;
		}
		$m     = LAT_Helpers::ws_meta( $post->ID );
		$taken = LAT_Helpers::places_taken( $post->ID );
		$wait  = LAT_Helpers::waitlist_count( $post->ID );
		printf( '<p><strong>%d</strong> din %d locuri ocupate</p>', (int) $taken, (int) $m['capacity'] );
		if ( $wait ) {
			printf( '<p>%d înscrieri pe lista de așteptare</p>', (int) $wait );
		}
		printf( '<p><a class="button" href="%s">Lista participanților</a></p>', esc_url( admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_WS . '&page=lat-participants&workshop=' . $post->ID ) ) );
		printf( '<p><a href="%s">Toate înscrierile</a></p>', esc_url( admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_REG . '&lat_ws=' . $post->ID ) ) );
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['lat_ws_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lat_ws_nonce'] ) ), 'lat_ws_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$in   = isset( $_POST['lat'] ) && is_array( $_POST['lat'] ) ? wp_unslash( $_POST['lat'] ) : array();
		$d    = LAT_Helpers::ws_defaults();
		$time = function ( $v, $fallback ) {
			return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v ) ? $v : $fallback;
		};
		$date = isset( $in['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $in['date'] ) ? $in['date'] : '';

		$data = array(
			'date'        => $date,
			'start'       => $time( isset( $in['start'] ) ? $in['start'] : '', $d['start'] ),
			'end'         => $time( isset( $in['end'] ) ? $in['end'] : '', $d['end'] ),
			'location'    => sanitize_text_field( isset( $in['location'] ) ? $in['location'] : '' ),
			'capacity'    => max( 1, absint( isset( $in['capacity'] ) ? $in['capacity'] : 1 ) ),
			'count'       => isset( $in['count'] ) && 'children' === $in['count'] ? 'children' : 'all',
			'price_adult' => sanitize_text_field( isset( $in['price_adult'] ) ? $in['price_adult'] : '' ),
			'price_child' => sanitize_text_field( isset( $in['price_child'] ) ? $in['price_child'] : '' ),
			'ages'        => sanitize_text_field( isset( $in['ages'] ) ? $in['ages'] : '' ),
			'bring'       => sanitize_textarea_field( isset( $in['bring'] ) ? $in['bring'] : '' ),
			'deadline'    => absint( isset( $in['deadline'] ) ? $in['deadline'] : 0 ),
			'max_per'     => max( 1, absint( isset( $in['max_per'] ) ? $in['max_per'] : 1 ) ),
			'open'        => empty( $in['open'] ) ? 0 : 1,
		);
		update_post_meta( $post_id, '_lat', $data );
		// Separate key used for sorting and "upcoming" queries.
		update_post_meta( $post_id, '_lat_start', $date ? $date . ' ' . $data['start'] : '' );

		// More places may free the waitlist.
		LAT_Registrations::promote_waitlist( $post_id );
	}

	public static function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				continue;
			}
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['lat_when']   = 'Data';
				$new['lat_places'] = 'Locuri';
			}
		}
		return $new;
	}

	public static function column( $column, $post_id ) {
		$m = LAT_Helpers::ws_meta( $post_id );
		if ( 'lat_when' === $column ) {
			echo $m['date'] ? esc_html( LAT_Helpers::date_label( $m['date'] ) . ', ' . $m['start'] . '–' . $m['end'] ) : '—';
			if ( empty( $m['open'] ) ) {
				echo '<br><em>Înscrieri oprite</em>';
			}
		} elseif ( 'lat_places' === $column ) {
			$taken = LAT_Helpers::places_taken( $post_id );
			$wait  = LAT_Helpers::waitlist_count( $post_id );
			$pct   = min( 100, round( $taken / max( 1, (int) $m['capacity'] ) * 100 ) );
			printf(
				'<a href="%s">%d / %d</a>%s<div class="lat-bar"><span style="width:%d%%"></span></div>',
				esc_url( admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_WS . '&page=lat-participants&workshop=' . $post_id ) ),
				(int) $taken,
				(int) $m['capacity'],
				$wait ? ' · ' . (int) $wait . ' în așteptare' : '',
				(int) $pct
			);
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
			// OR + NOT EXISTS keeps workshops that have no date yet in the list.
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

	public static function duplicate() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'lat_dup_' . $id );
		$post = get_post( $id );
		if ( ! $post || LAT_Helpers::CPT_WS !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'Atelierul nu a fost găsit.' );
		}
		$new = wp_insert_post(
			array(
				'post_type'    => LAT_Helpers::CPT_WS,
				'post_status'  => 'draft',
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
			)
		);
		$meta         = LAT_Helpers::ws_meta( $id );
		$meta['date'] = '';
		update_post_meta( $new, '_lat', $meta );
		update_post_meta( $new, '_lat_start', '' );
		$thumb = get_post_thumbnail_id( $id );
		if ( $thumb ) {
			set_post_thumbnail( $new, $thumb );
		}
		wp_set_object_terms( $new, wp_get_object_terms( $id, LAT_Helpers::TAX, array( 'fields' => 'ids' ) ), LAT_Helpers::TAX );
		wp_safe_redirect( admin_url( 'post.php?post=' . $new . '&action=edit' ) );
		exit;
	}
}
