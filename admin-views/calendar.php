<?php
/**
 * @var \PhotoBooking\Appointment[] $pending
 */

use PhotoBooking\Admin\Admin;
use PhotoBooking\Appointment;
use PhotoBooking\Time;

defined( 'ABSPATH' ) || exit;
$colors = Appointment::status_colors();
?>
<div class="wrap pb-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Calendario', 'photo-booking' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=pb-new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Nuovo appuntamento', 'photo-booking' ); ?></a>
	<button type="button" class="page-title-action" id="pb-add-block"><?php esc_html_e( 'Blocca intervallo', 'photo-booking' ); ?></button>
	<hr class="wp-header-end">

	<div class="pb-cal-layout">
		<div class="pb-cal-main">
			<div class="pb-legend">
				<?php foreach ( array( Appointment::PENDING, Appointment::CONFIRMED, Appointment::PROPOSED, Appointment::COMPLETED, Appointment::NEEDS_ACTION, Appointment::PENDING_PAYMENT ) as $st ) : ?>
					<span><i style="background:<?php echo esc_attr( $colors[ $st ] ); ?>"></i><?php echo esc_html( Appointment::status_labels()[ $st ] ); ?></span>
				<?php endforeach; ?>
				<span><i style="background:#5f6b7a"></i><?php esc_html_e( 'Bloccato', 'photo-booking' ); ?></span>
				<label class="pb-legend-toggle"><input type="checkbox" id="pb-show-closed"> <?php esc_html_e( 'Mostra annullate/rifiutate', 'photo-booking' ); ?></label>
			</div>
			<div id="pb-calendar"></div>
			<p class="description"><?php esc_html_e( 'Trascina un appuntamento per spostarlo. Seleziona un intervallo vuoto per bloccarlo o creare un appuntamento. Clicca un blocco per modificarlo o liberarlo.', 'photo-booking' ); ?></p>
			<div id="pb-cal-fallback" hidden class="notice notice-error inline"><p><?php esc_html_e( 'Impossibile caricare il calendario (FullCalendar). Controlla la connessione o l\'URL in Impostazioni → Avanzate. Puoi comunque usare l\'Elenco.', 'photo-booking' ); ?></p></div>
		</div>
		<aside class="pb-cal-side">
			<h2><?php esc_html_e( 'Da gestire', 'photo-booking' ); ?> <span class="count">(<?php echo count( $pending ); ?>)</span></h2>
			<?php if ( ! $pending ) : ?>
				<p class="description"><?php esc_html_e( 'Nessuna richiesta in attesa. 🎉', 'photo-booking' ); ?></p>
			<?php endif; ?>
			<ul class="pb-pending">
				<?php foreach ( $pending as $a ) : ?>
					<li>
						<a href="<?php echo esc_url( $a->admin_url() ); ?>">
							<strong><?php echo esc_html( $a->customer_name ); ?></strong>
							<span><?php echo esc_html( $a->package_name() ); ?></span>
							<span><?php echo esc_html( ucfirst( Time::format_datetime( $a->start_at ) ) ); ?></span>
							<?php echo Admin::badge( $a ); // phpcs:ignore ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</aside>
	</div>

	<dialog id="pb-dialog" class="pb-dialog">
		<form method="dialog" class="pb-dialog-inner">
			<h2 class="pb-dialog-title"></h2>
			<p class="pb-dialog-range"></p>
			<div class="pb-dialog-fields">
				<p class="pb-dlg-dates">
					<label><?php esc_html_e( 'Dal', 'photo-booking' ); ?> <input type="datetime-local" name="start" step="300"></label>
					<label><?php esc_html_e( 'Al', 'photo-booking' ); ?> <input type="datetime-local" name="end" step="300"></label>
				</p>
				<p><label><?php esc_html_e( 'Motivo (interno)', 'photo-booking' ); ?><br><input type="text" name="reason" class="regular-text" placeholder="<?php esc_attr_e( 'es. Ferie, servizio esterno…', 'photo-booking' ); ?>"></label></p>
			</div>
			<p class="pb-dialog-msg"></p>
			<div class="pb-dialog-actions">
				<button type="button" class="button button-primary" data-act="block"><?php esc_html_e( 'Blocca', 'photo-booking' ); ?></button>
				<button type="button" class="button" data-act="appointment"><?php esc_html_e( 'Nuovo appuntamento qui', 'photo-booking' ); ?></button>
				<button type="button" class="button button-primary" data-act="save-block"><?php esc_html_e( 'Salva', 'photo-booking' ); ?></button>
				<button type="button" class="button button-link-delete" data-act="delete-block"><?php esc_html_e( 'Sblocca (elimina blocco)', 'photo-booking' ); ?></button>
				<button type="submit" class="button-link" value="cancel"><?php esc_html_e( 'Chiudi', 'photo-booking' ); ?></button>
			</div>
		</form>
	</dialog>
</div>
