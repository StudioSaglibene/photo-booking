<?php
/**
 * @var \PhotoBooking\Appointment $a
 * @var array        $proposals
 * @var array        $outcome
 * @var \WC_Order[]  $orders
 */

use PhotoBooking\Admin\Admin;
use PhotoBooking\Appointment;
use PhotoBooking\Payments;
use PhotoBooking\Policy;
use PhotoBooking\Settings;
use PhotoBooking\Time;

defined( 'ABSPATH' ) || exit;

$st          = $a->status;
$is_request  = in_array( $st, array( Appointment::PENDING, Appointment::PROPOSAL_EXPIRED, Appointment::NEEDS_ACTION ), true );
$can_propose = in_array( $st, array( Appointment::PENDING, Appointment::PROPOSED, Appointment::PROPOSAL_EXPIRED, Appointment::NEEDS_ACTION, Appointment::CONFIRMED ), true );
$can_reject  = in_array( $st, array( Appointment::PENDING, Appointment::PROPOSED, Appointment::PROPOSAL_EXPIRED, Appointment::NEEDS_ACTION ), true );
$can_move    = in_array( $st, array( Appointment::PENDING, Appointment::CONFIRMED, Appointment::COMPLETED ), true );
$can_cancel  = in_array( $st, array( Appointment::CONFIRMED, Appointment::COMPLETED ), true );
$due         = $a->balance_due();
$max_props   = max( 1, (int) Settings::get( 'proposal_max_slots' ) );
$labels      = Payments::type_labels();
$finder      = static function ( $target ) use ( $a ) {
	echo '<div class="pb-slot-finder" data-product="' . esc_attr( $a->product_id ) . '" data-exclude="' . esc_attr( $a->id ) . '" data-target="' . esc_attr( $target ) . '"><label>' . esc_html__( 'Trova slot liberi il', 'photo-booking' ) . ' <input type="date" class="pb-finder-date"></label><span class="pb-finder-slots"></span></div>';
};
?>
<div class="wrap pb-wrap">
	<h1 class="wp-heading-inline"><?php echo esc_html( sprintf( __( 'Prenotazione %s', 'photo-booking' ), $a->number() ) ); ?></h1>
	<?php echo Admin::badge( $a ); // phpcs:ignore ?>
	<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=pb-calendar' ) ); ?>"><?php esc_html_e( '← Calendario', 'photo-booking' ); ?></a>
	<hr class="wp-header-end">

	<div class="pb-detail">
		<div class="pb-detail-main">

			<div class="pb-box pb-hero">
				<div>
					<div class="pb-hero-when"><?php echo esc_html( ucfirst( Time::format_date( $a->start_at ) ) ); ?></div>
					<div class="pb-hero-time"><?php echo esc_html( Time::format_time( $a->start_at ) . ' – ' . Time::format_time( $a->end_at ) ); ?></div>
					<div class="pb-hero-pkg"><?php echo esc_html( $a->package_name() ); ?></div>
				</div>
				<div class="pb-hero-money">
					<div><span><?php esc_html_e( 'Totale', 'photo-booking' ); ?></span><strong><?php echo wp_kses_post( wc_price( $a->total ) ); ?></strong></div>
					<div><span><?php esc_html_e( 'Versato', 'photo-booking' ); ?></span><strong><?php echo wp_kses_post( wc_price( $a->paid ) ); ?></strong></div>
					<div class="<?php echo $due > 0 && ! $a->is_closed() ? 'is-due' : ''; ?>"><span><?php esc_html_e( 'Da saldare', 'photo-booking' ); ?></span><strong><?php echo wp_kses_post( wc_price( $a->is_closed() ? 0 : $due ) ); ?></strong></div>
					<div class="pb-hero-pay"><?php echo esc_html( $a->payment_label() ); ?></div>
				</div>
			</div>

			<?php if ( $a->get_meta( 'slot_conflict' ) ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Il pagamento è arrivato dopo la scadenza del blocco e lo slot nel frattempo è stato occupato: proponi un\'altra data o rimborsa.', 'photo-booking' ); ?></p></div>
			<?php endif; ?>

			<?php if ( Appointment::PROPOSED === $st ) : ?>
				<div class="notice notice-info inline"><p>
					<?php
					/* translators: %s date */
					echo esc_html( sprintf( __( 'In attesa che il cliente scelga una delle date proposte (scadenza %s).', 'photo-booking' ), Time::format_datetime( $a->get_meta( 'proposal_expires' ) ) ) );
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( $is_request ) : ?>
				<div class="pb-box pb-decide">
					<h2><?php esc_html_e( 'Decidi sulla richiesta', 'photo-booking' ); ?></h2>
					<?php Admin::form_open( 'confirm', 'class="pb-inline-form"' ); ?>
						<input type="hidden" name="id" value="<?php echo esc_attr( $a->id ); ?>">
						<textarea name="message" rows="2" placeholder="<?php esc_attr_e( 'Messaggio facoltativo per il cliente', 'photo-booking' ); ?>"></textarea>
						<button class="button button-primary button-hero"><?php esc_html_e( '✓ Conferma', 'photo-booking' ); ?></button>
					</form>
				</div>
			<?php endif; ?>

			<?php if ( $can_propose ) : ?>
				<details class="pb-box" <?php echo $is_request ? 'open' : ''; ?>>
					<summary><h2><?php echo Appointment::CONFIRMED === $st ? esc_html__( 'Proponi altre date (lo studio non è disponibile)', 'photo-booking' ) : esc_html__( 'Proponi un\'altra data', 'photo-booking' ); ?></h2></summary>
					<?php Admin::form_open( 'propose' ); ?>
						<input type="hidden" name="id" value="<?php echo esc_attr( $a->id ); ?>">
						<p class="description"><?php echo esc_html( sprintf( __( 'Proponi da 1 a %1$d date. Restano riservate per %2$d ore: il cliente sceglie dall\'area privata, oppure chiede rimborso/credito.', 'photo-booking' ), $max_props, (int) Settings::get( 'proposal_hours' ) ) ); ?></p>
						<?php $finder( 'propose' ); ?>
						<div class="pb-propose-inputs">
							<?php for ( $i = 0; $i < $max_props; $i++ ) : ?>
								<input type="datetime-local" name="starts[]" step="300" data-propose>
							<?php endfor; ?>
						</div>
						<p><textarea name="message" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Messaggio per il cliente (facoltativo)', 'photo-booking' ); ?>"></textarea></p>
						<button class="button button-primary"><?php esc_html_e( 'Invia proposta', 'photo-booking' ); ?></button>
					</form>
				</details>
			<?php endif; ?>

			<?php if ( $can_reject ) : ?>
				<details class="pb-box">
					<summary><h2><?php esc_html_e( 'Rifiuta la richiesta', 'photo-booking' ); ?></h2></summary>
					<?php Admin::form_open( 'reject' ); ?>
						<input type="hidden" name="id" value="<?php echo esc_attr( $a->id ); ?>">
						<?php if ( $a->paid > 0 ) : ?>
							<p><label><input type="radio" name="outcome" value="refund" checked> <?php echo wp_kses_post( sprintf( __( 'Rimborsa %s (automatico per i pagamenti con carta)', 'photo-booking' ), wc_price( $a->paid ) ) ); ?></label><br>
							<label><input type="radio" name="outcome" value="credit"> <?php echo wp_kses_post( sprintf( __( 'Converti %s in credito', 'photo-booking' ), wc_price( $a->paid ) ) ); ?></label></p>
						<?php endif; ?>
						<p><textarea name="message" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Motivo / messaggio per il cliente', 'photo-booking' ); ?>"></textarea></p>
						<button class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Confermi il rifiuto?', 'photo-booking' ) ); ?>')"><?php esc_html_e( 'Rifiuta', 'photo-booking' ); ?></button>
					</form>
				</details>
			<?php endif; ?>

			<?php if ( ! $a->is_closed() && $due > 0 ) : ?>
				<div class="pb-box">
					<h2><?php esc_html_e( 'Saldo', 'photo-booking' ); ?></h2>
					<div class="pb-two">
						<?php Admin::form_open( 'cash' ); ?>
							<input type="hidden" name="id" value="<?php echo esc_attr( $a->id ); ?>">
							<p><label><?php esc_html_e( 'Ricevuto in contanti', 'photo-booking' ); ?><br>
							<input type="number" step="0.01" min="0.01" name="amount" value="<?php echo esc_attr( wc_format_decimal( $due, 2 ) ); ?>" class="small-text" style="width:110px"> <?php echo esc_html( get_woocommerce_currency_symbol() ); ?></label></p>
							<p><input type="text" name="note" class="regular-text" placeholder="<?php esc_attr_e( 'Nota (facoltativa)', 'photo-booking' ); ?>"></p>
							<p><label><input type="checkbox" name="notify" value="1" checked> <?php esc_html_e( 'Invia email "pagamento ricevuto"', 'photo-booking' ); ?></label></p>
							<button class="button button-primary"><?php esc_html_e( 'Registra saldo in contanti', 'photo-booking' ); ?></button>
						</form>
						<?php Admin::form_open( 'payment_link' ); ?>
							<input type="hidden" name="id" value="<?php echo esc_attr( $a->id ); ?>">
							<p class="description"><?php esc_html_e( 'Il cliente può pagare il saldo dalla sua area privata. Puoi anche generare un link di pagamento da inviare (es. su WhatsApp).', 'photo-booking' ); ?></p>
							<button class="button"><?php esc_html_e( 'Genera link di pagamento', 'photo-booking' ); ?></button>
						</form>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $can_move ) : ?>
				<details class="pb-box">
					<summary><h2><?php esc_html_e( 'Sposta', 'photo-booking' ); ?></h2></summary>
					<?php Admin::form_open( 'move' ); ?>
						<input type="hidden" name="id" value="<?php echo esc_attr( $a->id ); ?>">
						<?php $finder( 'move' ); ?>
						<p><input type="datetime-local" name="start" step="300" value="<?php echo esc_attr( Admin::to_input( $a->start_at ) ); ?>" data-move></p>
						<p><label><input type="checkbox" name="notify" value="1" checked> <?php esc_html_e( 'Avvisa il cliente via email', 'photo-booking' ); ?></label>
						<label style="margin-left:12px"><input type="checkbox" name="force" value="1"> <?php esc_html_e( 'Forza anche se sovrapposto', 'photo-booking' ); ?></label></p>
						<p><textarea name="message" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Messaggio per il cliente (facoltativo)', 'photo-booking' ); ?>"></textarea></p>
						<button class="button button-primary"><?php esc_html_e( 'Sposta', 'photo-booking' ); ?></button>
					</form>
				</details>
			<?php endif; ?>

			<?php if ( $can_cancel ) : ?>
				<details class="pb-box">
					<summary><h2><?php esc_html_e( 'Annulla appuntamento', 'photo-booking' ); ?></h2></summary>
					<?php Admin::form_open( 'cancel' ); ?>
						<input type="hidden" name="id" value="<?php echo esc_attr( $a->id ); ?>">
						<p class="description"><?php echo esc_html( sprintf( __( 'Secondo la policy (mancano %1$s ore): %2$s', 'photo-booking' ), number_format_i18n( max( 0, $outcome['hours'] ), 0 ), $outcome['description'] ) ); ?></p>
						<?php
						$def = Policy::OUTCOME_LOST === $outcome['type'] ? 'lost' : ( Policy::OUTCOME_CREDIT === $outcome['type'] ? 'credit' : 'refund' );
						?>
						<p>
							<label><input type="radio" name="outcome" value="refund" <?php checked( $def, 'refund' ); ?>> <?php echo wp_kses_post( sprintf( __( 'Rimborsa %s', 'photo-booking' ), wc_price( $a->paid ) ) ); ?></label><br>
							<label><input type="radio" name="outcome" value="credit" <?php checked( $def, 'credit' ); ?>> <?php echo wp_kses_post( sprintf( __( 'Converti %s in credito', 'photo-booking' ), wc_price( $a->paid ) ) ); ?></label><br>
							<label><input type="radio" name="outcome" value="lost" <?php checked( $def, 'lost' ); ?>> <?php esc_html_e( 'Trattieni l\'importo (disdetta tardiva / mancata presentazione)', 'photo-booking' ); ?></label>
						</p>
						<p><textarea name="message" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Messaggio per il cliente (facoltativo)', 'photo-booking' ); ?>"></textarea></p>
						<button class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Confermi l\'annullamento?', 'photo-booking' ) ); ?>')"><?php esc_html_e( 'Annulla appuntamento', 'photo-booking' ); ?></button>
					</form>
				</details>
			<?php endif; ?>

			<?php if ( Appointment::CONFIRMED === $st ) : ?>
				<div class="pb-box pb-row-actions">
					<?php Admin::form_open( 'complete', 'style="display:inline"' ); ?>
						<input type="hidden" name="id" value="<?php echo esc_attr( $a->id ); ?>">
						<button class="button"><?php esc_html_e( 'Segna come svolta', 'photo-booking' ); ?></button>
					</form>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=pb-deliveries&appointment=' . $a->id ) ); ?>"><?php esc_html_e( 'Carica consegna foto', 'photo-booking' ); ?></a>
				</div>
			<?php elseif ( Appointment::COMPLETED === $st ) : ?>
				<div class="pb-box pb-row-actions">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=pb-deliveries&appointment=' . $a->id ) ); ?>"><?php esc_html_e( 'Carica consegna foto', 'photo-booking' ); ?></a>
				</div>
			<?php endif; ?>

			<?php if ( $proposals ) : ?>
				<div class="pb-box">
					<h2><?php esc_html_e( 'Proposte', 'photo-booking' ); ?></h2>
					<table class="widefat striped"><tbody>
						<?php foreach ( $proposals as $p ) : ?>
							<tr><td><?php echo esc_html( ucfirst( Time::format_datetime( $p['start_at'] ) ) ); ?></td><td><?php echo esc_html( array( 'open' => __( 'In attesa', 'photo-booking' ), 'accepted' => __( 'Accettata', 'photo-booking' ), 'declined' => __( 'Non scelta', 'photo-booking' ), 'expired' => __( 'Scaduta', 'photo-booking' ), 'cancelled' => __( 'Ritirata', 'photo-booking' ) )[ $p['status'] ] ?? $p['status'] ); ?></td><td class="pb-muted"><?php echo esc_html( sprintf( __( 'scade %s', 'photo-booking' ), Time::format_datetime( $p['expires_at'] ) ) ); ?></td></tr>
						<?php endforeach; ?>
					</tbody></table>
				</div>
			<?php endif; ?>

			<div class="pb-box">
				<h2><?php esc_html_e( 'Movimenti', 'photo-booking' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Data', 'photo-booking' ); ?></th><th><?php esc_html_e( 'Tipo', 'photo-booking' ); ?></th><th class="num"><?php esc_html_e( 'Importo', 'photo-booking' ); ?></th><th><?php esc_html_e( 'Ordine', 'photo-booking' ); ?></th><th><?php esc_html_e( 'Note', 'photo-booking' ); ?></th></tr></thead>
					<tbody>
					<?php $rows = $a->payments(); ?>
					<?php if ( ! $rows ) : ?><tr><td colspan="5"><?php esc_html_e( 'Nessun movimento.', 'photo-booking' ); ?></td></tr><?php endif; ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'd/m/Y H:i', Time::parse( $r['created_at'] )->getTimestamp() ) ); ?></td>
							<td><?php echo esc_html( $labels[ $r['type'] ] ?? $r['type'] ); ?></td>
							<td class="num"><?php echo wp_kses_post( wc_price( $r['amount'] ) ); ?></td>
							<td><?php if ( $r['order_id'] ) : ?><a href="<?php echo esc_url( ( $o = wc_get_order( $r['order_id'] ) ) ? $o->get_edit_order_url() : '#' ); ?>">#<?php echo esc_html( $r['order_id'] ); ?></a><?php endif; ?></td>
							<td><?php echo esc_html( $r['method'] === 'coupon' ? $r['note'] : $r['note'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="pb-detail-side">
			<div class="pb-box">
				<h2><?php esc_html_e( 'Cliente', 'photo-booking' ); ?></h2>
				<p><strong><?php echo esc_html( $a->customer_name ); ?></strong><br>
				<a href="mailto:<?php echo esc_attr( $a->customer_email ); ?>"><?php echo esc_html( $a->customer_email ); ?></a><br>
				<a href="tel:<?php echo esc_attr( preg_replace( '/[^\d+]/', '', $a->customer_phone ) ); ?>"><?php echo esc_html( $a->customer_phone ); ?></a>
				<?php $wa = preg_replace( '/\D/', '', $a->customer_phone ); ?>
				<?php if ( $wa ) : ?> · <a target="_blank" rel="noopener" href="https://wa.me/<?php echo esc_attr( strlen( $wa ) <= 10 ? '39' . $wa : $wa ); ?>">WhatsApp</a><?php endif; ?></p>
				<?php if ( $a->extras_label() ) : ?><p><span class="pb-muted"><?php esc_html_e( 'Extra', 'photo-booking' ); ?>:</span> <?php echo esc_html( $a->extras_label() ); ?></p><?php endif; ?>
				<?php if ( $a->get_meta( 'participants' ) ) : ?><p><span class="pb-muted"><?php esc_html_e( 'Partecipanti', 'photo-booking' ); ?>:</span> <?php echo esc_html( $a->get_meta( 'participants' ) ); ?></p><?php endif; ?>
				<?php if ( $a->get_meta( 'children' ) ) : ?><p><span class="pb-muted"><?php esc_html_e( 'Età bambini', 'photo-booking' ); ?>:</span> <?php echo esc_html( $a->get_meta( 'children' ) ); ?></p><?php endif; ?>
				<?php if ( $a->customer_notes ) : ?><p><span class="pb-muted"><?php esc_html_e( 'Note cliente', 'photo-booking' ); ?>:</span><br><?php echo nl2br( esc_html( $a->customer_notes ) ); ?></p><?php endif; ?>
				<?php if ( $a->customer_id ) : ?>
					<p><a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . $a->customer_id ) ); ?>"><?php esc_html_e( 'Profilo utente', 'photo-booking' ); ?></a> · <a href="<?php echo esc_url( admin_url( 'admin.php?page=pb-deliveries&customer=' . $a->customer_id ) ); ?>"><?php esc_html_e( 'Consegne', 'photo-booking' ); ?></a></p>
				<?php endif; ?>
			</div>

			<div class="pb-box">
				<h2><?php esc_html_e( 'Ordini WooCommerce', 'photo-booking' ); ?></h2>
				<?php if ( ! $orders ) : ?><p class="pb-muted"><?php esc_html_e( 'Nessun ordine collegato.', 'photo-booking' ); ?></p><?php endif; ?>
				<ul>
					<?php foreach ( $orders as $o ) : ?>
						<li><a href="<?php echo esc_url( $o->get_edit_order_url() ); ?>">#<?php echo esc_html( $o->get_id() ); ?></a> – <?php echo wp_kses_post( wc_price( $o->get_total() ) ); ?> – <?php echo esc_html( wc_get_order_status_name( $o->get_status() ) ); ?> <span class="pb-muted"><?php echo esc_html( $o->get_payment_method_title() ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>

			<details class="pb-box">
				<summary><h2><?php esc_html_e( 'Modifica dati', 'photo-booking' ); ?></h2></summary>
				<?php Admin::form_open( 'edit' ); ?>
					<input type="hidden" name="id" value="<?php echo esc_attr( $a->id ); ?>">
					<p><label><?php esc_html_e( 'Nome', 'photo-booking' ); ?><br><input type="text" name="customer_name" class="widefat" value="<?php echo esc_attr( $a->customer_name ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Email', 'photo-booking' ); ?><br><input type="email" name="customer_email" class="widefat" value="<?php echo esc_attr( $a->customer_email ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Telefono', 'photo-booking' ); ?><br><input type="text" name="customer_phone" class="widefat" value="<?php echo esc_attr( $a->customer_phone ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Totale pacchetto', 'photo-booking' ); ?><br><input type="number" step="0.01" name="total" value="<?php echo esc_attr( wc_format_decimal( $a->total, 2 ) ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Note cliente', 'photo-booking' ); ?><br><textarea name="customer_notes" class="widefat" rows="2"><?php echo esc_textarea( $a->customer_notes ); ?></textarea></label></p>
					<p><label><?php esc_html_e( 'Note interne', 'photo-booking' ); ?><br><textarea name="admin_notes" class="widefat" rows="3"><?php echo esc_textarea( $a->admin_notes ); ?></textarea></label></p>
					<button class="button"><?php esc_html_e( 'Salva', 'photo-booking' ); ?></button>
				</form>
			</details>
			<?php if ( $a->admin_notes ) : ?>
				<div class="pb-box pb-note"><strong><?php esc_html_e( 'Note interne', 'photo-booking' ); ?></strong><br><?php echo nl2br( esc_html( $a->admin_notes ) ); ?></div>
			<?php endif; ?>

			<div class="pb-box">
				<h2><?php esc_html_e( 'Storico', 'photo-booking' ); ?></h2>
				<ul class="pb-history">
					<?php foreach ( $a->history() as $h ) : ?>
						<?php $u = $h['user_id'] ? get_userdata( $h['user_id'] ) : null; ?>
						<li><span class="pb-muted"><?php echo esc_html( wp_date( 'd/m H:i', Time::parse( $h['created_at'] )->getTimestamp() ) ); ?><?php echo $u ? ' · ' . esc_html( $u->display_name ) : ''; ?></span><br><?php echo esc_html( $h['message'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
	</div>
</div>
