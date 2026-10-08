<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Customer area (WooCommerce My Account): bookings + deliveries.
 */
class Account {

	const EP_BOOKINGS   = 'prenotazioni';
	const EP_DELIVERIES = 'consegne';

	/** Logged-out customers on the account page get the plugin's own login screen. */
	private static function is_login_screen() {
		return function_exists( 'is_account_page' ) && is_account_page() && ! is_user_logged_in() && ! is_lost_password_page();
	}

	public static function login_template( $template, $template_name ) {
		if ( 'myaccount/form-login.php' === $template_name && self::is_login_screen() ) {
			return PB_DIR . 'templates/myaccount/form-login.php';
		}
		return $template;
	}

	public static function login_assets() {
		if ( function_exists( 'is_account_page' ) && is_account_page() && is_user_logged_in() ) {
			wp_enqueue_style( 'pb-flow-fonts' );
			wp_enqueue_style( 'pb-front' );
			wp_enqueue_style( 'pb-account', PB_URL . 'assets/css/account.css', array( 'pb-front' ), PB_VERSION );
			add_filter(
				'body_class',
				static function ( $classes ) {
					$classes[] = 'pb-account-page';
					return $classes;
				}
			);
		}
		if ( ! self::is_login_screen() ) {
			return;
		}
		wp_enqueue_style( 'pb-login', PB_URL . 'assets/css/login.css', array( 'pb-flow-fonts' ), PB_VERSION );
		add_filter(
			'body_class',
			static function ( $classes ) {
				$classes[] = 'pb-login-page';
				return $classes;
			}
		);
	}

	public static function init() {
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ) );
		add_action( 'woocommerce_account_' . self::EP_BOOKINGS . '_endpoint', array( __CLASS__, 'bookings_page' ) );
		add_action( 'woocommerce_account_' . self::EP_DELIVERIES . '_endpoint', array( __CLASS__, 'deliveries_page' ) );
		add_filter( 'woocommerce_endpoint_' . self::EP_BOOKINGS . '_title', array( __CLASS__, 'title_bookings' ) );
		add_filter( 'woocommerce_endpoint_' . self::EP_DELIVERIES . '_title', array( __CLASS__, 'title_deliveries' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ), 20 );
		add_filter( 'wc_get_template', array( __CLASS__, 'login_template' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'login_assets' ), 20 );
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'dashboard_summary' ) );
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'login_redirect' ), 10, 2 );
	}

	public static function query_vars( $vars ) {
		$vars[ self::EP_BOOKINGS ]   = self::EP_BOOKINGS;
		$vars[ self::EP_DELIVERIES ] = self::EP_DELIVERIES;
		return $vars;
	}

	public static function menu( $items ) {
		$new = array();
		foreach ( $items as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'dashboard' === $k ) {
				$new[ self::EP_BOOKINGS ]   = __( 'Le mie prenotazioni', 'photo-booking' );
				$new[ self::EP_DELIVERIES ] = __( 'Le mie consegne', 'photo-booking' );
			}
		}
		if ( ! isset( $new[ self::EP_BOOKINGS ] ) ) {
			$new = array_merge(
				array(
					self::EP_BOOKINGS   => __( 'Le mie prenotazioni', 'photo-booking' ),
					self::EP_DELIVERIES => __( 'Le mie consegne', 'photo-booking' ),
				),
				$new
			);
		}
		return $new;
	}

	public static function title_bookings() {
		return __( 'Le mie prenotazioni', 'photo-booking' );
	}

	public static function title_deliveries() {
		return __( 'Le mie consegne', 'photo-booking' );
	}

	public static function login_redirect( $redirect, $user ) {
		if ( ! empty( $_REQUEST['redirect_to'] ) || ! empty( $_REQUEST['redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $redirect;
		}
		// Login from the checkout: stay there, the booking is in the cart.
		if ( Cart::cart_has_booking() ) {
			return wc_get_checkout_url();
		}
		if ( $user && ! user_can( $user, Install::CAP ) && Appointment::query( array( 'customer_id' => $user->ID, 'limit' => 1 ) ) ) {
			return wc_get_account_endpoint_url( self::EP_BOOKINGS );
		}
		return $redirect;
	}

	/** @return Appointment|null owned by current user */
	private static function own_appointment( $id ) {
		$a = Appointment::get( (int) $id );
		if ( ! $a || ! is_user_logged_in() || $a->customer_id !== get_current_user_id() ) {
			return null;
		}
		return $a;
	}

	public static function booking_url( Appointment $a, $args = array() ) {
		return add_query_arg( array_merge( array( 'prenotazione' => $a->id ), $args ), wc_get_account_endpoint_url( self::EP_BOOKINGS ) );
	}

	/* ------------------------------------------------------------------ */
	/* Pages                                                              */
	/* ------------------------------------------------------------------ */

	public static function bookings_page() {
		wp_enqueue_style( 'pb-front' );
		$id = absint( $_GET['prenotazione'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id ) {
			$a = self::own_appointment( $id );
			if ( ! $a ) {
				echo '<p>' . esc_html__( 'Prenotazione non trovata.', 'photo-booking' ) . '</p>';
				return;
			}
			$view = sanitize_key( $_GET['azione'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'sposta' === $view ) {
				wp_enqueue_script( 'pb-picker' );
			}
			pb_get_template(
				'myaccount/booking.php',
				array(
					'a'         => $a,
					'view'      => $view,
					'proposals' => Appointment::PROPOSED === $a->status ? $a->proposals( 'open' ) : array(),
					'outcome'   => Policy::cancel_outcome( $a ),
					'can_move'  => Policy::can_customer_reschedule( $a ),
					'can_cancel' => Policy::can_customer_cancel( $a ),
					'package'   => Package::config( $a->product_id ),
				)
			);
			return;
		}
		$list = Appointment::query(
			array(
				'customer_id'    => get_current_user_id(),
				'exclude_status' => array( Appointment::PENDING_PAYMENT, Appointment::EXPIRED ),
				'orderby'        => 'start_at',
				'order'          => 'DESC',
			)
		);
		$upcoming = array();
		$past     = array();
		$now      = Time::now_db();
		foreach ( $list as $a ) {
			if ( $a->is_active() && $a->end_at >= $now ) {
				array_unshift( $upcoming, $a );
			} else {
				$past[] = $a;
			}
		}
		pb_get_template(
			'myaccount/bookings.php',
			array(
				'upcoming' => $upcoming,
				'past'     => $past,
				'credits'  => Payments::credits_for( get_current_user_id() ),
			)
		);
	}

	public static function deliveries_page() {
		wp_enqueue_style( 'pb-front' );
		pb_get_template(
			'myaccount/deliveries.php',
			array(
				'deliveries' => Deliveries::query(
					array(
						'customer_id' => get_current_user_id(),
						'status'      => array( 'active', 'expired' ),
					)
				),
			)
		);
	}

	public static function dashboard_summary() {
		$next = Appointment::query(
			array(
				'customer_id' => get_current_user_id(),
				'status'      => array( Appointment::PENDING, Appointment::CONFIRMED, Appointment::PROPOSED ),
				'from'        => Time::now_db(),
				'limit'       => 1,
			)
		);
		if ( $next ) {
			$a = $next[0];
			echo '<div class="pb-dash-next"><strong>' . esc_html__( 'Il tuo prossimo appuntamento', 'photo-booking' ) . ':</strong> ' . esc_html( $a->package_name() . ' – ' . Time::format_datetime( $a->start_at ) ) . ' (' . esc_html( $a->status_label() ) . ') <a href="' . esc_url( self::booking_url( $a ) ) . '">' . esc_html__( 'Dettagli', 'photo-booking' ) . '</a></div>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                            */
	/* ------------------------------------------------------------------ */

	public static function handle_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['pb_account_action'] ) ) {
			return;
		}
		$id = absint( $_POST['appointment_id'] ?? 0 );
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'pb_account_' . $id ) ) {
			wc_add_notice( __( 'Sessione scaduta, riprova.', 'photo-booking' ), 'error' );
			return;
		}
		$a = self::own_appointment( $id );
		if ( ! $a ) {
			wc_add_notice( __( 'Prenotazione non trovata.', 'photo-booking' ), 'error' );
			return;
		}
		$action = sanitize_key( $_POST['pb_account_action'] );
		$back   = self::booking_url( $a );

		switch ( $action ) {
			case 'pay_balance':
				if ( $a->is_closed() ) {
					wc_add_notice( __( 'Questa prenotazione è chiusa.', 'photo-booking' ), 'error' );
					break;
				}
				$order = Payments::balance_order( $a );
				if ( is_wp_error( $order ) ) {
					wc_add_notice( $order->get_error_message(), 'error' );
					break;
				}
				wp_safe_redirect( $order->get_checkout_payment_url() );
				exit;

			case 'reschedule':
				$start = sanitize_text_field( wp_unslash( $_POST['start'] ?? '' ) );
				$res   = Bookings::reschedule( $a, $start, 'customer' );
				if ( is_wp_error( $res ) ) {
					wc_add_notice( $res->get_error_message(), 'error' );
					$back = self::booking_url( $a, array( 'azione' => 'sposta' ) );
				} else {
					wc_add_notice( __( 'Richiesta inviata! Lo studio verificherà la nuova data e te la confermerà via email.', 'photo-booking' ) );
				}
				break;

			case 'cancel':
				$outcome = 'credit' === ( $_POST['outcome'] ?? '' ) ? 'credit' : 'refund';
				$res     = Bookings::cancel( $a, $outcome, 'customer', sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
				if ( is_wp_error( $res ) ) {
					wc_add_notice( $res->get_error_message(), 'error' );
				} else {
					wc_add_notice( __( 'La prenotazione è stata disdetta. Ti abbiamo inviato una email con il riepilogo.', 'photo-booking' ) );
				}
				break;

			case 'accept_proposal':
				$res = Bookings::accept_proposal( $a, absint( $_POST['proposal_id'] ?? 0 ) );
				if ( is_wp_error( $res ) ) {
					wc_add_notice( $res->get_error_message(), 'error' );
				} else {
					wc_add_notice( __( 'Perfetto, la nuova data è confermata!', 'photo-booking' ) );
				}
				break;

			case 'decline_proposal':
				$outcome = 'credit' === ( $_POST['outcome'] ?? '' ) ? 'credit' : 'refund';
				$res     = Bookings::decline_proposal( $a, $outcome );
				if ( is_wp_error( $res ) ) {
					wc_add_notice( $res->get_error_message(), 'error' );
				} else {
					wc_add_notice( 'credit' === $outcome ? __( 'Abbiamo convertito l\'importo in un credito.', 'photo-booking' ) : __( 'Abbiamo avviato il rimborso.', 'photo-booking' ) );
				}
				break;
		}
		wp_safe_redirect( $back );
		exit;
	}
}
