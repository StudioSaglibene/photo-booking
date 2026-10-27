<?php
namespace PhotoBooking\Emails;

use PhotoBooking\Account;
use PhotoBooking\Appointment;
use PhotoBooking\Package;
use PhotoBooking\Settings;
use PhotoBooking\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Generic, configurable booking email.
 */
class Booking_Email extends \WC_Email {

	public $key;
	public $def;
	/** @var Appointment|null */
	public $appointment = null;
	/** @var array|null */
	public $delivery = null;
	public $extra = array();

	public function __construct( $key, array $def ) {
		$this->key            = $key;
		$this->def            = $def;
		$this->id             = 'pb_' . $key;
		$this->title          = 'Photo Booking: ' . $def['title'];
		$this->description    = __( 'Email automatica di Photo Booking.', 'photo-booking' );
		$this->customer_email = 'customer' === $def['to'];
		$this->template_base  = PB_DIR . 'templates/';
		$this->template_html  = 'emails/booking.php';
		$this->template_plain = 'emails/plain/booking.php';
		$this->placeholders   = array(
			'{customer_name}'      => '',
			'{package}'            => '',
			'{date}'               => '',
			'{time}'               => '',
			'{appointment_number}' => '',
			'{delivery_title}'     => '',
			'{expires}'            => '',
			'{amount}'             => '',
			'{deadline}'           => '',
		);
		parent::__construct();
		if ( ! $this->customer_email ) {
			$r               = $this->get_option( 'recipient' );
			$this->recipient = $r ? $r : get_option( 'admin_email' );
		}
		// Reminder is optional; follows the plugin setting by default.
		if ( 'reminder' === $key && ! Settings::get( 'reminder_enabled' ) ) {
			$this->enabled = 'no';
		}
	}

	public function get_default_subject() {
		return $this->def['subject'];
	}

	public function get_default_heading() {
		return $this->def['heading'];
	}

	public function get_default_additional_content() {
		return '';
	}

	public function get_intro() {
		return $this->format_string( $this->get_option( 'intro', $this->def['intro'] ) );
	}

	/**
	 * @param Appointment|array $object
	 */
	public function trigger( $object, $extra = array() ) {
		$this->setup_locale();
		$this->appointment = $object instanceof Appointment ? $object : null;
		$this->delivery    = is_array( $object ) ? $object : null;
		$this->extra       = $extra;
		$this->object      = $object;

		if ( $this->appointment ) {
			$a = $this->appointment;
			$this->placeholders['{customer_name}']      = $this->customer_email ? $this->first_name( $a->customer_name ) : $a->customer_name;
			$this->placeholders['{package}']            = $a->package_name();
			$this->placeholders['{date}']               = Time::format_date( $a->start_at );
			$this->placeholders['{time}']               = Time::format_time( $a->start_at );
			$this->placeholders['{appointment_number}'] = $a->number();
			if ( $this->customer_email ) {
				$this->recipient = $a->customer_email;
			}
			if ( 'proposal' === $this->key ) {
				$exp = $a->get_meta( 'proposal_expires' );
				$this->placeholders['{expires}'] = $exp ? Time::format_datetime( $exp ) : '';
			}
		}
		if ( $this->delivery ) {
			$user = get_userdata( (int) $this->delivery['customer_id'] );
			$name = $user ? ( get_user_meta( $user->ID, 'billing_first_name', true ) ?: $user->display_name ) : '';
			$this->placeholders['{customer_name}']  = $this->first_name( $name );
			$this->placeholders['{delivery_title}'] = $this->delivery['title'];
			$this->placeholders['{expires}']        = $this->delivery['expires_at'] ? Time::format_date( $this->delivery['expires_at'] ) : '';
			if ( $this->customer_email && $user ) {
				$this->recipient = $user->user_email;
			}
		}
		if ( isset( $extra['amount'] ) ) {
			$this->placeholders['{amount}'] = html_entity_decode( wp_strip_all_tags( wc_price( $extra['amount'] ) ), ENT_QUOTES, 'UTF-8' );
		}
		if ( ! empty( $extra['deadline'] ) ) {
			$d = $extra['deadline'];
			$this->placeholders['{deadline}'] = Time::format_datetime( $d instanceof \DateTimeInterface ? $d->format( Time::DB ) : (string) $d );
		}
		if ( isset( $extra['expires'] ) && $extra['expires'] ) {
			$this->placeholders['{expires}'] = Time::format_date( '@' . ( is_object( $extra['expires'] ) ? $extra['expires']->getTimestamp() : (int) $extra['expires'] ) );
		}

		$sent = false;
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
		return $sent;
	}

	private function first_name( $name ) {
		$parts = explode( ' ', trim( (string) $name ) );
		return $parts[0] ?? '';
	}

	/** Call-to-action button. */
	public function button() {
		$type = $this->def['button'] ?? '';
		$a    = $this->appointment;
		switch ( $type ) {
			case 'account':
				return array( __( 'Vai alla tua area privata', 'photo-booking' ), wc_get_account_endpoint_url( Account::EP_BOOKINGS ) );
			case 'pay':
				if ( ! empty( $this->extra['pay_url'] ) ) {
					return array( __( 'Paga ora con carta', 'photo-booking' ), $this->extra['pay_url'] );
				}
				return array( __( 'Vai alla tua area privata', 'photo-booking' ), wc_get_account_endpoint_url( Account::EP_BOOKINGS ) );
			case 'appointment':
				return array( __( 'Scegli la nuova data', 'photo-booking' ), $a ? $a->account_url() : wc_get_account_endpoint_url( Account::EP_BOOKINGS ) );
			case 'deliveries':
				return array( __( 'Scarica le foto', 'photo-booking' ), wc_get_account_endpoint_url( Account::EP_DELIVERIES ) );
			case 'book':
				return array( __( 'Prenota una sessione', 'photo-booking' ), Settings::booking_page_url() );
			case 'admin':
				return $a ? array( __( 'Apri la prenotazione', 'photo-booking' ), $a->admin_url() ) : array( __( 'Apri il calendario', 'photo-booking' ), admin_url( 'admin.php?page=pb-calendar' ) );
		}
		return null;
	}

	private function template_args( $plain ) {
		return array(
			'email_heading'      => $this->get_heading(),
			'intro'              => $this->get_intro(),
			'appointment'        => $this->appointment,
			'delivery'           => $this->delivery,
			'extra'              => $this->extra,
			'key'                => $this->key,
			'button'             => $this->button(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => ! $this->customer_email,
			'plain_text'         => $plain,
			'email'              => $this,
		);
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), 'photo-booking/', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), 'photo-booking/', $this->template_base );
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$fields = array();
		foreach ( $this->form_fields as $k => $f ) {
			$fields[ $k ] = $f;
			if ( 'heading' === $k ) {
				$fields['intro'] = array(
					'title'       => __( 'Testo del messaggio', 'photo-booking' ),
					'type'        => 'textarea',
					'css'         => 'width:400px;height:110px;',
					/* translators: placeholders */
					'description' => __( 'Segnaposto disponibili: {customer_name}, {package}, {date}, {time}, {appointment_number}, {delivery_title}, {expires}, {amount}, {site_title}. I dettagli della prenotazione vengono aggiunti automaticamente sotto il testo.', 'photo-booking' ),
					'placeholder' => '',
					'default'     => $this->def['intro'],
					'desc_tip'    => false,
				);
			}
		}
		if ( ! $this->customer_email ) {
			$fields = array_merge(
				array_slice( $fields, 0, 1, true ),
				array(
					'recipient' => array(
						'title'       => __( 'Destinatari', 'photo-booking' ),
						'type'        => 'text',
						/* translators: %s admin email */
						'description' => sprintf( __( 'Separati da virgola. Predefinito: %s', 'photo-booking' ), esc_attr( get_option( 'admin_email' ) ) ),
						'placeholder' => '',
						'default'     => '',
						'desc_tip'    => true,
					),
				),
				array_slice( $fields, 1, null, true )
			);
		}
		$this->form_fields = $fields;
	}
}
