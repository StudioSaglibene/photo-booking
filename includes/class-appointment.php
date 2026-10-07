<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Appointment entity + repository.
 *
 * @property int    $id
 * @property int    $customer_id
 * @property string $customer_name
 * @property string $customer_email
 * @property string $customer_phone
 * @property int    $product_id
 * @property string $start_at
 * @property string $end_at
 * @property string $busy_until
 * @property string $status
 * @property string $payment_status
 * @property string $payment_mode
 * @property float  $total
 * @property float  $deposit
 * @property float  $paid
 * @property int    $order_id
 * @property string $hold_expires_at
 * @property int    $reschedule_count
 * @property int    $reminder_sent
 * @property string $source
 * @property string $customer_notes
 * @property string $admin_notes
 * @property string $created_at
 * @property string $updated_at
 */
class Appointment {

	// Booking statuses.
	const PENDING_PAYMENT  = 'pending_payment';  // Slot held while customer pays.
	const PENDING          = 'pending';          // Paid, waiting for admin confirmation.
	const PROPOSED         = 'proposed';         // Admin proposed other dates, waiting for customer.
	const PROPOSAL_EXPIRED = 'proposal_expired'; // Customer did not answer the proposal.
	const CONFIRMED        = 'confirmed';
	const COMPLETED        = 'completed';        // Session done.
	const REJECTED         = 'rejected';
	const CANCELLED        = 'cancelled';
	const EXPIRED          = 'expired';          // Hold expired without payment.
	const NEEDS_ACTION     = 'needs_action';     // Paid after hold expiry and slot taken meanwhile: admin must act.

	// Payment statuses.
	const PAY_UNPAID   = 'unpaid';
	const PAY_DEPOSIT  = 'deposit';
	const PAY_PAID     = 'paid';
	const PAY_REFUNDED = 'refunded';
	const PAY_PARTIAL_REFUND = 'partially_refunded';
	const PAY_CREDIT   = 'credit';
	const PAY_LOST     = 'lost';

	private $data = array();
	private $meta = array();

	public function __construct( array $row = array() ) {
		$this->data = $row;
		$this->meta = ! empty( $row['meta'] ) ? (array) json_decode( $row['meta'], true ) : array();
		unset( $this->data['meta'] );
	}

	public function __get( $key ) {
		if ( ! array_key_exists( $key, $this->data ) ) {
			return null;
		}
		$v = $this->data[ $key ];
		if ( null === $v && in_array( $key, array( 'customer_notes', 'admin_notes', 'customer_phone' ), true ) ) {
			return '';
		}
		if ( in_array( $key, array( 'id', 'customer_id', 'product_id', 'order_id', 'reschedule_count', 'reminder_sent' ), true ) ) {
			return (int) $v;
		}
		if ( in_array( $key, array( 'total', 'deposit', 'paid' ), true ) ) {
			return round( (float) $v, 2 );
		}
		return $v;
	}

	public function __isset( $key ) {
		return isset( $this->data[ $key ] );
	}

	public function get_meta( $key, $default = null ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : $default;
	}

	public function to_array() {
		return $this->data + array( 'meta' => $this->meta );
	}

	/* ------------------------------------------------------------------ */
	/* Labels                                                             */
	/* ------------------------------------------------------------------ */

	public static function status_labels() {
		return array(
			self::PENDING_PAYMENT  => __( 'In pagamento', 'photo-booking' ),
			self::PENDING          => __( 'In attesa di conferma', 'photo-booking' ),
			self::PROPOSED         => __( 'Proposta inviata', 'photo-booking' ),
			self::PROPOSAL_EXPIRED => __( 'Proposta senza risposta', 'photo-booking' ),
			self::CONFIRMED        => __( 'Confermata', 'photo-booking' ),
			self::COMPLETED        => __( 'Svolta', 'photo-booking' ),
			self::REJECTED         => __( 'Rifiutata', 'photo-booking' ),
			self::CANCELLED        => __( 'Annullata', 'photo-booking' ),
			self::EXPIRED          => __( 'Scaduta (non pagata)', 'photo-booking' ),
			self::NEEDS_ACTION     => __( 'Da gestire (slot occupato)', 'photo-booking' ),
		);
	}

	public static function status_colors() {
		return array(
			self::PENDING_PAYMENT  => '#9aa0a6',
			self::PENDING          => '#e8912d',
			self::PROPOSED         => '#8e5bd6',
			self::PROPOSAL_EXPIRED => '#c0392b',
			self::CONFIRMED        => '#2e9d5b',
			self::COMPLETED        => '#2b6cb0',
			self::REJECTED         => '#7f8c8d',
			self::CANCELLED        => '#7f8c8d',
			self::EXPIRED          => '#bdc3c7',
			self::NEEDS_ACTION     => '#c0392b',
		);
	}

	public static function payment_labels() {
		return array(
			self::PAY_UNPAID         => __( 'Non pagato', 'photo-booking' ),
			self::PAY_DEPOSIT        => __( 'Acconto versato', 'photo-booking' ),
			self::PAY_PAID           => __( 'Saldato', 'photo-booking' ),
			self::PAY_REFUNDED       => __( 'Rimborsato', 'photo-booking' ),
			self::PAY_PARTIAL_REFUND => __( 'Rimborsato parzialmente', 'photo-booking' ),
			self::PAY_CREDIT         => __( 'Convertito in credito', 'photo-booking' ),
			self::PAY_LOST           => __( 'Trattenuto (disdetta tardiva)', 'photo-booking' ),
		);
	}

	public function status_label() {
		$l = self::status_labels();
		return $l[ $this->status ] ?? $this->status;
	}

	public function payment_label() {
		$l = self::payment_labels();
		return $l[ $this->payment_status ] ?? $this->payment_status;
	}

	/** Statuses that occupy the calendar. */
	public static function blocking_statuses() {
		return array( self::PENDING_PAYMENT, self::PENDING, self::CONFIRMED, self::COMPLETED );
	}

	/** Statuses that count as an "active" booking (for package capacity, customer area). */
	public static function active_statuses() {
		return array( self::PENDING_PAYMENT, self::PENDING, self::PROPOSED, self::PROPOSAL_EXPIRED, self::NEEDS_ACTION, self::CONFIRMED, self::COMPLETED );
	}

	public function is_active() {
		return in_array( $this->status, self::active_statuses(), true );
	}

	public function is_closed() {
		return in_array( $this->status, array( self::REJECTED, self::CANCELLED, self::EXPIRED ), true );
	}

	public function balance_due() {
		return max( 0, round( $this->total - $this->paid, 2 ) );
	}

	public function product() {
		return $this->product_id ? wc_get_product( $this->product_id ) : null;
	}

	public function package_name() {
		$p    = $this->product();
		$name = $p ? $p->get_name() : (string) $this->get_meta( 'product_name', __( 'Pacchetto', 'photo-booking' ) );
		$tier = (string) $this->get_meta( 'tier_name', '' );
		return '' !== $tier ? $name . ' – ' . $tier : $name;
	}

	/** Extras chosen with the sub-package, e.g. "Album (+150 €), 10 foto in più (+80 €)". */
	public function extras_label() {
		$extras = (array) $this->get_meta( 'extras', array() );
		$out    = array();
		foreach ( $extras as $e ) {
			$out[] = $e['name'] . ' (+' . html_entity_decode( wp_strip_all_tags( wc_price( $e['price'] ) ), ENT_QUOTES, 'UTF-8' ) . ')';
		}
		return implode( ', ', $out );
	}

	public function start() {
		return Time::parse( $this->start_at );
	}

	public function end() {
		return Time::parse( $this->end_at );
	}

	public function hours_until_start() {
		return Time::hours_until( $this->start_at );
	}

	public function number() {
		return '#' . $this->id;
	}

	public function admin_url() {
		return admin_url( 'admin.php?page=pb-appointment&id=' . $this->id );
	}

	public function account_url() {
		return wc_get_account_endpoint_url( Account::EP_BOOKINGS ) ? add_query_arg( 'prenotazione', $this->id, wc_get_account_endpoint_url( Account::EP_BOOKINGS ) ) : home_url();
	}

	public function proposals( $status = 'open' ) {
		global $wpdb;
		$t   = Install::table( 'proposals' );
		$sql = $status ? $wpdb->prepare( "SELECT * FROM {$t} WHERE appointment_id = %d AND status = %s ORDER BY start_at", $this->id, $status ) : $wpdb->prepare( "SELECT * FROM {$t} WHERE appointment_id = %d ORDER BY id", $this->id ); // phpcs:ignore
		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
	}

	/* ------------------------------------------------------------------ */
	/* Repository                                                         */
	/* ------------------------------------------------------------------ */

	/** @return Appointment|null */
	public static function get( $id ) {
		global $wpdb;
		$t   = Install::table( 'appointments' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
		return $row ? new self( $row ) : null;
	}

	/** @return Appointment|null */
	public static function find_by_order( $order_id ) {
		global $wpdb;
		$t  = Install::table( 'appointments' );
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE order_id = %d ORDER BY id DESC LIMIT 1", $order_id ) ); // phpcs:ignore
		return $id ? self::get( $id ) : null;
	}

	/**
	 * @param array $data Column => value.
	 * @return Appointment
	 */
	public static function create( array $data ) {
		global $wpdb;
		$now  = Time::now_db();
		$meta = $data['meta'] ?? array();
		unset( $data['meta'] );
		$data = array_merge(
			array(
				'status'         => self::PENDING_PAYMENT,
				'payment_status' => self::PAY_UNPAID,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			$data
		);
		$data['meta'] = wp_json_encode( $meta );
		$wpdb->insert( Install::table( 'appointments' ), $data );
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Persist changes.
	 *
	 * @param array $changes Column => value. Use key "meta" (array) to merge meta.
	 */
	public function update( array $changes ) {
		global $wpdb;
		if ( isset( $changes['meta'] ) ) {
			$this->meta      = array_merge( $this->meta, (array) $changes['meta'] );
			$changes['meta'] = wp_json_encode( $this->meta );
		}
		$changes['updated_at'] = Time::now_db();
		$wpdb->update( Install::table( 'appointments' ), $changes, array( 'id' => $this->id ) );
		unset( $changes['meta'] );
		$this->data = array_merge( $this->data, $changes );
		return $this;
	}

	/**
	 * Query appointments.
	 *
	 * @param array $args status (string|array), customer_id, from, to, product_id, search, orderby, order, limit, offset.
	 * @return Appointment[]
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$t     = Install::table( 'appointments' );
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $args['status'] ) ) {
			$st      = (array) $args['status'];
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $st ), '%s' ) ) . ')';
			$vals    = array_merge( $vals, $st );
		}
		if ( ! empty( $args['exclude_status'] ) ) {
			$st      = (array) $args['exclude_status'];
			$where[] = 'status NOT IN (' . implode( ',', array_fill( 0, count( $st ), '%s' ) ) . ')';
			$vals    = array_merge( $vals, $st );
		}
		if ( isset( $args['customer_id'] ) ) {
			$where[] = 'customer_id = %d';
			$vals[]  = (int) $args['customer_id'];
		}
		if ( ! empty( $args['product_id'] ) ) {
			$where[] = 'product_id = %d';
			$vals[]  = (int) $args['product_id'];
		}
		if ( ! empty( $args['from'] ) ) {
			$where[] = 'busy_until > %s';
			$vals[]  = $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where[] = 'start_at < %s';
			$vals[]  = $args['to'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s OR id = %d)';
			array_push( $vals, $like, $like, $like, (int) ltrim( $args['search'], '#' ) );
		}
		$orderby = in_array( $args['orderby'] ?? '', array( 'start_at', 'created_at', 'id', 'updated_at' ), true ) ? $args['orderby'] : 'start_at';
		$order   = strtoupper( $args['order'] ?? 'ASC' ) === 'DESC' ? 'DESC' : 'ASC';
		$sql     = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$orderby} {$order}";
		if ( ! empty( $args['limit'] ) ) {
			$sql .= ' LIMIT ' . (int) $args['limit'] . ' OFFSET ' . (int) ( $args['offset'] ?? 0 );
		}
		$rows = $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
		return array_map(
			static function ( $r ) {
				return new self( $r );
			},
			(array) $rows
		);
	}

	public static function count( array $args = array() ) {
		global $wpdb;
		$t     = Install::table( 'appointments' );
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $args['status'] ) ) {
			$st      = (array) $args['status'];
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $st ), '%s' ) ) . ')';
			$vals    = array_merge( $vals, $st );
		}
		if ( ! empty( $args['product_id'] ) ) {
			$where[] = 'product_id = %d';
			$vals[]  = (int) $args['product_id'];
		}
		$sql = "SELECT COUNT(*) FROM {$t} WHERE " . implode( ' AND ', $where );
		return (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore
	}

	/* ------------------------------------------------------------------ */
	/* History                                                            */
	/* ------------------------------------------------------------------ */

	public function log( $type, $message = '' ) {
		global $wpdb;
		$wpdb->insert(
			Install::table( 'events' ),
			array(
				'appointment_id' => $this->id,
				'type'           => $type,
				'message'        => $message,
				'user_id'        => get_current_user_id(),
				'created_at'     => Time::now_db(),
			)
		);
	}

	public function history() {
		global $wpdb;
		$t = Install::table( 'events' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE appointment_id = %d ORDER BY id DESC", $this->id ), ARRAY_A ); // phpcs:ignore
	}

	public function payments() {
		global $wpdb;
		$t = Install::table( 'payments' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE appointment_id = %d ORDER BY id ASC", $this->id ), ARRAY_A ); // phpcs:ignore
	}
}
