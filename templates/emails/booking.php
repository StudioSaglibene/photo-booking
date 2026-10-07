<?php
/**
 * Photo Booking email (HTML). Override in yourtheme/photo-booking/emails/booking.php
 *
 * @var string                          $email_heading
 * @var string                          $intro
 * @var \PhotoBooking\Appointment|null  $appointment
 * @var array|null                      $delivery
 * @var array                           $extra
 * @var string                          $key
 * @var array|null                      $button
 * @var string                          $additional_content
 * @var bool                            $sent_to_admin
 * @var \WC_Email                       $email
 */

use PhotoBooking\Package;
use PhotoBooking\Settings;
use PhotoBooking\Time;

defined( 'ABSPATH' ) || exit;

$accent = Settings::get( 'accent_color' ) ?: '#8a6d4b';

do_action( 'woocommerce_email_header', $email_heading, $email );

foreach ( array_filter( explode( "\n", (string) $intro ) ) as $line ) {
	echo '<p>' . wp_kses_post( $line ) . '</p>';
}

if ( ! empty( $extra['message'] ) ) {
	echo '<blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid ' . esc_attr( $accent ) . ';background:#f7f4f0;">' . wp_kses_post( nl2br( esc_html( $extra['message'] ) ) ) . '</blockquote>';
}

if ( ! empty( $extra['conflict'] ) ) {
	echo '<p style="color:#c0392b"><strong>' . esc_html__( 'Attenzione: il pagamento è arrivato dopo la scadenza del blocco e nel frattempo lo slot è stato occupato. Proponi un\'altra data al cliente.', 'photo-booking' ) . '</strong></p>';
}

// Money outcome (rejected / cancelled / proposal answered).
if ( ! empty( $extra['money'] ) ) {
	$m = $extra['money'];
	echo '<h3>' . esc_html__( 'Importi', 'photo-booking' ) . '</h3><ul>';
	if ( $m['refunded'] > 0 ) {
		/* translators: %s amount */
		echo '<li>' . wp_kses_post( sprintf( __( 'Rimborso di %s sul metodo di pagamento utilizzato (i tempi di accredito dipendono dalla banca, di solito 5-10 giorni lavorativi).', 'photo-booking' ), wc_price( $m['refunded'] ) ) ) . '</li>';
	}
	if ( $m['manual'] > 0 ) {
		/* translators: %s amount */
		echo '<li>' . wp_kses_post( sprintf( $sent_to_admin ? __( '%s da restituire manualmente (pagati in contanti).', 'photo-booking' ) : __( '%s pagati in studio ti verranno restituiti direttamente dallo studio.', 'photo-booking' ), wc_price( $m['manual'] ) ) ) . '</li>';
	}
	if ( $m['credit'] > 0 ) {
		/* translators: 1 amount 2 code */
		echo '<li>' . wp_kses_post( sprintf( __( 'Credito di %1$s (codice %2$s), applicato automaticamente alla prossima prenotazione.', 'photo-booking' ), wc_price( $m['credit'] ), '<strong>' . esc_html( $m['credit_code'] ) . '</strong>' ) ) . '</li>';
	}
	if ( 'lost' === $m['outcome'] && $m['paid'] > 0 ) {
		/* translators: %s amount */
		echo '<li>' . wp_kses_post( sprintf( __( 'Secondo le condizioni di prenotazione, l\'importo versato (%s) non viene restituito.', 'photo-booking' ), wc_price( $m['paid'] ) ) ) . '</li>';
	}
	if ( $m['paid'] <= 0 ) {
		echo '<li>' . esc_html__( 'Non risultano importi pagati.', 'photo-booking' ) . '</li>';
	}
	if ( $sent_to_admin && ! empty( $m['errors'] ) ) {
		echo '<li style="color:#c0392b">' . esc_html( implode( '; ', $m['errors'] ) ) . '</li>';
	}
	echo '</ul>';
}

if ( isset( $extra['accepted'] ) && $sent_to_admin ) {
	echo '<p><strong>' . ( $extra['accepted'] ? esc_html__( 'Il cliente ha ACCETTATO una delle date proposte: l\'appuntamento è confermato.', 'photo-booking' ) : esc_html__( 'Il cliente ha RIFIUTATO le date proposte: la prenotazione è stata annullata.', 'photo-booking' ) ) . '</strong></p>';
}

// Proposals list.
if ( 'proposal' === $key && $appointment ) {
	echo '<ul>';
	foreach ( $appointment->proposals( 'open' ) as $p ) {
		echo '<li><strong>' . esc_html( ucfirst( Time::format_datetime( $p['start_at'] ) ) ) . '</strong></li>';
	}
	echo '</ul>';
}

// Credit issued.
if ( 'credit_issued' === $key && ! empty( $extra['code'] ) ) {
	echo '<p style="font-size:18px;text-align:center;padding:12px;border:1px dashed ' . esc_attr( $accent ) . ';">' . esc_html__( 'Codice credito', 'photo-booking' ) . ': <strong>' . esc_html( $extra['code'] ) . '</strong></p>';
}

if ( in_array( $key, array( 'rescheduled', 'rescheduled_pending', 'admin_customer_rescheduled' ), true ) && ! empty( $extra['old'] ) ) {
	/* translators: %s old date */
	echo '<p style="color:#777">' . esc_html( sprintf( __( 'Data precedente: %s', 'photo-booking' ), Time::format_datetime( $extra['old'] ) ) ) . '</p>';
}

// Appointment details.
if ( $appointment && ! in_array( $key, array( 'credit_issued' ), true ) ) {
	$rows = array(
		__( 'Prenotazione', 'photo-booking' ) => $appointment->number(),
		__( 'Pacchetto', 'photo-booking' )    => $appointment->package_name(),
		__( 'Data', 'photo-booking' )         => ucfirst( Time::format_date( $appointment->start_at ) ),
		__( 'Orario', 'photo-booking' )       => Time::format_time( $appointment->start_at ) . ' – ' . Time::format_time( $appointment->end_at ),
	);
	if ( Settings::get( 'studio_address' ) ) {
		$rows[ __( 'Dove', 'photo-booking' ) ] = Settings::get( 'studio_address' );
	}
	$rows[ __( 'Totale', 'photo-booking' ) ]  = wp_strip_all_tags( wc_price( $appointment->total ) );
	$rows[ __( 'Versato', 'photo-booking' ) ] = wp_strip_all_tags( wc_price( $appointment->paid ) );
	if ( $appointment->balance_due() > 0 && ! $appointment->is_closed() ) {
		$rows[ __( 'Da saldare', 'photo-booking' ) ] = wp_strip_all_tags( wc_price( $appointment->balance_due() ) ) . ( $sent_to_admin ? '' : ' – ' . __( 'online dall\'area privata o in studio', 'photo-booking' ) );
	}
	if ( $sent_to_admin ) {
		$rows[ __( 'Cliente', 'photo-booking' ) ]  = $appointment->customer_name;
		$rows[ __( 'Email', 'photo-booking' ) ]    = $appointment->customer_email;
		$rows[ __( 'Telefono', 'photo-booking' ) ] = $appointment->customer_phone;
		if ( $appointment->extras_label() ) {
			$rows[ __( 'Extra', 'photo-booking' ) ] = $appointment->extras_label();
		}
		if ( $appointment->get_meta( 'participants' ) ) {
			$rows[ __( 'Partecipanti', 'photo-booking' ) ] = $appointment->get_meta( 'participants' );
		}
		if ( $appointment->get_meta( 'children' ) ) {
			$rows[ __( 'Età bambini', 'photo-booking' ) ] = $appointment->get_meta( 'children' );
		}
		if ( $appointment->customer_notes ) {
			$rows[ __( 'Note', 'photo-booking' ) ] = $appointment->customer_notes;
		}
		$rows[ __( 'Stato', 'photo-booking' ) ] = $appointment->status_label() . ' / ' . $appointment->payment_label();
	}
	echo '<table cellspacing="0" cellpadding="8" style="width:100%;border:1px solid #e5e5e5;border-collapse:collapse;margin:16px 0;" border="1">';
	foreach ( $rows as $label => $value ) {
		echo '<tr><th style="text-align:left;width:35%;background:#fafafa;">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
	echo '</table>';

	if ( 'confirmed' === $key ) {
		$cfg = Package::config( $appointment->product_id );
		if ( ! empty( $cfg['instructions'] ) ) {
			echo '<h3>' . esc_html__( 'Come prepararsi', 'photo-booking' ) . '</h3>' . wp_kses_post( wpautop( $cfg['instructions'] ) );
		}
	}
	if ( in_array( $key, array( 'confirmed', 'request_received' ), true ) ) {
		echo '<h3>' . esc_html__( 'Disdetta e spostamenti', 'photo-booking' ) . '</h3>' . wp_kses_post( \PhotoBooking\Policy::policy_html() );
	}
}

// Delivery details.
if ( $delivery ) {
	echo '<table cellspacing="0" cellpadding="8" style="width:100%;border:1px solid #e5e5e5;border-collapse:collapse;margin:16px 0;" border="1">';
	echo '<tr><th style="text-align:left;width:35%;background:#fafafa;">' . esc_html__( 'Consegna', 'photo-booking' ) . '</th><td>' . esc_html( $delivery['title'] ) . '</td></tr>';
	echo '<tr><th style="text-align:left;background:#fafafa;">' . esc_html__( 'Dimensione', 'photo-booking' ) . '</th><td>' . esc_html( size_format( (int) $delivery['size'], 1 ) ) . '</td></tr>';
	if ( $delivery['expires_at'] ) {
		echo '<tr><th style="text-align:left;background:#fafafa;">' . esc_html__( 'Scaricabile fino al', 'photo-booking' ) . '</th><td>' . esc_html( Time::format_date( $delivery['expires_at'] ) ) . '</td></tr>';
	}
	echo '</table>';
}

if ( $button ) {
	echo '<p style="text-align:center;margin:24px 0;"><a href="' . esc_url( $button[1] ) . '" style="display:inline-block;padding:12px 24px;background:' . esc_attr( $accent ) . ';color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">' . esc_html( $button[0] ) . '</a></p>';
}

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
