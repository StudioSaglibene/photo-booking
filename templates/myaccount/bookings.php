<?php
/**
 * My account – bookings list.
 *
 * @var \PhotoBooking\Appointment[] $upcoming
 * @var \PhotoBooking\Appointment[] $past
 * @var \WC_Coupon[]                 $credits
 */

use PhotoBooking\Account;
use PhotoBooking\Appointment;
use PhotoBooking\Settings;
use PhotoBooking\Time;

defined( 'ABSPATH' ) || exit;

$card = static function ( Appointment $a ) {
	$needs = Appointment::PROPOSED === $a->status;
	?>
	<a class="pb-card<?php echo $needs ? ' is-attention' : ''; ?>" href="<?php echo esc_url( Account::booking_url( $a ) ); ?>">
		<span class="pb-card-date">
			<strong><?php echo esc_html( wp_date( 'j', $a->start()->getTimestamp() ) ); ?></strong>
			<?php echo esc_html( wp_date( 'M Y', $a->start()->getTimestamp() ) ); ?>
		</span>
		<span class="pb-card-body">
			<span class="pb-card-title"><?php echo esc_html( $a->package_name() ); ?></span>
			<span class="pb-card-meta"><?php echo esc_html( ucfirst( Time::format_date( $a->start_at ) ) . ' · ' . Time::format_time( $a->start_at ) ); ?></span>
			<span class="pb-badges">
				<span class="pb-badge pb-st-<?php echo esc_attr( $a->status ); ?>"><?php echo esc_html( $a->status_label() ); ?></span>
				<span class="pb-badge pb-pay-<?php echo esc_attr( $a->payment_status ); ?>"><?php echo esc_html( $a->payment_label() ); ?></span>
				<?php if ( $a->balance_due() > 0 && ! $a->is_closed() ) : ?>
					<span class="pb-badge pb-due"><?php echo wp_kses_post( sprintf( /* translators: %s amount */ __( 'Da saldare %s', 'photo-booking' ), wc_price( $a->balance_due() ) ) ); ?></span>
				<?php endif; ?>
			</span>
			<?php if ( $needs ) : ?>
				<span class="pb-card-attention"><?php esc_html_e( 'Ti abbiamo proposto nuove date: scegli quella che preferisci →', 'photo-booking' ); ?></span>
			<?php endif; ?>
		</span>
	</a>
	<?php
};
?>
<div class="pb-account">
	<?php if ( $credits ) : ?>
		<div class="pb-credit-box">
			<strong><?php esc_html_e( 'Il tuo credito', 'photo-booking' ); ?></strong>
			<?php foreach ( $credits as $c ) : ?>
				<div>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: 1 amount 2 code 3 date */
							__( '%1$s (codice %2$s) valido fino al %3$s', 'photo-booking' ),
							wc_price( $c->get_amount() ),
							'<code>' . esc_html( $c->get_code() ) . '</code>',
							esc_html( $c->get_date_expires() ? wp_date( 'j F Y', $c->get_date_expires()->getTimestamp() ) : '—' )
						)
					);
					?>
				</div>
			<?php endforeach; ?>
			<small><?php esc_html_e( 'Viene applicato automaticamente alla tua prossima prenotazione.', 'photo-booking' ); ?></small>
		</div>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Prossimi appuntamenti', 'photo-booking' ); ?></h3>
	<?php if ( $upcoming ) : ?>
		<div class="pb-cards"><?php array_map( $card, $upcoming ); ?></div>
	<?php else : ?>
		<p class="pb-muted"><?php esc_html_e( 'Non hai appuntamenti in programma.', 'photo-booking' ); ?> <a href="<?php echo esc_url( Settings::booking_page_url() ); ?>"><?php esc_html_e( 'Prenota una sessione', 'photo-booking' ); ?></a></p>
	<?php endif; ?>

	<?php if ( $past ) : ?>
		<h3><?php esc_html_e( 'Storico', 'photo-booking' ); ?></h3>
		<div class="pb-cards is-past"><?php array_map( $card, $past ); ?></div>
	<?php endif; ?>
</div>
