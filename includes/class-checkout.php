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
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_cf' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_cf' ), 10, 2 );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'prefill_cf' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_cf' ) );
		add_action( 'woocommerce_review_order_after_submit', array( __CLASS__, 'seller_note' ) );
		add_shortcode( 'pb_venditore', array( __CLASS__, 'seller_shortcode' ) );
		add_filter( 'gettext', array( __CLASS__, 'texts' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		// Login box and "choose a password" at the checkout, whatever the WooCommerce settings say.
		add_filter( 'pre_option_woocommerce_enable_checkout_login_reminder', array( __CLASS__, 'yes' ) );
		add_filter( 'pre_option_woocommerce_enable_signup_and_login_from_checkout', array( __CLASS__, 'yes' ) );
		add_filter( 'pre_option_woocommerce_registration_generate_password', array( __CLASS__, 'no' ) );
		add_filter( 'option_woocommerce_stripe_settings', array( __CLASS__, 'stripe_defaults' ) );
	}

	/**
	 * Stripe: methods are chosen in WooCommerce → Pagamenti → Stripe (card only to start; Klarna & co. appear
	 * as soon as they are ticked there), never from the Stripe dashboard; no Apple/Google Pay express buttons.
	 */
	public static function stripe_defaults( $s ) {
		if ( ! is_array( $s ) ) {
			return $s;
		}
		$s['pmc_enabled']      = 'no';
		$s['express_checkout'] = 'no';
		if ( empty( $s['upe_checkout_experience_accepted_payments'] ) ) {
			$s['upe_checkout_experience_accepted_payments'] = array( 'card' );
		}
		return $s;
	}

	public static function yes() {
		return 'yes';
	}

	public static function no() {
		return 'no';
	}

	private static function is_checkout_form() {
		return function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && ! is_wc_endpoint_url( 'order-pay' );
	}

	/**
	 * Billing data required for the receipt/invoice (name, address, tax code) plus phone and email.
	 * Nothing else: no company, no shipping, no notes.
	 */
	public static function fields( $fields ) {
		if ( ! Cart::cart_has_booking() ) {
			return $fields;
		}
		$keep = array( 'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone', 'billing_country', 'billing_address_1', 'billing_postcode', 'billing_city', 'billing_state' );
		foreach ( $fields['billing'] as $key => $f ) {
			if ( ! in_array( $key, $keep, true ) ) {
				unset( $fields['billing'][ $key ] );
			}
		}
		unset( $fields['shipping'] );
		unset( $fields['order']['order_comments'] );
		$order = array( 'billing_first_name' => 10, 'billing_last_name' => 20, 'billing_phone' => 30, 'billing_email' => 40, 'billing_address_1' => 50, 'billing_postcode' => 60, 'billing_city' => 70, 'billing_state' => 80, 'billing_country' => 90 );
		foreach ( $order as $key => $priority ) {
			if ( isset( $fields['billing'][ $key ] ) ) {
				$fields['billing'][ $key ]['priority'] = $priority;
				$fields['billing'][ $key ]['class']    = array( in_array( $key, array( 'billing_first_name', 'billing_phone', 'billing_postcode', 'billing_state' ), true ) ? 'form-row-first' : 'form-row-last' );
			}
		}
		$fields['billing']['billing_phone']['required']     = true;
		$fields['billing']['billing_address_1']['label']    = __( 'Indirizzo', 'photo-booking' );
		$fields['billing']['billing_address_1']['placeholder'] = __( 'Via e numero civico', 'photo-booking' );
		$fields['billing']['billing_address_1']['class']    = array( 'form-row-wide' );
		$fields['billing']['billing_state']['label']        = __( 'Provincia', 'photo-booking' );
		$fields['billing']['billing_country']['type']       = 'hidden';
		$fields['billing']['billing_country']['default']    = 'IT';
		$fields['billing']['billing_country']['class']      = array( 'pb-hidden' );
		$fields['billing']['billing_country']['label']      = '';
		$fields['billing']['billing_cf'] = array(
			'label'       => __( 'Codice fiscale', 'photo-booking' ),
			'placeholder' => __( 'Per la ricevuta (facoltativo)', 'photo-booking' ),
			'required'    => false,
			'class'       => array( 'form-row-wide' ),
			'priority'    => 85,
			'maxlength'   => 16,
		);
		if ( isset( $fields['account']['account_password'] ) ) {
			$fields['account']['account_password']['label']       = __( 'Scegli una password', 'photo-booking' );
			$fields['account']['account_password']['placeholder'] = '••••••••';
		}
		return $fields;
	}

	/** Tax code: basic format check, saved on the order and on the customer. */
	public static function validate_cf( $data, $errors ) {
		$cf = strtoupper( trim( (string) ( $data['billing_cf'] ?? '' ) ) );
		if ( '' !== $cf && ! preg_match( '/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $cf ) && ! preg_match( '/^[0-9]{11}$/', $cf ) ) {
			$errors->add( 'billing_cf', __( 'Il codice fiscale non sembra corretto: controlla i 16 caratteri.', 'photo-booking' ) );
		}
	}

	public static function save_cf( $order, $data ) {
		$cf = strtoupper( trim( (string) ( $data['billing_cf'] ?? '' ) ) );
		if ( '' !== $cf ) {
			$order->update_meta_data( '_billing_cf', $cf );
			if ( $order->get_customer_id() ) {
				update_user_meta( $order->get_customer_id(), 'billing_cf', $cf );
			}
		}
	}

	public static function prefill_cf( $value, $input ) {
		if ( 'billing_cf' === $input && ( null === $value || '' === $value ) && is_user_logged_in() ) {
			return get_user_meta( get_current_user_id(), 'billing_cf', true );
		}
		return $value;
	}

	public static function admin_cf( $order ) {
		$cf = $order->get_meta( '_billing_cf' );
		if ( $cf ) {
			echo '<p><strong>' . esc_html__( 'Codice fiscale', 'photo-booking' ) . ':</strong> ' . esc_html( $cf ) . '</p>';
		}
	}

	/** [pb_venditore] – seller identity block for the legal pages. */
	public static function seller_shortcode() {
		$s    = Settings::all();
		$rows = array(
			__( 'Titolare', 'photo-booking' )     => $s['business_name'],
			__( 'Sede', 'photo-booking' )         => $s['business_address'],
			__( 'Partita IVA', 'photo-booking' )  => $s['business_vat'],
			__( 'Email', 'photo-booking' )        => $s['business_email'],
			__( 'Telefono', 'photo-booking' )     => $s['business_phone'],
		);
		$out = '<p class="pb-seller">';
		foreach ( array_filter( $rows ) as $k => $v ) {
			$out .= '<strong>' . esc_html( $k ) . ':</strong> ' . esc_html( $v ) . '<br>';
		}
		return $out . '</p>';
	}

	/** Seller identity under the order button (required for distance selling). */
	public static function seller_note() {
		if ( ! Cart::cart_has_booking() ) {
			return;
		}
		$s     = Settings::all();
		$lines = array_filter(
			array(
				$s['business_name'],
				$s['business_address'],
				$s['business_vat'] ? 'P. IVA ' . $s['business_vat'] : '',
				$s['business_email'],
			)
		);
		if ( ! $lines ) {
			return;
		}
		echo '<p class="pb-co-seller">' . esc_html__( 'Venditore', 'photo-booking' ) . ': ' . esc_html( implode( ' · ', $lines ) ) . '</p>';
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
