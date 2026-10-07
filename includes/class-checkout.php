<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout WooCommerce ridotto all'essenziale e con la grafica della pagina Prenota:
 * dati del cliente (nome, cognome, email, telefono), login o creazione account con password, riepilogo, pagamento.
 */
class Checkout {

	public static function init() {
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'fields' ), 50 );
		add_filter( 'woocommerce_enable_order_notes_field', '__return_false', 50 );
		add_filter( 'woocommerce_cart_needs_shipping_address', '__return_false', 50 );
		add_action( 'init', array( __CLASS__, 'remove_coupon_form' ) );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'intro' ), 5 );
		add_action( 'woocommerce_checkout_before_customer_details', array( __CLASS__, 'logged_in_note' ) );
		add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'button_text' ) );
		add_filter( 'gettext', array( __CLASS__, 'texts' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
	}

	private static function is_checkout_form() {
		return function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && ! is_wc_endpoint_url( 'order-pay' );
	}

	/** Only what the studio needs: name, email, phone. Country stays (hidden, Italy) because the payment gateway reads it. */
	public static function fields( $fields ) {
		if ( ! Cart::cart_has_booking() ) {
			return $fields;
		}
		$keep = array( 'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone', 'billing_country' );
		foreach ( $fields['billing'] as $key => $f ) {
			if ( ! in_array( $key, $keep, true ) ) {
				unset( $fields['billing'][ $key ] );
			}
		}
		unset( $fields['shipping'] );
		unset( $fields['order']['order_comments'] );
		$fields['billing']['billing_first_name']['priority'] = 10;
		$fields['billing']['billing_last_name']['priority']  = 20;
		$fields['billing']['billing_phone']['priority']      = 30;
		$fields['billing']['billing_email']['priority']      = 40;
		$fields['billing']['billing_first_name']['class'] = array( 'form-row-first' );
		$fields['billing']['billing_last_name']['class']  = array( 'form-row-last' );
		$fields['billing']['billing_phone']['class']      = array( 'form-row-first' );
		$fields['billing']['billing_email']['class']      = array( 'form-row-last' );
		$fields['billing']['billing_phone']['required']   = true;
		$fields['billing']['billing_country']['type']     = 'hidden';
		$fields['billing']['billing_country']['default']  = 'IT';
		$fields['billing']['billing_country']['class']    = array( 'pb-hidden' );
		$fields['billing']['billing_country']['label']    = '';
		if ( isset( $fields['account']['account_password'] ) ) {
			$fields['account']['account_password']['label']       = __( 'Scegli una password', 'photo-booking' );
			$fields['account']['account_password']['placeholder'] = '••••••••';
		}
		return $fields;
	}

	public static function remove_coupon_form() {
		remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
	}

	public static function intro() {
		if ( ! Cart::cart_has_booking() ) {
			return;
		}
		echo '<div class="pb-co-intro"><p class="pb-eyebrow">' . esc_html__( 'Ultimo passo', 'photo-booking' ) . '</p><h2 class="pb-title">' . esc_html__( 'Conferma e paga.', 'photo-booking' ) . '</h2><p class="pb-lead">' . esc_html__( 'L\'orario resta riservato per te durante il pagamento. Dopo la conferma troverai tutto nella tua area personale.', 'photo-booking' ) . '</p></div>';
	}

	public static function logged_in_note() {
		if ( ! Cart::cart_has_booking() || ! is_user_logged_in() ) {
			return;
		}
		$user = wp_get_current_user();
		echo '<p class="pb-co-user">' . esc_html( sprintf( /* translators: %s email */ __( 'Stai prenotando come %s.', 'photo-booking' ), $user->user_email ) ) . ' <a href="' . esc_url( wp_logout_url( wc_get_checkout_url() ) ) . '">' . esc_html__( 'Non sei tu?', 'photo-booking' ) . '</a></p>';
	}

	public static function button_text( $text ) {
		return Cart::cart_has_booking() ? __( 'Paga e prenota', 'photo-booking' ) : $text;
	}

	/** WooCommerce strings on the checkout, in the voice of the booking page. */
	public static function texts( $translated, $text, $domain ) {
		if ( 'woocommerce' !== $domain || ! self::is_checkout_form() ) {
			return $translated;
		}
		switch ( $text ) {
			case 'Billing details':
				return __( 'I tuoi dati', 'photo-booking' );
			case 'Your order':
				return __( 'Il tuo ordine', 'photo-booking' );
			case 'Returning customer?':
				return __( 'Hai già un account?', 'photo-booking' );
			case 'Click here to login':
				return __( 'Accedi qui', 'photo-booking' );
			case 'Create an account?':
				return __( 'Crea il tuo profilo', 'photo-booking' );
			case 'Create account password':
				return __( 'Scegli una password', 'photo-booking' );
			case 'If you have shopped with us before, please enter your details below. If you are a new customer, please proceed to the Billing section.':
				return __( 'Se hai già prenotato con noi, accedi con la tua email e la tua password: i tuoi dati verranno compilati da soli.', 'photo-booking' );
			case 'Username or email address':
			case 'Username or email':
				return __( 'Email', 'photo-booking' );
			case 'Remember me':
				return __( 'Ricordami', 'photo-booking' );
			case 'Lost your password?':
				return __( 'Password dimenticata?', 'photo-booking' );
			case 'Product':
				return __( 'Sessione', 'photo-booking' );
			case 'Subtotal':
				return __( 'Da pagare ora', 'photo-booking' );
			case 'Total':
				return __( 'Totale ora', 'photo-booking' );
		}
		return $translated;
	}

	public static function assets() {
		if ( ! self::is_checkout_form() || ! Cart::cart_has_booking() ) {
			return;
		}
		wp_enqueue_style( 'pb-checkout', PB_URL . 'assets/css/checkout.css', array( 'pb-flow-fonts' ), PB_VERSION );
		add_filter(
			'body_class',
			static function ( $classes ) {
				$classes[] = 'pb-checkout-page';
				return $classes;
			}
		);
	}
}
