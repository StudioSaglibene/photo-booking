<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Public booking form: [prenotazione pacchetto="id|slug" categoria="natale"].
 */
class Booking_Form {

	private static $errors = array();
	private static $values = array();

	public static function init() {
		add_shortcode( 'prenotazione', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'handle_post' ), 30 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style( 'pb-front', PB_URL . 'assets/css/booking.css', array(), PB_VERSION );
		wp_add_inline_style( 'pb-front', '.pb-booking,.pb-account{--pb-accent:' . ( sanitize_hex_color( Settings::get( 'accent_color' ) ) ?: '#8a6d4b' ) . ';}' );
		wp_register_script( 'pb-picker', PB_URL . 'assets/js/slot-picker.js', array(), PB_VERSION, true );
		wp_register_script( 'pb-booking', PB_URL . 'assets/js/booking.js', array( 'pb-picker' ), PB_VERSION, true );
		wp_localize_script( 'pb-picker', 'PB', self::js_config() );
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			wp_enqueue_style( 'pb-front' );
		}
	}

	public static function js_config() {
		return array(
			'rest'     => esc_url_raw( rest_url( Rest::NS ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'currency' => html_entity_decode( get_woocommerce_currency_symbol() ),
			'decimals' => wc_get_price_decimals(),
			'i18n'     => array(
				'months'   => array( 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre' ),
				'days'     => array( 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom' ),
				'noSlots'  => __( 'Nessun orario disponibile in questa data.', 'photo-booking' ),
				'noDays'   => __( 'Nessuna data disponibile in questo mese.', 'photo-booking' ),
				'loading'  => __( 'Caricamento…', 'photo-booking' ),
				'error'    => __( 'Errore di connessione, riprova.', 'photo-booking' ),
				'prev'     => __( 'Mese precedente', 'photo-booking' ),
				'next'     => __( 'Mese successivo', 'photo-booking' ),
				'choose'   => __( 'Scegli', 'photo-booking' ),
				'required' => __( 'Compila questo passaggio per continuare.', 'photo-booking' ),
			),
		);
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'pacchetto' => '',
				'categoria' => '',
			),
			$atts,
			'prenotazione'
		);
		wp_enqueue_style( 'pb-front' );
		wp_enqueue_script( 'pb-booking' );

		$packages = array();
		if ( $atts['pacchetto'] ) {
			foreach ( array_map( 'trim', explode( ',', $atts['pacchetto'] ) ) as $ref ) {
				$p = is_numeric( $ref ) ? wc_get_product( (int) $ref ) : self::product_by_slug( $ref );
				if ( $p && Package::is_package( $p ) && 'publish' === get_post_status( $p->get_id() ) ) {
					$packages[] = $p;
				}
			}
		} else {
			$packages = Package::all( $atts['categoria'] );
		}
		if ( ! $packages ) {
			return '<p class="pb-empty">' . esc_html__( 'Al momento non ci sono pacchetti prenotabili.', 'photo-booking' ) . '</p>';
		}

		$selected = absint( self::$values['product_id'] ?? ( $_GET['pacchetto'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 1 === count( $packages ) ) {
			$selected = $packages[0]->get_id();
		}
		$items = array();
		foreach ( $packages as $p ) {
			$cfg              = Package::config( $p );
			$cfg['remaining'] = Package::remaining_places( $p->get_id() );
			$cfg['first']     = Availability::first_month( $p->get_id() );
			$cfg['image']     = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'medium_large' ) : '';
			$cfg['excerpt']   = $p->get_short_description();
			$items[]          = $cfg;
		}

		$user   = wp_get_current_user();
		$values = wp_parse_args(
			self::$values,
			array(
				'first_name'   => $user->ID ? $user->first_name : '',
				'last_name'    => $user->ID ? $user->last_name : '',
				'email'        => $user->ID ? $user->user_email : '',
				'phone'        => $user->ID ? get_user_meta( $user->ID, 'billing_phone', true ) : '',
				'participants' => '',
				'children'     => '',
				'notes'        => '',
				'mode'         => 'deposit',
				'start'        => '',
			)
		);
		$credit = $user->ID ? Payments::credit_total( $user->ID ) : 0;

		return pb_get_template_html(
			'booking-form.php',
			array(
				'packages' => $items,
				'selected' => $selected,
				'values'   => $values,
				'errors'   => self::$errors,
				'credit'   => $credit,
			)
		);
	}

	private static function product_by_slug( $slug ) {
		$post = get_page_by_path( sanitize_title( $slug ), OBJECT, 'product' );
		return $post ? wc_get_product( $post->ID ) : null;
	}

	/**
	 * Form submission: validate, hold the slot, put it in the cart, go to checkout.
	 */
	public static function handle_post() {
		if ( empty( $_POST['pb_action'] ) || 'book' !== $_POST['pb_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! isset( $_POST['pb_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pb_nonce'] ), 'pb_book' ) ) {
			self::$errors[] = __( 'La sessione è scaduta, ricarica la pagina e riprova.', 'photo-booking' );
			return;
		}
		$v = array(
			'product_id'   => absint( $_POST['product_id'] ?? 0 ),
			'start'        => sanitize_text_field( wp_unslash( $_POST['start'] ?? '' ) ),
			'mode'         => 'full' === ( $_POST['mode'] ?? '' ) ? 'full' : 'deposit',
			'first_name'   => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
			'last_name'    => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ),
			'email'        => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone'        => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'participants' => sanitize_text_field( wp_unslash( $_POST['participants'] ?? '' ) ),
			'children'     => sanitize_text_field( wp_unslash( $_POST['children'] ?? '' ) ),
			'notes'        => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
		);
		self::$values = $v;

		if ( ! Package::is_package( $v['product_id'] ) ) {
			self::$errors[] = __( 'Scegli un pacchetto.', 'photo-booking' );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v['start'] ) ) {
			self::$errors[] = __( 'Scegli data e orario.', 'photo-booking' );
		}
		if ( '' === $v['first_name'] || '' === $v['last_name'] ) {
			self::$errors[] = __( 'Inserisci nome e cognome.', 'photo-booking' );
		}
		if ( ! is_email( $v['email'] ) ) {
			self::$errors[] = __( 'Inserisci un indirizzo email valido.', 'photo-booking' );
		}
		if ( strlen( preg_replace( '/\D/', '', $v['phone'] ) ) < 6 ) {
			self::$errors[] = __( 'Inserisci un numero di telefono valido.', 'photo-booking' );
		}
		if ( empty( $_POST['accept_policy'] ) ) {
			self::$errors[] = __( 'Devi accettare le condizioni di prenotazione e disdetta.', 'photo-booking' );
		}
		if ( self::$errors ) {
			return;
		}
		// Rate limit: max 10 holds per IP in 10 minutes.
		$ip  = md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$cnt = (int) get_transient( 'pb_hold_' . $ip );
		if ( $cnt > 10 ) {
			self::$errors[] = __( 'Troppi tentativi, riprova tra qualche minuto.', 'photo-booking' );
			return;
		}
		set_transient( 'pb_hold_' . $ip, $cnt + 1, 10 * MINUTE_IN_SECONDS );

		$appt = Bookings::create_hold(
			$v['product_id'],
			$v['start'],
			array(
				'name'  => trim( $v['first_name'] . ' ' . $v['last_name'] ),
				'email' => $v['email'],
				'phone' => $v['phone'],
				'notes' => $v['notes'],
				'meta'  => array(
					'participants' => $v['participants'],
					'children'     => $v['children'],
				),
			),
			$v['mode']
		);
		if ( is_wp_error( $appt ) ) {
			self::$errors[] = $appt->get_error_message();
			self::$values['start'] = '';
			return;
		}

		if ( ! WC()->session ) {
			WC()->initialize_session();
		}
		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		WC()->session->set( 'pb_customer', $v );
		if ( WC()->customer ) {
			if ( ! WC()->customer->get_billing_first_name() ) {
				WC()->customer->set_billing_first_name( $v['first_name'] );
				WC()->customer->set_billing_last_name( $v['last_name'] );
			}
			if ( ! WC()->customer->get_billing_email() ) {
				WC()->customer->set_billing_email( $v['email'] );
			}
			if ( ! WC()->customer->get_billing_phone() ) {
				WC()->customer->set_billing_phone( $v['phone'] );
			}
			WC()->customer->save();
		}

		$key = Cart::add_booking( $appt );
		if ( ! $key ) {
			$appt->update( array( 'status' => Appointment::EXPIRED ) );
			self::$errors[] = __( 'Non è stato possibile procedere al pagamento. Riprova.', 'photo-booking' );
			return;
		}
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}
}
