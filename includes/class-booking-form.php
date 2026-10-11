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
		add_filter( 'apollo13framework_header_tools', array( __CLASS__, 'header_account_link' ) );
		add_action( 'template_redirect', array( __CLASS__, 'hide_shop' ), 1 );
		add_filter( 'wp_sitemaps_post_types', array( __CLASS__, 'sitemap_no_products' ) );
		add_filter( 'wp_sitemaps_taxonomies', array( __CLASS__, 'sitemap_no_product_taxonomies' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots_noindex_shop' ), 99 );
	}

	/**
	 * The shop, the product pages, categories and the cart have no role here: everything goes through the
	 * booking page. Permanent redirects, so search engines drop them.
	 */
	public static function hide_shop() {
		if ( ! function_exists( 'is_shop' ) || is_admin() ) {
			return;
		}
		if ( is_product() ) {
			$args = Package::is_package( get_queried_object_id() ) ? array( 'pacchetto' => get_queried_object_id() ) : array();
			wp_safe_redirect( Settings::booking_page_url( $args ), 301 );
			exit;
		}
		if ( is_checkout() || is_account_page() ) {
			return; // never touch the payment and account pages
		}
		if ( is_shop() || is_product_taxonomy() || is_cart() || is_post_type_archive( 'product' ) ) {
			wp_safe_redirect( Settings::booking_page_url(), 301 );
			exit;
		}
	}

	public static function sitemap_no_products( $types ) {
		unset( $types['product'] );
		return $types;
	}

	public static function sitemap_no_product_taxonomies( $taxonomies ) {
		unset( $taxonomies['product_cat'], $taxonomies['product_tag'] );
		return $taxonomies;
	}

	/** Checkout, account and order pages are personal: never in the search results. */
	public static function robots_noindex_shop( $robots ) {
		if ( function_exists( 'is_checkout' ) && ( is_checkout() || is_account_page() || is_cart() ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['follow'] );
		}
		return $robots;
	}

	/** "Accedi" / "Area riservata" link next to the Prenota button in the theme header (Rife). */
	public static function header_account_link( $html ) {
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			return $html;
		}
		$url   = wc_get_page_permalink( 'myaccount' );
		$label = is_user_logged_in() ? __( 'Area riservata', 'photo-booking' ) : __( 'Accedi', 'photo-booking' );
		return $html . '<a class="pb-account-link" href="' . esc_url( $url ) . '"><i class="pb-account-icon fa fa-user-o" aria-hidden="true"></i>' . esc_html( $label ) . '</a>';
	}

	public static function register_assets() {
		wp_register_style( 'pb-front', PB_URL . 'assets/css/booking.css', array(), pb_asset_ver( 'assets/css/booking.css' ) );
		wp_add_inline_style( 'pb-front', '.pb-booking,.pb-account{--pb-accent:' . ( sanitize_hex_color( Settings::get( 'accent_color' ) ) ?: '#8a6d4b' ) . ';}' );
		wp_register_script( 'pb-picker', PB_URL . 'assets/js/slot-picker.js', array(), pb_asset_ver( 'assets/js/slot-picker.js' ), true );
		wp_register_script( 'pb-booking', PB_URL . 'assets/js/booking.js', array( 'pb-picker' ), pb_asset_ver( 'assets/js/booking.js' ), true );
		wp_register_style( 'pb-flow-fonts', PB_URL . 'assets/css/fonts.css', array(), pb_asset_ver( 'assets/css/fonts.css' ) );
		// Elementor would load "Italiana" from Google: we serve it locally (privacy, no external calls).
		add_filter( 'elementor/frontend/print_google_fonts', '__return_false' );
		wp_enqueue_style( 'pb-flow-fonts' );
		wp_register_style( 'pb-flow', PB_URL . 'assets/css/booking-flow.css', array( 'pb-flow-fonts' ), pb_asset_ver( 'assets/css/booking-flow.css' ) );
		wp_register_style( 'pb-toast', PB_URL . 'assets/css/toast.css', array(), pb_asset_ver( 'assets/css/toast.css' ) );
		wp_register_script( 'pb-toast', PB_URL . 'assets/js/toast.js', array( 'jquery' ), pb_asset_ver( 'assets/js/toast.js' ), true );
		wp_register_script( 'pb-flow', PB_URL . 'assets/js/booking-flow.js', array( 'pb-picker', 'pb-toast' ), pb_asset_ver( 'assets/js/booking-flow.js' ), true );
		if ( self::is_booking_page() || ( function_exists( 'is_account_page' ) && ( is_account_page() || is_checkout() ) ) ) {
			wp_enqueue_style( 'pb-toast' );
			wp_enqueue_script( 'pb-toast' );
		}
		wp_enqueue_style( 'pb-header', PB_URL . 'assets/css/header.css', array(), pb_asset_ver( 'assets/css/header.css' ) );
		wp_localize_script( 'pb-picker', 'PB', self::js_config() );
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			wp_enqueue_style( 'pb-front' );
		}
		if ( self::is_booking_page() ) {
			wp_enqueue_style( 'pb-flow' );
			wp_enqueue_script( 'pb-flow' );
			add_filter(
				'body_class',
				static function ( $classes ) {
					$classes[] = 'pb-booking-page';
					return $classes;
				}
			);
		}
	}

	/** Booking page plus the WooCommerce pages the flow passes through (checkout, account): same light header. */
	public static function is_booking_page() {
		$id = (int) Settings::get( 'booking_page_id' );
		if ( $id && is_page( $id ) ) {
			return true;
		}
		if ( function_exists( 'is_checkout' ) && ( is_checkout() || is_account_page() || is_cart() ) ) {
			return true;
		}
		// Legal pages have no hero image: same light header.
		return is_page( array( 'privacy-policy', 'cookie-policy', 'condizioni-di-vendita' ) );
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
		wp_enqueue_style( 'pb-flow' );
		wp_enqueue_script( 'pb-flow' );

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
				'tier'         => sanitize_key( wp_unslash( $_GET['pacchetto_tipo'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
				'extras'       => array(),
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
				'credit'    => $credit,
				'logged_in' => (bool) $user->ID,
				'login_url' => wp_login_url( Settings::booking_page_url() . '#prenota-flow' ),
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
			'tier'         => sanitize_key( wp_unslash( $_POST['tier'] ?? '' ) ),
			'extras'       => array_map( 'sanitize_key', (array) wp_unslash( $_POST['extras'] ?? array() ) ),
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
		if ( ! self::$errors ) {
			$quote = Package::quote( $v['product_id'], $v['tier'], $v['extras'] );
			if ( is_wp_error( $quote ) ) {
				self::$errors[] = $quote->get_error_message();
			}
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

		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), 'billing_phone', $v['phone'] );
		}

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
			$v['mode'],
			array(
				'tier'   => $v['tier'],
				'extras' => $v['extras'],
			)
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
