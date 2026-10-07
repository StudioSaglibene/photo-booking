<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Cancellation / reschedule rules.
 */
class Policy {

	const OUTCOME_CHOICE = 'choice'; // Customer chooses refund or credit.
	const OUTCOME_CREDIT = 'credit';
	const OUTCOME_LOST   = 'lost';

	/**
	 * What happens to the money if the appointment is cancelled now.
	 *
	 * @return array type, label, description
	 */
	public static function cancel_outcome( Appointment $appt ) {
		$hours        = $appt->hours_until_start();
		$refund_hours = (int) Settings::get( 'refund_min_days' ) * 24;
		$credit_hours = (int) Settings::get( 'credit_min_hours' );
		$months       = (int) Settings::get( 'credit_validity_months' );

		// Not yet confirmed: the customer can always get the money back.
		if ( in_array( $appt->status, array( Appointment::PENDING, Appointment::PROPOSED, Appointment::PROPOSAL_EXPIRED, Appointment::NEEDS_ACTION ), true ) || $hours >= $refund_hours ) {
			$type = self::OUTCOME_CHOICE;
			$desc = __( 'Puoi scegliere tra il rimborso sul metodo di pagamento usato oppure un credito da usare per un altro servizio.', 'photo-booking' );
		} elseif ( $hours >= $credit_hours ) {
			$type = self::OUTCOME_CREDIT;
			/* translators: %d months */
			$desc = sprintf( __( 'L\'importo versato diventa un credito valido %d mesi su qualsiasi servizio in studio.', 'photo-booking' ), $months );
		} else {
			$type = self::OUTCOME_LOST;
			$desc = __( 'Mancano meno di 24 ore all\'appuntamento: secondo le condizioni di prenotazione l\'importo versato non viene restituito.', 'photo-booking' );
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
