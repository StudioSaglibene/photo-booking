<?php
/**
 * @var array                          $list
 * @var bool                           $configured
 * @var \PhotoBooking\Appointment|null $prefill
 */

use PhotoBooking\Admin\Admin;
use PhotoBooking\Deliveries;
use PhotoBooking\Settings;
use PhotoBooking\Time;

defined( 'ABSPATH' ) || exit;
$labels = Deliveries::status_labels();
?>
<div class="wrap pb-wrap">
	<h1><?php esc_html_e( 'Consegne', 'photo-booking' ); ?></h1>

	<?php if ( ! $configured ) : ?>
		<div class="notice notice-warning inline"><p><?php echo wp_kses_post( sprintf( __( 'Per caricare le consegne configura Cloudflare R2 in <a href="%s">Impostazioni → Consegne</a>.', 'photo-booking' ), esc_url( admin_url( 'admin.php?page=pb-settings#r2' ) ) ) ); ?></p></div>
	<?php endif; ?>

	<div class="pb-box pb-upload" <?php echo $configured ? '' : 'aria-disabled="true"'; ?>>
		<h2><?php esc_html_e( 'Nuova consegna', 'photo-booking' ); ?></h2>
		<form id="pb-upload-form">
			<div class="pb-grid3">
				<div>
					<label><?php esc_html_e( 'Cliente', 'photo-booking' ); ?> *<br>
					<input type="search" class="widefat pb-customer-search" placeholder="<?php esc_attr_e( 'Cerca per nome, email, telefono', 'photo-booking' ); ?>" autocomplete="off" value="<?php echo $prefill ? esc_attr( $prefill->customer_name ) : ''; ?>"></label>
					<ul class="pb-customer-results"></ul>
					<input type="hidden" name="customer_id" value="<?php echo $prefill ? esc_attr( $prefill->customer_id ) : ''; ?>">
					<input type="hidden" name="appointment_id" value="<?php echo $prefill ? esc_attr( $prefill->id ) : ''; ?>">
					<p class="pb-customer-selected" <?php echo $prefill ? '' : 'hidden'; ?>><?php echo $prefill ? esc_html( $prefill->customer_name . ' – ' . $prefill->customer_email ) : ''; ?></p>
				</div>
				<label><?php esc_html_e( 'Nome della consegna', 'photo-booking' ); ?><br>
					<input type="text" name="title" class="widefat" placeholder="<?php esc_attr_e( 'es. Natale 2026 – galleria completa', 'photo-booking' ); ?>" value="<?php echo $prefill ? esc_attr( $prefill->package_name() . ' – ' . wp_date( 'j F Y', $prefill->start()->getTimestamp() ) ) : ''; ?>"></label>
				<label><?php esc_html_e( 'Disponibile per (giorni)', 'photo-booking' ); ?><br>
					<input type="number" name="days" min="1" value="<?php echo esc_attr( Settings::get( 'delivery_days' ) ); ?>" style="width:90px"></label>
			</div>
			<p class="pb-file-row">
				<input type="file" name="file" accept=".zip,application/zip,application/x-zip-compressed" required>
				<label><input type="checkbox" name="notify" value="1" checked> <?php esc_html_e( 'Avvisa il cliente via email a caricamento completato', 'photo-booking' ); ?></label>
			</p>
			<p>
				<button type="submit" class="button button-primary" <?php disabled( ! $configured ); ?>><?php esc_html_e( 'Carica su R2', 'photo-booking' ); ?></button>
				<button type="button" class="button pb-upload-cancel" hidden><?php esc_html_e( 'Interrompi', 'photo-booking' ); ?></button>
			</p>
			<div class="pb-progress-wrap" hidden>
				<div class="pb-progress-bar"><span></span></div>
				<p class="pb-progress-text"></p>
			</div>
		</form>
	</div>

	<h2><?php esc_html_e( 'Consegne caricate', 'photo-booking' ); ?></h2>
	<table class="widefat striped pb-table">
		<thead><tr>
			<th><?php esc_html_e( 'Cliente', 'photo-booking' ); ?></th>
			<th><?php esc_html_e( 'Consegna', 'photo-booking' ); ?></th>
			<th><?php esc_html_e( 'Dimensione', 'photo-booking' ); ?></th>
			<th><?php esc_html_e( 'Caricata', 'photo-booking' ); ?></th>
			<th><?php esc_html_e( 'Scadenza', 'photo-booking' ); ?></th>
			<th><?php esc_html_e( 'Stato', 'photo-booking' ); ?></th>
			<th><?php esc_html_e( 'Download', 'photo-booking' ); ?></th>
			<th></th>
		</tr></thead>
		<tbody>
		<?php if ( ! $list ) : ?><tr><td colspan="8"><?php esc_html_e( 'Nessuna consegna.', 'photo-booking' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $list as $d ) : ?>
			<?php $u = get_userdata( $d['customer_id'] ); ?>
			<tr>
				<td><?php echo $u ? '<a href="' . esc_url( admin_url( 'admin.php?page=pb-deliveries&customer=' . $u->ID ) ) . '">' . esc_html( trim( get_user_meta( $u->ID, 'billing_first_name', true ) . ' ' . get_user_meta( $u->ID, 'billing_last_name', true ) ) ?: $u->display_name ) . '</a><br><span class="pb-muted">' . esc_html( $u->user_email ) . '</span>' : '—'; ?></td>
				<td><strong><?php echo esc_html( $d['title'] ); ?></strong><br><span class="pb-muted"><?php echo esc_html( $d['filename'] ); ?></span></td>
				<td><?php echo esc_html( size_format( (int) $d['size'], 1 ) ); ?></td>
				<td><?php echo $d['uploaded_at'] ? esc_html( wp_date( 'd/m/Y', Time::parse( $d['uploaded_at'] )->getTimestamp() ) ) : '—'; ?></td>
				<td>
					<?php if ( in_array( $d['status'], array( 'active', 'expired' ), true ) ) : ?>
						<?php Admin::form_open( 'delivery_expiry', 'class="pb-inline-expiry"' ); ?>
							<input type="hidden" name="delivery" value="<?php echo esc_attr( $d['id'] ); ?>">
							<input type="date" name="expires" value="<?php echo esc_attr( substr( (string) $d['expires_at'], 0, 10 ) ); ?>">
							<button class="button button-small"><?php esc_html_e( 'Salva', 'photo-booking' ); ?></button>
						</form>
					<?php else : ?>
						<?php echo $d['expires_at'] ? esc_html( wp_date( 'd/m/Y', Time::parse( $d['expires_at'] )->getTimestamp() ) ) : '—'; ?>
					<?php endif; ?>
				</td>
				<td><span class="pb-dstatus pb-d-<?php echo esc_attr( $d['status'] ); ?>"><?php echo esc_html( $labels[ $d['status'] ] ?? $d['status'] ); ?></span>
					<?php if ( 'expired' === $d['status'] ) : ?><br><span class="pb-muted"><?php echo esc_html( sprintf( __( 'file eliminato il %s', 'photo-booking' ), wp_date( 'd/m/Y', Time::parse( $d['expires_at'] )->modify( '+' . (int) Settings::get( 'delivery_purge_days' ) . ' days' )->getTimestamp() ) ) ); ?></span><?php endif; ?>
				</td>
				<td><?php echo (int) $d['downloads']; ?><?php if ( $d['last_download_at'] ) : ?><br><span class="pb-muted"><?php echo esc_html( wp_date( 'd/m H:i', Time::parse( $d['last_download_at'] )->getTimestamp() ) ); ?></span><?php endif; ?></td>
				<td class="pb-actions-cell">
					<?php if ( in_array( $d['status'], array( 'active', 'expired' ), true ) ) : ?>
						<a class="button button-small" href="<?php echo esc_url( Deliveries::download_link( $d ) ); ?>"><?php esc_html_e( 'Scarica', 'photo-booking' ); ?></a>
					<?php endif; ?>
					<?php if ( 'active' === $d['status'] ) : ?>
						<?php Admin::form_open( 'delivery_notify', 'style="display:inline"' ); ?>
							<input type="hidden" name="delivery" value="<?php echo esc_attr( $d['id'] ); ?>">
							<button class="button button-small"><?php esc_html_e( 'Reinvia email', 'photo-booking' ); ?></button>
						</form>
					<?php endif; ?>
					<?php Admin::form_open( 'delivery_delete', 'style="display:inline"' ); ?>
						<input type="hidden" name="delivery" value="<?php echo esc_attr( $d['id'] ); ?>">
						<button class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Eliminare definitivamente la consegna e il file su R2?', 'photo-booking' ) ); ?>')"><?php esc_html_e( 'Elimina', 'photo-booking' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
