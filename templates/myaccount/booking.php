<?php
/**
 * My account – single booking.
 *
 * @var \PhotoBooking\Appointment $a
 * @var string                    $view       ''|sposta|disdici
 * @var array                     $proposals
 * @var array                     $outcome
 * @var true|\WP_Error            $can_move
 * @var true|\WP_Error            $can_cancel
 * @var array                     $package
 */

use PhotoBooking\Account;
use PhotoBooking\Appointment;
use PhotoBooking\Availability;
use PhotoBooking\Policy;
use PhotoBooking\Settings;
use PhotoBooking\Time;

defined( 'ABSPATH' ) || exit;

$nonce = static function () use ( $a ) {
	wp_nonce_field( 'pb_account_' . $a->id );
	echo '<input type="hidden" name="appointment_id" value="' . esc_attr( $a->id ) . '">';
};
?>
<div class="pb-account pb-booking-detail">
	<p><a href="<?php echo esc_url( wc_get_account_endpoint_url( Account::EP_BOOKINGS ) ); ?>">← <?php esc_html_e( 'Tutte le prenotazioni', 'photo-booking' ); ?></a></p>

	<div class="pb-detail-head">
		<div>
			<h3><?php echo esc_html( $a->package_name() ); ?></h3>
			<p class="pb-detail-when"><?php echo esc_html( ucfirst( Time::format_date( $a->start_at ) ) ); ?> · <?php echo esc_html( Time::format_time( $a->start_at ) . ' – ' . Time::format_time( $a->end_at ) ); ?></p>
		</div>
		<span class="pb-badges">
			<span class="pb-badge pb-st-<?php echo esc_attr( $a->status ); ?>"><?php echo esc_html( $a->status_label() ); ?></span>
		</span>
	</div>

	<?php if ( Appointment::PENDING === $a->status ) : ?>
		<p class="pb-notice"><?php esc_html_e( 'Abbiamo ricevuto la tua richiesta: lo studio la confermerà al più presto e riceverai una email.', 'photo-booking' ); ?></p>
	<?php elseif ( Appointment::NEEDS_ACTION === $a->status || Appointment::PROPOSAL_EXPIRED === $a->status ) : ?>
		<p class="pb-notice"><?php esc_html_e( 'Lo studio ti contatterà a breve per concordare una nuova data.', 'photo-booking' ); ?></p>
	<?php endif; ?>

	<?php if ( $proposals ) : ?>
		<section class="pb-panel is-attention">
			<h4><?php esc_html_e( 'Scegli la nuova data', 'photo-booking' ); ?></h4>
			<p><?php esc_html_e( 'L\'orario che avevi scelto non è disponibile. Ti proponiamo queste alternative:', 'photo-booking' ); ?></p>
			<?php if ( $a->get_meta( 'proposal_message' ) ) : ?>
				<blockquote><?php echo esc_html( $a->get_meta( 'proposal_message' ) ); ?></blockquote>
			<?php endif; ?>
			<div class="pb-proposals">
				<?php foreach ( $proposals as $p ) : ?>
					<form method="post" class="pb-proposal">
						<?php $nonce(); ?>
						<input type="hidden" name="pb_account_action" value="accept_proposal">
						<input type="hidden" name="proposal_id" value="<?php echo esc_attr( $p['id'] ); ?>">
						<span><strong><?php echo esc_html( ucfirst( Time::format_date( $p['start_at'] ) ) ); ?></strong><br><?php echo esc_html( Time::format_time( $p['start_at'] ) . ' – ' . Time::format_time( $p['end_at'] ) ); ?></span>
						<button type="submit" class="button"><?php esc_html_e( 'Scelgo questa', 'photo-booking' ); ?></button>
					</form>
				<?php endforeach; ?>
			</div>
			<?php /* translators: %s date */ ?>
			<p class="pb-muted"><?php echo esc_html( sprintf( __( 'Le date restano riservate per te fino a %s.', 'photo-booking' ), Time::format_datetime( $proposals[0]['expires_at'] ) ) ); ?></p>
			<details class="pb-decline">
				<summary><?php esc_html_e( 'Nessuna data va bene', 'photo-booking' ); ?></summary>
				<form method="post">
					<?php $nonce(); ?>
					<input type="hidden" name="pb_account_action" value="decline_proposal">
					<p><?php esc_html_e( 'Cosa preferisci per l\'importo versato?', 'photo-booking' ); ?></p>
					<label class="pb-radio"><input type="radio" name="outcome" value="refund" checked> <?php echo wp_kses_post( sprintf( /* translators: %s amount */ __( 'Rimborso di %s', 'photo-booking' ), wc_price( $a->paid ) ) ); ?></label>
					<label class="pb-radio"><input type="radio" name="outcome" value="credit"> <?php echo wp_kses_post( sprintf( /* translators: %s amount */ __( 'Credito di %s per un altro servizio', 'photo-booking' ), wc_price( $a->paid ) ) ); ?></label>
					<button type="submit" class="button pb-danger" onclick="return confirm('<?php echo esc_js( __( 'Confermi? La prenotazione verrà annullata.', 'photo-booking' ) ); ?>');"><?php esc_html_e( 'Annulla la prenotazione', 'photo-booking' ); ?></button>
				</form>
			</details>
		</section>
	<?php endif; ?>

	<section class="pb-panel">
		<h4><?php esc_html_e( 'Pagamenti', 'photo-booking' ); ?></h4>
		<dl class="pb-summary">
			<dt><?php esc_html_e( 'Totale', 'photo-booking' ); ?></dt><dd><?php echo wp_kses_post( wc_price( $a->total ) ); ?></dd>
			<dt><?php esc_html_e( 'Versato', 'photo-booking' ); ?></dt><dd><?php echo wp_kses_post( wc_price( $a->paid ) ); ?></dd>
			<?php if ( ! $a->is_closed() ) : ?>
				<dt><?php esc_html_e( 'Da saldare', 'photo-booking' ); ?></dt><dd><strong><?php echo wp_kses_post( wc_price( $a->balance_due() ) ); ?></strong></dd>
			<?php endif; ?>
			<dt><?php esc_html_e( 'Stato', 'photo-booking' ); ?></dt><dd><?php echo esc_html( $a->payment_label() ); ?></dd>
		</dl>
		<?php if ( $a->balance_due() > 0 && in_array( $a->status, array( Appointment::PENDING, Appointment::CONFIRMED, Appointment::COMPLETED ), true ) ) : ?>
			<?php if ( $a->hold_expires_at && $a->paid <= 0 ) : ?>
				<p class="pb-deadline"><?php echo esc_html( sprintf( /* translators: %s date and time */ __( 'La data è riservata per te fino al %s: per confermarla paga l\'acconto entro quella data.', 'photo-booking' ), Time::format_datetime( $a->hold_expires_at ) ) ); ?></p>
			<?php endif; ?>
			<div class="pb-pay-actions">
				<?php if ( $a->deposit_due() > 0 && $a->deposit_due() < $a->balance_due() - 0.009 ) : ?>
					<form method="post" class="pb-inline">
						<?php $nonce(); ?>
						<input type="hidden" name="pb_account_action" value="pay_deposit">
						<button type="submit" class="button alt"><?php echo wp_kses_post( sprintf( /* translators: %s amount */ __( 'Paga l\'acconto (%s)', 'photo-booking' ), wc_price( $a->deposit_due() ) ) ); ?></button>
					</form>
					<form method="post" class="pb-inline">
						<?php $nonce(); ?>
						<input type="hidden" name="pb_account_action" value="pay_balance">
						<button type="submit" class="button"><?php echo wp_kses_post( sprintf( /* translators: %s amount */ __( 'Paga tutto (%s)', 'photo-booking' ), wc_price( $a->balance_due() ) ) ); ?></button>
					</form>
				<?php else : ?>
					<form method="post" class="pb-inline">
						<?php $nonce(); ?>
						<input type="hidden" name="pb_account_action" value="pay_balance">
						<button type="submit" class="button alt"><?php echo wp_kses_post( sprintf( /* translators: %s amount */ __( 'Paga il saldo (%s)', 'photo-booking' ), wc_price( $a->balance_due() ) ) ); ?></button>
					</form>
				<?php endif; ?>
				<span class="pb-muted"><?php esc_html_e( 'Oppure paga in studio, anche in contanti.', 'photo-booking' ); ?></span>
			</div>
		<?php endif; ?>
	</section>

	<?php if ( 'sposta' === $view ) : ?>
		<section class="pb-panel">
			<h4><?php esc_html_e( 'Sposta l\'appuntamento', 'photo-booking' ); ?></h4>
			<?php if ( is_wp_error( $can_move ) ) : ?>
				<p><?php echo esc_html( $can_move->get_error_message() ); ?></p>
			<?php else : ?>
				<p class="pb-muted"><?php esc_html_e( 'Scegli una nuova data e un nuovo orario. Lo spostamento gratuito si può usare una sola volta e la nuova data dovrà essere confermata dallo studio.', 'photo-booking' ); ?></p>
				<form method="post">
					<?php $nonce(); ?>
					<input type="hidden" name="pb_account_action" value="reschedule">
					<input type="hidden" name="start" value="">
					<div class="pb-picker" data-pb-picker data-product="<?php echo esc_attr( $a->product_id ); ?>" data-exclude="<?php echo esc_attr( $a->id ); ?>" data-first="<?php echo esc_attr( Availability::first_month( $a->product_id ) ); ?>"></div>
					<p><button type="submit" class="button alt"><?php esc_html_e( 'Conferma il nuovo orario', 'photo-booking' ); ?></button>
					<a href="<?php echo esc_url( Account::booking_url( $a ) ); ?>"><?php esc_html_e( 'Annulla', 'photo-booking' ); ?></a></p>
				</form>
			<?php endif; ?>
		</section>
	<?php elseif ( 'disdici' === $view ) : ?>
		<section class="pb-panel">
			<h4><?php esc_html_e( 'Disdici la prenotazione', 'photo-booking' ); ?></h4>
			<?php if ( is_wp_error( $can_cancel ) ) : ?>
				<p><?php echo esc_html( $can_cancel->get_error_message() ); ?></p>
			<?php else : ?>
				<p><?php echo esc_html( $outcome['description'] ); ?></p>
				<form method="post">
					<?php $nonce(); ?>
					<input type="hidden" name="pb_account_action" value="cancel">
					<?php if ( Policy::OUTCOME_REFUND === $outcome['type'] && $a->paid > 0 ) : ?>
						<input type="hidden" name="outcome" value="refund">
						<p><strong><?php echo wp_kses_post( sprintf( /* translators: %s amount */ __( 'Ti verranno rimborsati %s.', 'photo-booking' ), wc_price( $a->paid ) ) ); ?></strong></p>
					<?php elseif ( Policy::OUTCOME_CREDIT === $outcome['type'] && $a->paid > 0 ) : ?>
						<input type="hidden" name="outcome" value="credit">
						<p><strong><?php echo wp_kses_post( sprintf( /* translators: %s amount */ __( 'Riceverai un credito di %s.', 'photo-booking' ), wc_price( $a->paid ) ) ); ?></strong></p>
					<?php endif; ?>
					<p class="pb-field"><label for="pb-reason"><?php esc_html_e( 'Vuoi dirci il motivo? (facoltativo)', 'photo-booking' ); ?></label><textarea id="pb-reason" name="reason" rows="2"></textarea></p>
					<p><button type="submit" class="button pb-danger" onclick="return confirm('<?php echo esc_js( __( 'Confermi la disdetta? L\'operazione non è reversibile.', 'photo-booking' ) ); ?>');"><?php esc_html_e( 'Conferma la disdetta', 'photo-booking' ); ?></button>
					<a href="<?php echo esc_url( Account::booking_url( $a ) ); ?>"><?php esc_html_e( 'Torna indietro', 'photo-booking' ); ?></a></p>
				</form>
			<?php endif; ?>
		</section>
	<?php else : ?>
		<?php if ( ! $a->is_closed() && Appointment::COMPLETED !== $a->status ) : ?>
			<p class="pb-actions">
				<?php if ( true === $can_move ) : ?>
					<a class="button" href="<?php echo esc_url( Account::booking_url( $a, array( 'azione' => 'sposta' ) ) ); ?>"><?php esc_html_e( 'Sposta', 'photo-booking' ); ?></a>
				<?php elseif ( in_array( $a->status, array( Appointment::PENDING, Appointment::CONFIRMED ), true ) ) : ?>
					<span class="pb-muted"><?php echo esc_html( $can_move->get_error_message() ); ?></span>
				<?php endif; ?>
				<?php if ( true === $can_cancel ) : ?>
					<a class="pb-link-danger" href="<?php echo esc_url( Account::booking_url( $a, array( 'azione' => 'disdici' ) ) ); ?>"><?php esc_html_e( 'Disdici', 'photo-booking' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( ! empty( $package['instructions'] ) && in_array( $a->status, array( Appointment::CONFIRMED, Appointment::PENDING ), true ) ) : ?>
		<section class="pb-panel">
			<h4><?php esc_html_e( 'Come prepararsi', 'photo-booking' ); ?></h4>
			<?php echo wp_kses_post( wpautop( $package['instructions'] ) ); ?>
		</section>
	<?php endif; ?>

	<?php if ( Settings::get( 'studio_address' ) ) : ?>
		<p class="pb-muted"><?php echo esc_html( Settings::get( 'studio_address' ) ); ?></p>
	<?php endif; ?>

	<details class="pb-policy-box">
		<summary><?php esc_html_e( 'Condizioni di disdetta e spostamento', 'photo-booking' ); ?></summary>
		<?php echo wp_kses_post( Policy::policy_html() ); ?>
	</details>
</div>
