<?php
/**
 * WooCommerce product type "Pacchetto fotografico".
 *
 * @package PhotoBooking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Photo session package. Virtual, sold individually, bookable only through the booking form.
 */
class WC_Product_Pb_Package extends WC_Product_Simple {

	public function get_type() {
		return 'pb_package';
	}

	public function is_virtual() {
		return true;
	}

	public function is_sold_individually() {
		return true;
	}

	public function needs_shipping() {
		return false;
	}

	public function is_downloadable() {
		return false;
	}

	public function add_to_cart_url() {
		return apply_filters( 'woocommerce_product_add_to_cart_url', \PhotoBooking\Settings::booking_page_url( array( 'pacchetto' => $this->get_id() ) ), $this );
	}

	public function add_to_cart_text() {
		return apply_filters( 'woocommerce_product_add_to_cart_text', __( 'Prenota', 'photo-booking' ), $this );
	}

	public function single_add_to_cart_text() {
		return __( 'Prenota', 'photo-booking' );
	}

	public function supports( $feature ) {
		if ( 'ajax_add_to_cart' === $feature ) {
			return false;
		}
		return parent::supports( $feature );
	}
}
