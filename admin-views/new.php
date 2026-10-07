<?php
/**
 * @var \WC_Product[] $packages
 * @var string        $start
 */

use PhotoBooking\Admin\Admin;
use PhotoBooking\Package;

defined( 'ABSPATH' ) || exit;

$date = $start ? substr( $start, 0, 10 ) : '';
$time = $start && strlen( $start ) >= 16 ? substr( $start, 11, 5 ) : '';
?>
<div class="wrap pb-wrap">
	<h1><?php esc_html_e( 'Nuovo appuntamento', 'photo-booking' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Per clienti che prenotano al telefono o in studio. L\'appuntamento compare subito nel calendario e nell\'area privata del cliente.', 'photo-booking' ); ?></p>

	<?php if ( ! $packages ) : ?>
		<div class="notice notice-warning inline"><p><?php echo wp_kses_post( sprintf( __( 'Crea prima almeno un prodotto di tipo "Pacchetto fotografico" in <a href="%s">Prodotti</a>.', 'photo-booking' ), esc_url( admin_url( 'post-new.php?post_type=product' ) ) ) ); ?></p></div>
	<?php else : ?>
	<?php Admin::form_open( 'new', 'class="pb-new-form"' ); ?>
		<div class="pb-detail">
			<div class="pb-detail-main">
				<div class="pb-box">
					<h2><?php esc_html_e( 'Cliente', 'photo-booking' ); ?></h2>
					<p><label><?php esc_html_e( 'Cerca cliente esistente', 'photo-booking' ); ?><br>
					<input type="search" class="regular-text pb-customer-search" placeholder="<?php esc_attr_e( 'Nome, email o telefono', 'photo-booking' ); ?>" autocomplete="off"></label></p>
					<ul class="pb-customer-results"></ul>
					<input type="hidden" name="customer_id" value="">
					<p class="pb-customer-selected" hidden></p>
					<p class="description"><?php esc_html_e( 'Oppure compila i dati di un nuovo cliente (verrà creato il suo account):', 'photo-booking' ); ?></p>
					<div class="pb-grid3">
						<label><?php esc_html_e( 'Nome e cognome', 'photo-booking' ); ?><input type="text" name="name" class="widefat"></label>
						<label><?php esc_html_e( 'Email', 'photo-booking' ); ?><input type="email" name="email" class="widefat"></label>
						<label><?php esc_html_e( 'Telefono', 'photo-booking' ); ?><input type="text" name="phone" class="widefat"></label>
					</div>
				</div>

				<div class="pb-box">
					<h2><?php esc_html_e( 'Appuntamento', 'photo-booking' ); ?></h2>
					<p><label><?php esc_html_e( 'Pacchetto', 'photo-booking' ); ?><br>
						<select name="product_id" data-product-select required>
							<?php foreach ( $packages as $p ) : ?>
								<?php $c = Package::config( $p ); ?>
								<option value="<?php echo esc_attr( $p->get_id() ); ?>" data-price="<?php echo esc_attr( $c['price'] ); ?>" data-deposit="<?php echo esc_attr( $c['deposit'] ); ?>"><?php echo esc_html( $p->get_name() . ' – ' . wp_strip_all_tags( wc_price( $c['price'] ) ) . ' – ' . $c['duration'] . ' min' ); ?></option>
							<?php endforeach; ?>
						</select></label></p>
					<div class="pb-slot-finder" data-product-from="[data-product-select]" data-target="new">
						<label><?php esc_html_e( 'Data', 'photo-booking' ); ?> <input type="date" name="date" class="pb-finder-date" value="<?php echo esc_attr( $date ); ?>" required></label>
						<label><?php esc_html_e( 'Ora', 'photo-booking' ); ?> <input type="time" name="time" step="300" value="<?php echo esc_attr( $time ); ?>" required data-new-time></label>
						<span class="pb-finder-slots"></span>
					</div>
					<p><label><input type="checkbox" name="force" value="1"> <?php esc_html_e( 'Inserisci anche se si sovrappone ad altri appuntamenti o blocchi', 'photo-booking' ); ?></label></p>
					<p><label><?php esc_html_e( 'Stato', 'photo-booking' ); ?><br>
						<select name="status">
							<option value="confirmed"><?php esc_html_e( 'Confermata', 'photo-booking' ); ?></option>
							<option value="pending"><?php esc_html_e( 'In attesa di conferma', 'photo-booking' ); ?></option>
						</select></label></p>
					<p><label><?php esc_html_e( 'Note', 'photo-booking' ); ?><br><textarea name="notes" rows="2" class="large-text"></textarea></label></p>
				</div>
			</div>
			<div class="pb-detail-side">
				<div class="pb-box">
					<h2><?php esc_html_e( 'Pagamento', 'photo-booking' ); ?></h2>
					<p><label><?php esc_html_e( 'Prezzo (lascia vuoto per il prezzo del pacchetto)', 'photo-booking' ); ?><br><input type="number" step="0.01" name="total"></label></p>
					<p>
						<label><input type="radio" name="payment" value="none" checked> <?php esc_html_e( 'Nessun pagamento ancora', 'photo-booking' ); ?></label><br>
						<label><input type="radio" name="payment" value="deposit_cash"> <?php esc_html_e( 'Acconto ricevuto in contanti', 'photo-booking' ); ?></label><br>
						<label><input type="radio" name="payment" value="full_cash"> <?php esc_html_e( 'Intero importo ricevuto in contanti', 'photo-booking' ); ?></label>
					</p>
					<p><label><?php esc_html_e( 'Importo acconto (vuoto = acconto del pacchetto)', 'photo-booking' ); ?><br><input type="number" step="0.01" name="cash_amount"></label></p>
					<p class="description"><?php esc_html_e( 'Il cliente potrà pagare online quanto resta dalla sua area privata.', 'photo-booking' ); ?></p>
					<p><label><input type="checkbox" name="notify" value="1" checked> <?php esc_html_e( 'Invia email di conferma al cliente', 'photo-booking' ); ?></label></p>
					<button class="button button-primary button-large"><?php esc_html_e( 'Crea appuntamento', 'photo-booking' ); ?></button>
				</div>
			</div>
		</div>
	</form>
	<?php endif; ?>
</div>
