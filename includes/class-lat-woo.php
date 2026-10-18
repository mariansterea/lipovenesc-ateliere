<?php
/**
 * WooCommerce bridge: one hidden product per paid event, an order created straight
 * from the sign-up form, payment on WooCommerce's "pay for order" page (Stripe or any
 * other gateway), and order status → registration status.
 *
 * The plugin never touches card data; WooCommerce and the gateway do.
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'ABSPATH' ) || exit;

class LAT_Woo {

	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ) );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_paid' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_cancelled' ) );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'on_failed' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_cancelled' ) );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'not_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_thankyou_order_received_text', array( __CLASS__, 'thankyou' ), 10, 2 );
		add_filter( 'woocommerce_order_item_name', array( __CLASS__, 'item_name' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'admin_order_link' ) );
	}

	private static function registration_of_order( $order ) {
		$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		if ( ! $order ) {
			return 0;
		}
		return (int) $order->get_meta( '_lat_registration' );
	}

	/**
	 * Creates / updates the hidden product behind a paid event. Called on event save.
	 */
	public static function sync_product( $ws_id ) {
		if ( ! LAT_Helpers::woo_active() ) {
			return 0;
		}
		$m = LAT_Helpers::ws_meta( $ws_id );
		if ( 'buy' !== $m['mode_eff'] ) {
			return (int) $m['product_id'];
		}
		$product = $m['product_id'] ? wc_get_product( (int) $m['product_id'] ) : null;
		if ( ! $product ) {
			$product = new WC_Product_Simple();
		}
		$title = get_the_title( $ws_id ) . ( $m['date'] ? ' · ' . LAT_Helpers::date_label( $m['date'], false ) : '' );
		$price = LAT_Helpers::num( $m['price_adult'] );
		if ( null === $price || $price <= 0 ) {
			$price = LAT_Helpers::num( $m['price_child'] );
		}
		$product->set_name( $title );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( false );
		$product->set_manage_stock( false );
		$product->set_regular_price( (string) ( $price ? $price : 0 ) );
		$product->set_reviews_allowed( false );
		$product->update_meta_data( '_lat_event', (int) $ws_id );
		$image = get_post_thumbnail_id( $ws_id );
		if ( $image ) {
			$product->set_image_id( $image );
		}
		$pid = $product->save();
		if ( $pid && (int) $m['product_id'] !== (int) $pid ) {
			$raw               = LAT_Helpers::ws_raw( $ws_id );
			$raw['product_id'] = (int) $pid;
			update_post_meta( $ws_id, '_lat', $raw );
		}
		return (int) $pid;
	}

	/**
	 * Order for a pending registration. Returns the payment URL or WP_Error.
	 */
	public static function create_order( $reg_id ) {
		if ( ! LAT_Helpers::woo_active() ) {
			return new WP_Error( 'lat_woo', 'Plata online nu este disponibilă.' );
		}
		$r = LAT_Registrations::get( $reg_id );
		if ( ! $r ) {
			return new WP_Error( 'lat_woo', 'Înscrierea nu a fost găsită.' );
		}
		$m   = LAT_Helpers::ws_meta( $r['workshop'] );
		$pid = self::sync_product( $r['workshop'] );
		$pr  = $pid ? wc_get_product( $pid ) : null;
		if ( ! $pr ) {
			return new WP_Error( 'lat_woo', 'Produsul evenimentului nu a putut fi creat.' );
		}
		$pa = (float) LAT_Helpers::num( $m['price_adult'] );
		$pc = '' === trim( (string) $m['price_child'] ) ? $pa : (float) LAT_Helpers::num( $m['price_child'] );

		try {
			$order = wc_create_order(
				array(
					'status'      => 'pending',
					'created_via' => 'lipovenesc-ateliere',
				)
			);
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			$lines = array(
				array( 'Adult', $r['adults'], $pa ),
				array( 'Copil', $r['children'], $pc ),
			);
			foreach ( $lines as $l ) {
				if ( $l[1] < 1 || $l[2] <= 0 ) {
					continue;
				}
				$item = new WC_Order_Item_Product();
				$item->set_product( $pr );
				$item->set_quantity( (int) $l[1] );
				$item->set_subtotal( $l[2] * $l[1] );
				$item->set_total( $l[2] * $l[1] );
				$item->set_name( get_the_title( $r['workshop'] ) . ' – ' . $l[0] );
				$item->add_meta_data( 'Data', LAT_Helpers::date_label( $m['date'] ) . ', ' . $m['start'], true );
				$item->add_meta_data( 'Bilet', $l[0], true );
				$order->add_item( $item );
			}
			$parts = preg_split( '/\s+/', trim( $r['name'] ), 2 );
			$order->set_billing_first_name( $parts[0] );
			$order->set_billing_last_name( isset( $parts[1] ) ? $parts[1] : '' );
			$order->set_billing_email( $r['email'] );
			$order->set_billing_phone( $r['phone'] );
			$order->set_billing_country( 'RO' );
			$order->set_customer_note( $r['notes'] );
			$order->update_meta_data( '_lat_registration', (int) $reg_id );
			$order->update_meta_data( '_lat_event', (int) $r['workshop'] );
			$order->set_customer_id( get_current_user_id() );
			$order->calculate_totals( false );
			$order->add_order_note( 'Bilete eveniment: ' . get_the_title( $r['workshop'] ) . ' (' . LAT_Helpers::people_label( $r['adults'], $r['children'] ) . '). Înscriere #' . (int) $reg_id . '.' );
			$order->save();
		} catch ( Exception $e ) {
			return new WP_Error( 'lat_woo', 'Comanda nu a putut fi creată.' );
		}

		update_post_meta( $reg_id, '_lat_order_id', $order->get_id() );
		return $order->get_checkout_payment_url();
	}

	public static function on_paid( $order_id ) {
		$reg = self::registration_of_order( $order_id );
		if ( $reg ) {
			LAT_Registrations::mark_paid( $reg, $order_id );
		}
	}

	public static function on_cancelled( $order_id ) {
		$reg = self::registration_of_order( $order_id );
		if ( ! $reg ) {
			return;
		}
		$r = LAT_Registrations::get( $reg );
		if ( $r && in_array( $r['status'], array( 'pending', 'confirmed' ), true ) ) {
			LAT_Registrations::set_status( $reg, 'cancelled', 'pending' === $r['status'] );
		}
	}

	public static function on_failed( $order_id ) {
		// A failed attempt keeps the hold; the person can retry from the same link until it expires.
		$reg = self::registration_of_order( $order_id );
		if ( $reg ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note( 'Plata a eșuat; locul rămâne rezervat până la expirarea termenului.' );
			}
		}
	}

	public static function cancel_order( $order_id ) {
		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order && $order->has_status( array( 'pending', 'on-hold', 'failed' ) ) ) {
			remove_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_cancelled' ) );
			$order->update_status( 'cancelled', 'Termenul de plată a expirat sau înscrierea a fost anulată.' );
			add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_cancelled' ) );
		}
	}

	/**
	 * Full refund through the gateway when the farm cancels an event.
	 */
	public static function refund_order( $order_id, $reason = '' ) {
		if ( ! $order_id || ! function_exists( 'wc_create_refund' ) ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() || $order->get_total_refunded() >= $order->get_total() ) {
			return false;
		}
		remove_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_cancelled' ) );
		$refund = wc_create_refund(
			array(
				'amount'         => $order->get_total() - $order->get_total_refunded(),
				'reason'         => $reason,
				'order_id'       => $order_id,
				'refund_payment' => true,
				'restock_items'  => false,
			)
		);
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_cancelled' ) );
		if ( is_wp_error( $refund ) ) {
			$order->add_order_note( 'Rambursarea automată nu a reușit (' . $refund->get_error_message() . '). Rambursați manual din comandă.' );
			return false;
		}
		return true;
	}

	public static function not_purchasable( $purchasable, $product ) {
		if ( $product && $product->get_meta( '_lat_event' ) ) {
			return false;
		}
		return $purchasable;
	}

	public static function thankyou( $text, $order ) {
		if ( ! $order ) {
			return $text;
		}
		$reg = self::registration_of_order( $order );
		if ( ! $reg ) {
			return $text;
		}
		$r = LAT_Registrations::get( $reg );
		if ( ! $r ) {
			return $text;
		}
		if ( $order->is_paid() ) {
			return 'Mulțumim! Biletele sunt confirmate și le-ați primit și pe email. <a class="lat-btn" href="' . esc_url( LAT_Helpers::ticket_url( $r['token'] ) ) . '">Vezi biletul</a>';
		}
		return 'Comanda a fost înregistrată. Locurile rămân rezervate până la confirmarea plății; biletul se trimite pe email imediat după.';
	}

	public static function item_name( $name, $item ) {
		return $name;
	}

	public static function admin_order_link( $order ) {
		$reg = self::registration_of_order( $order );
		if ( $reg ) {
			echo '<p class="form-field form-field-wide"><strong>Eveniment:</strong> <a href="' . esc_url( admin_url( 'post.php?post=' . $reg . '&action=edit' ) ) . '">înscrierea #' . (int) $reg . '</a></p>';
		}
	}
}
