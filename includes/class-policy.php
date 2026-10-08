<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Cancellation / reschedule rules.
 */
class Policy {

	const OUTCOME_CHOICE = 'choice'; // (legacy) customer chooses refund or credit.
	const OUTCOME_REFUND = 'refund'; // Full refund on the original payment method.
	const OUTCOME_CREDIT = 'credit'; // (legacy, admin only) amount turned into a credit.
	const OUTCOME_LOST   = 'lost';

	/**
	 * What happens to the money if the appointment is cancelled now.
	 *
	 * @return array type, label, description
	 */
	public static function cancel_outcome( Appointment $appt ) {
		$hours        = $appt->hours_until_start();
		$refund_hours = (int) Settings::get( 'refund_min_days' ) * 24;

		// Not yet confirmed, or far enough in advance: the customer gets the money back. No credits/points for customers.
		if ( in_array( $appt->status, array( Appointment::PENDING, Appointment::PROPOSED, Appointment::PROPOSAL_EXPIRED, Appointment::NEEDS_ACTION ), true ) || $hours >= $refund_hours ) {
			$type = self::OUTCOME_REFUND;
			$desc = __( 'L\'importo versato ti viene rimborsato per intero sul metodo di pagamento usato.', 'photo-booking' );
		} else {
			$type = self::OUTCOME_LOST;
			/* translators: %d days */
			$desc = sprintf( __( 'Mancano meno di %d giorni all\'appuntamento: secondo le condizioni di prenotazione l\'importo versato non viene restituito.', 'photo-booking' ), (int) Settings::get( 'refund_min_days' ) );
		}
		if ( $appt->paid <= 0 ) {
			$desc = __( 'Non hai effettuato pagamenti per questa prenotazione.', 'photo-booking' );
		}
		return array(
			'type'        => $type,
			'description' => $desc,
			'hours'       => $hours,
		);
	}

	/**
	 * Can the customer reschedule by themselves?
	 *
	 * @return true|\WP_Error
	 */
	public static function can_customer_reschedule( Appointment $appt ) {
		if ( ! in_array( $appt->status, array( Appointment::PENDING, Appointment::CONFIRMED ), true ) ) {
			return new \WP_Error( 'pb_status', __( 'Questa prenotazione non può essere spostata.', 'photo-booking' ) );
		}
		if ( $appt->reschedule_count >= (int) Settings::get( 'reschedule_max' ) ) {
			return new \WP_Error( 'pb_limit', __( 'Hai già usato lo spostamento gratuito. Contatta lo studio per altre modifiche.', 'photo-booking' ) );
		}
		if ( $appt->hours_until_start() < (int) Settings::get( 'reschedule_min_hours' ) ) {
			/* translators: %d hours */
			return new \WP_Error( 'pb_late', sprintf( __( 'Lo spostamento è possibile fino a %d ore prima. Contatta lo studio.', 'photo-booking' ), (int) Settings::get( 'reschedule_min_hours' ) ) );
		}
		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function can_customer_cancel( Appointment $appt ) {
		if ( ! in_array( $appt->status, array( Appointment::PENDING, Appointment::CONFIRMED, Appointment::PROPOSED, Appointment::PROPOSAL_EXPIRED, Appointment::NEEDS_ACTION ), true ) ) {
			return new \WP_Error( 'pb_status', __( 'Questa prenotazione non può essere disdetta.', 'photo-booking' ) );
		}
		if ( $appt->hours_until_start() < 0 && Appointment::CONFIRMED === $appt->status ) {
			return new \WP_Error( 'pb_past', __( 'L\'appuntamento è già passato.', 'photo-booking' ) );
		}
		return true;
	}

	public static function policy_html() {
		$lines = array_filter( array_map( 'trim', explode( "\n", (string) Settings::get( 'policy_text' ) ) ) );
		if ( ! $lines ) {
			return '';
		}
		return '<ul class="pb-policy">' . implode(
			'',
			array_map(
				static function ( $l ) {
					return '<li>' . esc_html( $l ) . '</li>';
				},
				$lines
			)
		) . '</ul>';
	}
}
