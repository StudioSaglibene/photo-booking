<?php
/**
 * @var \WC_Product[] $packages
 * @var string        $start
 * @var array|null    $customer
 * @var array|null    $order     paid WooCommerce order to link (id, product, total, paid, name, email, phone)
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
	<?php if ( $order ) : ?>
		<div class="notice notice-info inline"><p><?php echo wp_kses_post( sprintf( __( 'Stai creando la prenotazione per l\'<strong>ordine #%1$d</strong> di %2$s (%3$s, %4$s): scegli data e ora, il pagamento dell\'ordine verrà collegato alla prenotazione.', 'photo-booking' ), $order['id'], esc_html( $order['name'] ), wp_strip_all_tags( wc_price( $order['total'] ) ), $order['paid'] ? __( 'pagato', 'photo-booking' ) : __( 'non ancora pagato', 'photo-booking' ) ) ); ?></p></div>
	<?php endif; ?>
	<?php Admin::form_open( 'new', 'class="pb-new-form"' ); ?>
		<?php if ( $order ) : ?><input type="hidden" name="order_id" value="<?php echo esc_attr( $order['id'] ); ?>"><?php endif; ?>
		<div class="pb-detail">
			<div class="pb-detail-main">
				<div class="pb-box">
					<h2><?php esc_html_e( 'Cliente', 'photo-booking' ); ?></h2>
					<p><label><?php esc_html_e( 'Cerca cliente esistente', 'photo-booking' ); ?><br>
					<input type="search" class="regular-text pb-customer-search" placeholder="<?php esc_attr_e( 'Nome, email o telefono', 'photo-booking' ); ?>" autocomplete="off"></label></p>
					<ul class="pb-customer-results"></ul>
					<input type="hidden" name="customer_id" value="<?php echo esc_attr( $customer['id'] ?? '' ); ?>">
					<p class="pb-customer-selected" <?php echo $customer ? '' : 'hidden'; ?>><?php echo $customer ? esc_html( $customer['name'] . ' · ' . $customer['email'] . ( $customer['phone'] ? ' · ' . $customer['phone'] : '' ) ) : ''; ?></p>
					<p class="description"><?php esc_html_e( 'Oppure compila i dati di un nuovo cliente (verrà creato il suo account):', 'photo-booking' ); ?></p>
					<div class="pb-grid3">
						<label><?php esc_html_e( 'Nome e cognome', 'photo-booking' ); ?><input type="text" name="name" class="widefat" value="<?php echo esc_attr( $customer ? '' : ( $order['name'] ?? '' ) ); ?>"></label>
						<label><?php esc_html_e( 'Email', 'photo-booking' ); ?><input type="email" name="email" class="widefat" value="<?php echo esc_attr( $customer ? '' : ( $order['email'] ?? '' ) ); ?>"></label>
						<label><?php esc_html_e( 'Telefono', 'photo-booking' ); ?><input type="text" name="phone" class="widefat" value="<?php echo esc_attr( $customer ? '' : ( $order['phone'] ?? '' ) ); ?>"></label>
					</div>
				</div>

				<div class="pb-box">
					<h2><?php esc_html_e( 'Appuntamento', 'photo-booking' ); ?></h2>
					<p><label><?php esc_html_e( 'Pacchetto', 'photo-booking' ); ?><br>
						<select name="product_id" data-product-select required>
							<?php foreach ( $packages as $p ) : ?>
								<?php $c = Package::config( $p ); ?>
								<option value="<?php echo esc_attr( $p->get_id() ); ?>" <?php selected( (int) ( $order['product'] ?? 0 ), $p->get_id() ); ?> data-price="<?php echo esc_attr( $c['price'] ); ?>" data-deposit="<?php echo esc_attr( $c['deposit'] ); ?>"><?php echo esc_html( $p->get_name() . ' – ' . wp_strip_all_tags( wc_price( $c['price'] ) ) . ' – ' . $c['duration'] . ' min' ); ?></option>
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
					<?php if ( $order ) : ?>
						<p><?php echo wp_kses_post( sprintf( __( 'Viene usato l\'ordine #%1$d (%2$s): nessun altro pagamento da registrare ora.', 'photo-booking' ), $order['id'], wp_strip_all_tags( wc_price( $order['total'] ) ) ) ); ?></p>
					<?php endif; ?>
					<div <?php echo $order ? 'hidden' : ''; ?>>
					<p><label><?php esc_html_e( 'Prezzo (lascia vuoto per il prezzo del pacchetto)', 'photo-booking' ); ?><br><input type="number" step="0.01" name="total"></label></p>
					<p>
						<label><input type="radio" name="payment" value="none" checked> <?php esc_html_e( 'Nessun pagamento ancora', 'photo-booking' ); ?></label><br>
						<label><input type="radio" name="payment" value="deposit_cash"> <?php esc_html_e( 'Acconto ricevuto in contanti', 'photo-booking' ); ?></label><br>
						<label><input type="radio" name="payment" value="full_cash"> <?php esc_html_e( 'Intero importo ricevuto in contanti', 'photo-booking' ); ?></label><br>
						<label><input type="radio" name="payment" value="gift"> <?php esc_html_e( 'Omaggio: nessun pagamento dovuto (ordine a 0 €)', 'photo-booking' ); ?></label>
					</p>
					<p><label><?php esc_html_e( 'Importo acconto (vuoto = acconto del pacchetto)', 'photo-booking' ); ?><br><input type="number" step="0.01" name="cash_amount"></label></p>
					<p class="description"><?php esc_html_e( 'Il cliente potrà pagare online quanto resta dalla sua area privata.', 'photo-booking' ); ?></p>
					</div>
					<p><label><input type="checkbox" name="notify" value="1" checked> <?php esc_html_e( 'Invia email di conferma al cliente', 'photo-booking' ); ?></label></p>
					<button class="button button-primary button-large"><?php esc_html_e( 'Crea appuntamento', 'photo-booking' ); ?></button>
				</div>
			</div>
		</div>
	</form>
	<?php endif; ?>
</div>
