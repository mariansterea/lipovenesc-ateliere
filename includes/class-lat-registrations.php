<?php
/**
 * Registrations: post type, creation with capacity lock, pending (unpaid) holds,
 * waitlist, check-in, admin list and edit screen.
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'ABSPATH' ) || exit;

class LAT_Registrations {

	const FIELDS = array( 'workshop', 'status', 'adults', 'children', 'ages', 'name', 'phone', 'email', 'notes', 'total', 'token', 'source', 'reminded', 'order_id', 'hold_until', 'checkin', 'paid' );

	const CRON_HOLDS = 'lat_release_holds';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . LAT_Helpers::CPT_REG, array( __CLASS__, 'save_admin' ), 10, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'on_trash' ) );
		add_filter( 'manage_' . LAT_Helpers::CPT_REG . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . LAT_Helpers::CPT_REG . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filters' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'list_query' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_lat_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( self::CRON_HOLDS, array( __CLASS__, 'release_expired_holds' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOLDS ) ) {
			wp_schedule_event( time() + 120, 'lat_ten_minutes', self::CRON_HOLDS );
		}
	}

	public static function register() {
		register_post_type(
			LAT_Helpers::CPT_REG,
			array(
				'labels'          => array(
					'name'          => 'Înscrieri',
					'singular_name' => 'Înscriere',
					'all_items'     => 'Înscrieri',
					'add_new'       => 'Adaugă înscriere',
					'add_new_item'  => 'Adaugă înscriere (telefonică)',
					'edit_item'     => 'Editează înscrierea',
					'not_found'     => 'Nicio înscriere.',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . LAT_Helpers::CPT_WS,
				'supports'        => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	/* ---------- Data ---------- */

	public static function get( $id ) {
		$post = get_post( $id );
		if ( ! $post || LAT_Helpers::CPT_REG !== $post->post_type ) {
			return null;
		}
		$r = array( 'id' => (int) $id );
		foreach ( self::FIELDS as $f ) {
			$r[ $f ] = get_post_meta( $id, '_lat_' . $f, true );
		}
		foreach ( array( 'workshop', 'adults', 'children', 'order_id', 'hold_until', 'checkin', 'paid' ) as $f ) {
			$r[ $f ] = (int) $r[ $f ];
		}
		return $r;
	}

	private static function save_meta( $id, $r ) {
		foreach ( self::FIELDS as $f ) {
			if ( array_key_exists( $f, $r ) ) {
				update_post_meta( $id, '_lat_' . $f, $r[ $f ] );
			}
		}
		if ( isset( $r['name'] ) ) {
			$who = trim( $r['name'] ) . ' (' . LAT_Helpers::people_label( $r['adults'], $r['children'] ) . ')';
			remove_action( 'save_post_' . LAT_Helpers::CPT_REG, array( __CLASS__, 'save_admin' ), 10 );
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $who,
				)
			);
			add_action( 'save_post_' . LAT_Helpers::CPT_REG, array( __CLASS__, 'save_admin' ), 10, 2 );
		}
	}

	/**
	 * Serialises capacity checks per event (MySQL named lock).
	 */
	private static function with_lock( $workshop_id, $callback ) {
		global $wpdb;
		$name = 'lat_ws_' . (int) $workshop_id . '_' . $wpdb->prefix;
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		try {
			return $callback();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Public registration (reserve) or ticket purchase start (buy → pending + pay URL).
	 *
	 * @return array|WP_Error [ id, status, pay_url ].
	 */
	public static function create( $in ) {
		$ws_id = absint( isset( $in['workshop'] ) ? $in['workshop'] : 0 );
		$post  = get_post( $ws_id );
		if ( ! $post || LAT_Helpers::CPT_WS !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error( 'lat_ws', 'Evenimentul nu mai este disponibil.', array( 'status' => 404 ) );
		}
		$m     = LAT_Helpers::ws_meta( $ws_id );
		$state = LAT_Helpers::ws_state( $ws_id );
		if ( in_array( $state, array( 'past', 'closed', 'cancelled' ), true ) ) {
			return new WP_Error( 'lat_closed', 'Înscrierile pentru acest eveniment s-au închis.', array( 'status' => 400 ) );
		}

		$adults   = absint( isset( $in['adults'] ) ? $in['adults'] : 0 );
		$children = absint( isset( $in['children'] ) ? $in['children'] : 0 );
		$name     = sanitize_text_field( isset( $in['name'] ) ? $in['name'] : '' );
		$phone    = preg_replace( '/[^\d+ ]/', '', (string) ( isset( $in['phone'] ) ? $in['phone'] : '' ) );
		$email    = sanitize_email( isset( $in['email'] ) ? $in['email'] : '' );

		if ( $adults + $children < 1 ) {
			return new WP_Error( 'lat_people', 'Alegeți câte persoane vin.', array( 'status' => 400 ) );
		}
		if ( $adults + $children > (int) $m['max_per'] ) {
			return new WP_Error( 'lat_people', 'Pe o înscriere pot fi maximum ' . (int) $m['max_per'] . ' persoane. Pentru grupuri mai mari, sunați-ne.', array( 'status' => 400 ) );
		}
		if ( 'children' === $m['count'] && $children < 1 ) {
			return new WP_Error( 'lat_people', 'Evenimentul este pentru copii: adăugați cel puțin un copil.', array( 'status' => 400 ) );
		}
		if ( mb_strlen( $name ) < 3 ) {
			return new WP_Error( 'lat_name', 'Scrieți numele complet.', array( 'status' => 400 ) );
		}
		if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 9 ) {
			return new WP_Error( 'lat_phone', 'Numărul de telefon nu pare corect.', array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'lat_email', 'Adresa de email nu pare corectă.', array( 'status' => 400 ) );
		}
		if ( empty( $in['consent'] ) ) {
			return new WP_Error( 'lat_consent', 'Bifați acordul pentru prelucrarea datelor.', array( 'status' => 400 ) );
		}

		$total = LAT_Helpers::price_total( $m, $adults, $children );
		$buy   = 'buy' === $m['mode_eff'] && null !== $total && $total > 0;

		$r = array(
			'workshop'   => $ws_id,
			'adults'     => $adults,
			'children'   => $children,
			'ages'       => sanitize_text_field( isset( $in['ages'] ) ? $in['ages'] : '' ),
			'name'       => $name,
			'phone'      => trim( $phone ),
			'email'      => $email,
			'notes'      => sanitize_textarea_field( isset( $in['notes'] ) ? $in['notes'] : '' ),
			'total'      => $total,
			'token'      => wp_generate_password( 32, false, false ),
			'source'     => 'site',
			'reminded'   => 0,
			'order_id'   => 0,
			'hold_until' => 0,
			'checkin'    => 0,
			'paid'       => 0,
		);
		$need          = LAT_Helpers::places_for( $m, $adults, $children );
		$want_waitlist = ! empty( $in['waitlist'] ) && LAT_Helpers::get( 'waitlist' );

		$result = self::with_lock(
			$ws_id,
			function () use ( $ws_id, $need, $want_waitlist, $r, $buy ) {
				$left = LAT_Helpers::places_left( $ws_id );
				if ( $need <= $left ) {
					$r['status'] = $buy ? 'pending' : 'confirmed';
					if ( $buy ) {
						$r['hold_until'] = time() + max( 5, (int) LAT_Helpers::get( 'hold_minutes' ) ) * MINUTE_IN_SECONDS;
					}
				} elseif ( $want_waitlist ) {
					$r['status'] = 'waitlist';
				} else {
					$msg = $left > 0
						? 'Mai ' . ( 1 === $left ? 'este un singur loc liber' : 'sunt doar ' . $left . ' locuri libere' ) . '. Modificați numărul de persoane sau intrați pe lista de așteptare.'
						: 'Locurile s-au ocupat. Puteți intra pe lista de așteptare.';
					return new WP_Error( 'lat_full', $msg, array( 'status' => 409, 'left' => $left ) );
				}
				$id = wp_insert_post(
					array(
						'post_type'   => LAT_Helpers::CPT_REG,
						'post_status' => 'publish',
						'post_title'  => $r['name'],
					),
					true
				);
				if ( is_wp_error( $id ) || ! $id ) {
					return new WP_Error( 'lat_save', 'Înscrierea nu a putut fi salvată. Încercați din nou.', array( 'status' => 500 ) );
				}
				self::save_meta( $id, $r );
				return array(
					'id'     => $id,
					'status' => $r['status'],
				);
			}
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 'pending' === $result['status'] ) {
			$pay = LAT_Woo::create_order( $result['id'] );
			if ( is_wp_error( $pay ) ) {
				// Payment could not be prepared: fall back to a plain reservation so nobody is lost.
				update_post_meta( $result['id'], '_lat_status', 'confirmed' );
				update_post_meta( $result['id'], '_lat_hold_until', 0 );
				$result['status'] = 'confirmed';
				do_action( 'lat_registration_created', $result['id'] );
				return $result;
			}
			$result['pay_url'] = $pay;
			return $result;
		}

		do_action( 'lat_registration_created', $result['id'] );
		return $result;
	}

	public static function set_status( $id, $status, $silent = false ) {
		$r = self::get( $id );
		if ( ! $r || ! isset( LAT_Helpers::STATUS_LABELS[ $status ] ) || $r['status'] === $status ) {
			return false;
		}
		update_post_meta( $id, '_lat_status', $status );
		if ( 'pending' !== $status ) {
			update_post_meta( $id, '_lat_hold_until', 0 );
		}
		if ( ! $silent ) {
			do_action( 'lat_registration_status', $id, $status, $r['status'] );
		}
		if ( in_array( $r['status'], array( 'confirmed', 'pending' ), true ) && ! in_array( $status, array( 'confirmed', 'pending' ), true ) ) {
			self::promote_waitlist( $r['workshop'] );
		}
		return true;
	}

	/**
	 * Called by WooCommerce when the order is paid.
	 */
	public static function mark_paid( $id, $order_id ) {
		$r = self::get( $id );
		if ( ! $r ) {
			return;
		}
		update_post_meta( $id, '_lat_paid', 1 );
		update_post_meta( $id, '_lat_order_id', (int) $order_id );
		update_post_meta( $id, '_lat_hold_until', 0 );
		if ( 'pending' === $r['status'] ) {
			update_post_meta( $id, '_lat_status', 'confirmed' );
			do_action( 'lat_registration_created', $id );
		}
	}

	/**
	 * Pending holds whose time ran out: cancel quietly and free the places.
	 */
	public static function release_expired_holds() {
		$ids = get_posts(
			array(
				'post_type'   => LAT_Helpers::CPT_REG,
				'post_status' => 'publish',
				'numberposts' => 100,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_lat_status',
						'value' => 'pending',
					),
					array(
						'key'     => '_lat_hold_until',
						'value'   => time(),
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$r = self::get( $id );
			if ( $r && $r['hold_until'] > 0 ) {
				self::set_status( $id, 'cancelled', true );
				update_post_meta( $id, '_lat_notes', trim( $r['notes'] . "\nAnulată automat: plata nu a fost finalizată." ) );
				if ( class_exists( 'LAT_Woo' ) ) {
					LAT_Woo::cancel_order( $r['order_id'] );
				}
			}
		}
	}

	/**
	 * Cancels every live registration of an event (event cancelled by the farm).
	 */
	public static function cancel_all( $ws_id, $reason = 'event' ) {
		$ids = get_posts(
			array(
				'post_type'   => LAT_Helpers::CPT_REG,
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
						'value'   => array( 'confirmed', 'pending', 'waitlist' ),
						'compare' => 'IN',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$r = self::get( $id );
			update_post_meta( $id, '_lat_status', 'cancelled' );
			update_post_meta( $id, '_lat_hold_until', 0 );
			if ( $r['paid'] && $r['order_id'] && class_exists( 'LAT_Woo' ) ) {
				LAT_Woo::refund_order( $r['order_id'], 'Eveniment anulat de organizator' );
			} elseif ( $r['order_id'] && class_exists( 'LAT_Woo' ) ) {
				LAT_Woo::cancel_order( $r['order_id'] );
			}
			do_action( 'lat_registration_event_cancelled', $id, $reason );
		}
		return count( $ids );
	}

	public static function toggle_checkin( $id, $on = null ) {
		$r = self::get( $id );
		if ( ! $r ) {
			return false;
		}
		$new = null === $on ? ( $r['checkin'] ? 0 : time() ) : ( $on ? time() : 0 );
		update_post_meta( $id, '_lat_checkin', $new );
		return (bool) $new;
	}

	/**
	 * Moves waitlisted registrations (oldest first) into freed places.
	 */
	public static function promote_waitlist( $ws_id ) {
		if ( in_array( LAT_Helpers::ws_state( $ws_id ), array( 'past', 'cancelled' ), true ) ) {
			return;
		}
		$promoted = self::with_lock(
			$ws_id,
			function () use ( $ws_id ) {
				$m    = LAT_Helpers::ws_meta( $ws_id );
				$left = LAT_Helpers::places_left( $ws_id );
				$done = array();
				if ( $left < 1 ) {
					return $done;
				}
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
							array(
								'key'   => '_lat_status',
								'value' => 'waitlist',
							),
						),
					)
				);
				foreach ( $ids as $rid ) {
					$need = LAT_Helpers::places_for( $m, get_post_meta( $rid, '_lat_adults', true ), get_post_meta( $rid, '_lat_children', true ) );
					if ( $need <= $left ) {
						if ( 'buy' === $m['mode_eff'] && (float) get_post_meta( $rid, '_lat_total', true ) > 0 ) {
							// Paid events: the promoted person gets a hold and a payment link instead of a free seat.
							update_post_meta( $rid, '_lat_status', 'pending' );
							update_post_meta( $rid, '_lat_hold_until', time() + 24 * HOUR_IN_SECONDS );
						} else {
							update_post_meta( $rid, '_lat_status', 'confirmed' );
						}
						$left  -= $need;
						$done[] = $rid;
					}
				}
				return $done;
			}
		);
		foreach ( $promoted as $rid ) {
			do_action( 'lat_registration_promoted', $rid );
		}
	}

	public static function find_by_token( $token ) {
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', (string) $token ) ) {
			return null;
		}
		$ids = get_posts(
			array(
				'post_type'   => LAT_Helpers::CPT_REG,
				'post_status' => 'publish',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_lat_token', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $token, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $ids ? self::get( $ids[0] ) : null;
	}

	public static function on_trash( $post_id ) {
		if ( get_post_type( $post_id ) === LAT_Helpers::CPT_REG ) {
			$ws = (int) get_post_meta( $post_id, '_lat_workshop', true );
			if ( $ws ) {
				self::promote_waitlist( $ws );
			}
		}
	}

	/* ---------- Admin edit screen ---------- */

	public static function meta_boxes() {
		add_meta_box( 'lat_reg', 'Detalii înscriere', array( __CLASS__, 'box' ), LAT_Helpers::CPT_REG, 'normal', 'high' );
	}

	public static function box( $post ) {
		$r = self::get( $post->ID );
		if ( ! $r['status'] ) {
			$r['status'] = 'confirmed';
			$r['adults'] = 1;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $r['workshop'] && isset( $_GET['lat_ws'] ) ) {
			$r['workshop'] = absint( $_GET['lat_ws'] );
		}
		$workshops = get_posts(
			array(
				'post_type'   => LAT_Helpers::CPT_WS,
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => -1,
				'meta_key'    => '_lat_start', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'     => 'meta_value',
				'order'       => 'DESC',
			)
		);
		wp_nonce_field( 'lat_reg_save', 'lat_reg_nonce' );
		?>
		<table class="form-table">
			<tr>
				<th><label for="lat-r-ws">Eveniment</label></th>
				<td><select id="lat-r-ws" name="latr[workshop]">
					<?php foreach ( $workshops as $w ) : ?>
						<?php $wm = LAT_Helpers::ws_raw( $w->ID ); ?>
						<option value="<?php echo (int) $w->ID; ?>" <?php selected( $r['workshop'], $w->ID ); ?>><?php echo esc_html( get_the_title( $w ) . ' — ' . ( $wm['date'] ? LAT_Helpers::date_label( $wm['date'] ) . ' ' . $wm['start'] : 'fără dată' ) ); ?></option>
					<?php endforeach; ?>
				</select></td>
			</tr>
			<tr>
				<th>Participanți</th>
				<td><input type="number" min="0" class="small-text" name="latr[adults]" value="<?php echo (int) $r['adults']; ?>"> adulți &nbsp;
					<input type="number" min="0" class="small-text" name="latr[children]" value="<?php echo (int) $r['children']; ?>"> copii &nbsp;
					vârste copii <input type="text" class="regular-text" name="latr[ages]" value="<?php echo esc_attr( $r['ages'] ); ?>" placeholder="5, 8"></td>
			</tr>
			<tr>
				<th><label for="lat-r-name">Nume</label></th>
				<td><input type="text" id="lat-r-name" class="regular-text" name="latr[name]" value="<?php echo esc_attr( $r['name'] ); ?>" required></td>
			</tr>
			<tr>
				<th><label for="lat-r-phone">Telefon</label></th>
				<td><input type="tel" id="lat-r-phone" class="regular-text" name="latr[phone]" value="<?php echo esc_attr( $r['phone'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="lat-r-email">Email</label></th>
				<td><input type="email" id="lat-r-email" class="regular-text" name="latr[email]" value="<?php echo esc_attr( $r['email'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="lat-r-notes">Observații</label></th>
				<td><textarea id="lat-r-notes" rows="3" class="large-text" name="latr[notes]"><?php echo esc_textarea( $r['notes'] ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="lat-r-status">Status</label></th>
				<td><select id="lat-r-status" name="latr[status]">
					<?php foreach ( LAT_Helpers::STATUS_LABELS as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $r['status'], $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label style="margin-left:12px"><input type="checkbox" name="latr[paid]" value="1" <?php checked( $r['paid'] ); ?>> Plătit</label>
				<label style="margin-left:12px"><input type="checkbox" name="latr[checkin]" value="1" <?php checked( $r['checkin'] ); ?>> Prezent (check-in)</label>
				<?php if ( 'auto-draft' === $post->post_status ) : ?>
					<p><label><input type="checkbox" name="latr[send_email]" value="1"> Trimite participantului emailul de confirmare (cu bilet)</label></p>
				<?php endif; ?>
				</td>
			</tr>
			<?php if ( '' !== (string) $r['total'] && null !== $r['total'] ) : ?>
			<tr><th>Total</th><td><?php echo esc_html( LAT_Helpers::money( (float) $r['total'] ) ); ?><?php echo $r['order_id'] ? ' · <a href="' . esc_url( admin_url( 'post.php?post=' . (int) $r['order_id'] . '&action=edit' ) ) . '">comanda #' . (int) $r['order_id'] . '</a>' : ''; ?></td></tr>
			<?php endif; ?>
			<?php if ( $r['token'] ) : ?>
			<tr><th>Bilet</th><td><a href="<?php echo esc_url( LAT_Helpers::ticket_url( $r['token'] ) ); ?>" target="_blank" rel="noopener">Vezi biletul</a> · cod <?php echo esc_html( LAT_Helpers::ticket_code( $r['token'] ) ); ?></td></tr>
			<?php endif; ?>
		</table>
		<?php
	}

	public static function save_admin( $post_id, $post ) {
		if ( ! isset( $_POST['lat_reg_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lat_reg_nonce'] ) ), 'lat_reg_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$in     = isset( $_POST['latr'] ) && is_array( $_POST['latr'] ) ? wp_unslash( $_POST['latr'] ) : array();
		$old    = self::get( $post_id );
		$is_new = empty( $old['token'] );
		$status = isset( $in['status'] ) && isset( LAT_Helpers::STATUS_LABELS[ $in['status'] ] ) ? $in['status'] : 'confirmed';
		$ws_id  = absint( isset( $in['workshop'] ) ? $in['workshop'] : 0 );
		$m      = LAT_Helpers::ws_meta( $ws_id );

		$r = array(
			'workshop' => $ws_id,
			'adults'   => absint( isset( $in['adults'] ) ? $in['adults'] : 0 ),
			'children' => absint( isset( $in['children'] ) ? $in['children'] : 0 ),
			'ages'     => sanitize_text_field( isset( $in['ages'] ) ? $in['ages'] : '' ),
			'name'     => sanitize_text_field( isset( $in['name'] ) ? $in['name'] : '' ),
			'phone'    => preg_replace( '/[^\d+ ]/', '', (string) ( isset( $in['phone'] ) ? $in['phone'] : '' ) ),
			'email'    => sanitize_email( isset( $in['email'] ) ? $in['email'] : '' ),
			'notes'    => sanitize_textarea_field( isset( $in['notes'] ) ? $in['notes'] : '' ),
			'status'   => $status,
			'paid'     => empty( $in['paid'] ) ? 0 : 1,
			'checkin'  => empty( $in['checkin'] ) ? 0 : ( $old['checkin'] ? $old['checkin'] : time() ),
		);
		$r['total'] = LAT_Helpers::price_total( $m, $r['adults'], $r['children'] );
		if ( $is_new ) {
			$r['token']      = wp_generate_password( 32, false, false );
			$r['source']     = 'admin';
			$r['reminded']   = 0;
			$r['order_id']   = 0;
			$r['hold_until'] = 0;
		}
		if ( $old['workshop'] && $old['workshop'] !== $ws_id ) {
			$r['reminded'] = 0;
		}
		if ( 'pending' !== $status ) {
			$r['hold_until'] = 0;
		}

		if ( 'confirmed' === $status ) {
			$others = LAT_Helpers::places_taken( $ws_id ) - ( in_array( $old['status'], array( 'confirmed', 'pending' ), true ) && $old['workshop'] === $ws_id ? LAT_Helpers::places_for( $m, $old['adults'], $old['children'] ) : 0 );
			if ( $others + LAT_Helpers::places_for( $m, $r['adults'], $r['children'] ) > (int) $m['capacity'] ) {
				set_transient( 'lat_notice_' . get_current_user_id(), 'Atenție: evenimentul are acum mai mulți participanți decât locurile setate (' . (int) $m['capacity'] . ').', 60 );
			}
		}

		self::save_meta( $post_id, $r );
		remove_action( 'save_post_' . LAT_Helpers::CPT_REG, array( __CLASS__, 'save_admin' ), 10 );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
		add_action( 'save_post_' . LAT_Helpers::CPT_REG, array( __CLASS__, 'save_admin' ), 10, 2 );

		if ( $is_new && ! empty( $in['send_email'] ) ) {
			do_action( 'lat_registration_created', $post_id );
		} elseif ( ! $is_new && $old['status'] !== $status ) {
			do_action( 'lat_registration_status', $post_id, $status, $old['status'] );
		}
		if ( in_array( $old['status'], array( 'confirmed', 'pending' ), true ) && ( ! in_array( $status, array( 'confirmed', 'pending' ), true ) || $old['workshop'] !== $ws_id ) ) {
			self::promote_waitlist( $old['workshop'] );
		}
	}

	public static function notices() {
		$key    = 'lat_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['lat_done'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Înscrierea a fost actualizată.</p></div>';
		}
	}

	/* ---------- Admin list ---------- */

	public static function columns( $columns ) {
		return array(
			'cb'          => $columns['cb'],
			'title'       => 'Participant',
			'lat_contact' => 'Contact',
			'lat_ws'      => 'Eveniment',
			'lat_people'  => 'Persoane',
			'lat_total'   => 'Total',
			'lat_status'  => 'Status',
			'date'        => 'Înscris la',
		);
	}

	public static function column( $column, $post_id ) {
		$r = self::get( $post_id );
		switch ( $column ) {
			case 'lat_contact':
				if ( $r['phone'] ) {
					echo '<a href="tel:' . esc_attr( preg_replace( '/\s+/', '', $r['phone'] ) ) . '">' . esc_html( $r['phone'] ) . '</a><br>';
				}
				if ( $r['email'] ) {
					echo '<a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html( $r['email'] ) . '</a>';
				}
				break;
			case 'lat_ws':
				if ( $r['workshop'] ) {
					$m = LAT_Helpers::ws_raw( $r['workshop'] );
					echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_REG . '&lat_ws=' . $r['workshop'] ) ) . '">' . esc_html( get_the_title( $r['workshop'] ) ) . '</a><br><span class="lat-muted">' . esc_html( $m['date'] ? LAT_Helpers::date_label( $m['date'] ) . ' ' . $m['start'] : '' ) . '</span>';
				}
				break;
			case 'lat_people':
				echo esc_html( LAT_Helpers::people_label( $r['adults'], $r['children'] ) );
				if ( $r['ages'] ) {
					echo '<br><span class="lat-muted">vârste: ' . esc_html( $r['ages'] ) . '</span>';
				}
				if ( $r['notes'] ) {
					echo '<br><span class="lat-muted" title="' . esc_attr( $r['notes'] ) . '">📝 ' . esc_html( wp_trim_words( $r['notes'], 8 ) ) . '</span>';
				}
				break;
			case 'lat_total':
				echo '' !== (string) $r['total'] ? esc_html( LAT_Helpers::money( (float) $r['total'] ) ) : '—';
				if ( $r['paid'] ) {
					echo '<br><span class="lat-badge lat-badge--confirmed">plătit</span>';
				}
				if ( $r['order_id'] ) {
					echo '<br><a class="lat-muted" href="' . esc_url( admin_url( 'post.php?post=' . (int) $r['order_id'] . '&action=edit' ) ) . '">#' . (int) $r['order_id'] . '</a>';
				}
				break;
			case 'lat_status':
				$label = isset( LAT_Helpers::STATUS_LABELS[ $r['status'] ] ) ? LAT_Helpers::STATUS_LABELS[ $r['status'] ] : $r['status'];
				echo '<span class="lat-badge lat-badge--' . esc_attr( $r['status'] ) . '">' . esc_html( $label ) . '</span>';
				if ( $r['checkin'] ) {
					echo ' <span class="lat-badge lat-badge--checkin">prezent</span>';
				}
				if ( 'admin' === $r['source'] ) {
					echo '<br><span class="lat-muted">adăugată manual</span>';
				}
				break;
		}
	}

	public static function filters( $post_type ) {
		if ( LAT_Helpers::CPT_REG !== $post_type ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$ws     = isset( $_GET['lat_ws'] ) ? absint( $_GET['lat_ws'] ) : 0;
		$status = isset( $_GET['lat_status'] ) ? sanitize_key( $_GET['lat_status'] ) : '';
		// phpcs:enable
		$workshops = get_posts(
			array(
				'post_type'   => LAT_Helpers::CPT_WS,
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => -1,
				'meta_key'    => '_lat_start', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'     => 'meta_value',
				'order'       => 'DESC',
			)
		);
		echo '<select name="lat_ws"><option value="">Toate evenimentele</option>';
		foreach ( $workshops as $w ) {
			$m = LAT_Helpers::ws_raw( $w->ID );
			printf( '<option value="%d" %s>%s</option>', (int) $w->ID, selected( $ws, $w->ID, false ), esc_html( get_the_title( $w ) . ( $m['date'] ? ' — ' . wp_date( 'j M', strtotime( $m['date'] . ' 12:00' ) ) : '' ) ) );
		}
		echo '</select><select name="lat_status"><option value="">Toate statusurile</option>';
		foreach ( LAT_Helpers::STATUS_LABELS as $k => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $status, $k, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public static function list_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || LAT_Helpers::CPT_REG !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$meta = array();
		if ( ! empty( $_GET['lat_ws'] ) ) {
			$meta[] = array(
				'key'   => '_lat_workshop',
				'value' => absint( $_GET['lat_ws'] ),
			);
		}
		if ( ! empty( $_GET['lat_status'] ) ) {
			$meta[] = array(
				'key'   => '_lat_status',
				'value' => sanitize_key( $_GET['lat_status'] ),
			);
		}
		// phpcs:enable
		if ( $meta ) {
			$query->set( 'meta_query', $meta );
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( LAT_Helpers::CPT_REG !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'] );
		$status = get_post_meta( $post->ID, '_lat_status', true );
		$url    = function ( $to ) use ( $post ) {
			return wp_nonce_url( admin_url( 'admin-post.php?action=lat_status&id=' . $post->ID . '&to=' . $to ), 'lat_status_' . $post->ID );
		};
		if ( in_array( $status, array( 'waitlist', 'pending' ), true ) ) {
			$actions['lat_confirm'] = '<a href="' . esc_url( $url( 'confirmed' ) ) . '">Confirmă</a>';
		}
		if ( 'cancelled' !== $status ) {
			$actions['lat_cancel'] = '<a href="' . esc_url( $url( 'cancelled' ) ) . '" onclick="return confirm(\'Anulezi înscrierea? Participantul primește un email.\')">Anulează</a>';
		}
		$actions['lat_checkin'] = '<a href="' . esc_url( $url( 'checkin' ) ) . '">' . ( get_post_meta( $post->ID, '_lat_checkin', true ) ? 'Scoate prezența' : 'Marchează prezent' ) . '</a>';
		return $actions;
	}

	public static function handle_status() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'lat_status_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'Nu ai drepturi pentru această acțiune.' );
		}
		$to = isset( $_GET['to'] ) ? sanitize_key( $_GET['to'] ) : '';
		if ( 'checkin' === $to ) {
			self::toggle_checkin( $id );
		} else {
			self::set_status( $id, $to );
		}
		wp_safe_redirect( add_query_arg( 'lat_done', 1, wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_REG ) ) );
		exit;
	}
}
