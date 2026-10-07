<?php
/**
 * Photo Booking email (plain text).
 *
 * @var string $email_heading
 * @var string $intro
 * @var \PhotoBooking\Appointment|null $appointment
 * @var array|null $delivery
 * @var array $extra
 * @var string $key
 * @var array|null $button
 * @var string $additional_content
 */

use PhotoBooking\Time;

defined( 'ABSPATH' ) || exit;

echo '= ' . wp_strip_all_tags( $email_heading ) . " =\n\n";
echo wp_strip_all_tags( $intro ) . "\n\n";

if ( ! empty( $extra['message'] ) ) {
	echo wp_strip_all_tags( $extra['message'] ) . "\n\n";
}
if ( ! empty( $extra['money'] ) ) {
	echo wp_strip_all_tags( \PhotoBooking\Bookings::money_summary( $extra['money'] ) ) . "\n\n";
}
if ( 'proposal' === $key && $appointment ) {
	foreach ( $appointment->proposals( 'open' ) as $p ) {
		echo '- ' . ucfirst( Time::format_datetime( $p['start_at'] ) ) . "\n";
	}
	echo "\n";
}
if ( 'credit_issued' === $key && ! empty( $extra['code'] ) ) {
	echo 'Codice credito: ' . $extra['code'] . "\n\n";
}
if ( $appointment ) {
	echo 'Prenotazione: ' . $appointment->number() . "\n";
	echo 'Pacchetto: ' . $appointment->package_name() . "\n";
	echo 'Quando: ' . ucfirst( Time::format_datetime( $appointment->start_at ) ) . "\n";
	echo 'Totale: ' . wp_strip_all_tags( wc_price( $appointment->total ) ) . ' – Versato: ' . wp_strip_all_tags( wc_price( $appointment->paid ) ) . "\n\n";
}
if ( $delivery ) {
	echo 'Consegna: ' . $delivery['title'] . "\n";
	echo 'Scaricabile fino al: ' . Time::format_date( $delivery['expires_at'] ) . "\n\n";
}
if ( $button ) {
	echo $button[0] . ': ' . $button[1] . "\n\n";
}
if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n\n";
}
echo wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
