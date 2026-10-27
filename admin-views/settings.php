<?php
/**
 * @var array     $s
 * @var WP_Post[] $pages
 */

use PhotoBooking\Admin\Admin;

defined( 'ABSPATH' ) || exit;

$days = array( 1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica' );
$num  = static function ( $name, $value, $suffix = '', $min = 0 ) {
	echo '<input type="number" min="' . esc_attr( $min ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="small-text"> ' . esc_html( $suffix );
};
$last = (int) get_option( 'pb_cron_last_run' );
$cors = wp_json_encode(
	array(
		array(
			'AllowedOrigins' => array( untrailingslashit( home_url() ) ),
			'AllowedMethods' => array( 'PUT', 'GET', 'HEAD' ),
			'AllowedHeaders' => array( '*' ),
			'ExposeHeaders'  => array( 'ETag' ),
			'MaxAgeSeconds'  => 3600,
		),
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);
?>
<div class="wrap pb-wrap pb-settings">
	<h1><?php esc_html_e( 'Impostazioni Photo Booking', 'photo-booking' ); ?></h1>

	<?php Admin::form_open( 'settings' ); ?>
		<h2><?php esc_html_e( 'Generale', 'photo-booking' ); ?></h2>
		<table class="form-table">
			<tr><th><?php esc_html_e( 'Pagina di prenotazione', 'photo-booking' ); ?></th><td>
				<select name="booking_page_id"><option value="0">—</option>
					<?php foreach ( $pages as $p ) : ?><option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $s['booking_page_id'], $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option><?php endforeach; ?>
				</select>
				<p class="description"><?php echo wp_kses_post( __( 'La pagina deve contenere lo shortcode <code>[prenotazione]</code>. Varianti: <code>[prenotazione categoria="natale"]</code> (solo i pacchetti di una categoria prodotto), <code>[prenotazione pacchetto="123"]</code> (un pacchetto specifico, es. sulla landing di Natale).', 'photo-booking' ) ); ?></p>
			</td></tr>
			<tr><th><?php esc_html_e( 'Dati del venditore', 'photo-booking' ); ?></th><td>
				<input type="text" class="regular-text" name="business_name" placeholder="<?php esc_attr_e( 'Ragione sociale / nome e cognome', 'photo-booking' ); ?>" value="<?php echo esc_attr( $s['business_name'] ); ?>">
				<input type="text" class="regular-text" name="business_vat" placeholder="<?php esc_attr_e( 'Partita IVA', 'photo-booking' ); ?>" value="<?php echo esc_attr( $s['business_vat'] ); ?>"><br>
				<input type="text" class="large-text" name="business_address" placeholder="<?php esc_attr_e( 'Sede (via, CAP, città)', 'photo-booking' ); ?>" value="<?php echo esc_attr( $s['business_address'] ); ?>" style="margin-top:6px"><br>
				<input type="email" class="regular-text" name="business_email" placeholder="<?php esc_attr_e( 'Email di contatto', 'photo-booking' ); ?>" value="<?php echo esc_attr( $s['business_email'] ); ?>" style="margin-top:6px">
				<input type="text" class="regular-text" name="business_phone" placeholder="<?php esc_attr_e( 'Telefono', 'photo-booking' ); ?>" value="<?php echo esc_attr( $s['business_phone'] ); ?>" style="margin-top:6px">
				<p class="description"><?php esc_html_e( 'Compaiono nel checkout, nelle pagine legali (shortcode [pb_venditore]) e nelle email: obbligatori per la vendita a distanza.', 'photo-booking' ); ?></p>
			</td></tr>
			<tr><th><?php esc_html_e( 'Indirizzo dello studio', 'photo-booking' ); ?></th><td><input type="text" class="large-text" name="studio_address" value="<?php echo esc_attr( $s['studio_address'] ); ?>"><p class="description"><?php esc_html_e( 'Mostrato nelle email e nell\'area privata.', 'photo-booking' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Colore principale', 'photo-booking' ); ?></th><td><input type="color" name="accent_color" value="<?php echo esc_attr( $s['accent_color'] ); ?>"></td></tr>
		</table>

		<h2><?php esc_html_e( 'Orari di apertura', 'photo-booking' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Fasce prenotabili per i pacchetti senza date dedicate. Formato 09:00-13:00, 14:30-18:00. Vuoto = chiuso. Per ferie e chiusure usa "Blocca intervallo" nel calendario.', 'photo-booking' ); ?></p>
		<table class="form-table">
			<?php foreach ( $days as $d => $label ) : ?>
				<tr><th><?php echo esc_html( $label ); ?></th><td><input type="text" name="hours[<?php echo esc_attr( $d ); ?>]" value="<?php echo esc_attr( $s['hours'][ $d ] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'chiuso', 'photo-booking' ); ?>" class="regular-text"></td></tr>
			<?php endforeach; ?>
		</table>

		<h2><?php esc_html_e( 'Regole di prenotazione', 'photo-booking' ); ?></h2>
		<table class="form-table">
			<tr><th><?php esc_html_e( 'Intervallo tra gli slot', 'photo-booking' ); ?></th><td><?php $num( 'slot_interval', $s['slot_interval'], 'minuti' ); ?><p class="description"><?php esc_html_e( '0 = automatico (durata del pacchetto + pausa). Es. 15 per offrire inizi ogni quarto d\'ora.', 'photo-booking' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Preavviso minimo', 'photo-booking' ); ?></th><td><?php $num( 'min_notice_hours', $s['min_notice_hours'], 'ore' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Prenotabile fino a', 'photo-booking' ); ?></th><td><?php $num( 'max_advance_days', $s['max_advance_days'], 'giorni in anticipo', 1 ); ?><p class="description"><?php esc_html_e( 'Non si applica ai pacchetti con date dedicate.', 'photo-booking' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Blocco dello slot durante il pagamento', 'photo-booking' ); ?></th><td><?php $num( 'hold_minutes', $s['hold_minutes'], 'minuti', 5 ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Validità della proposta di nuova data', 'photo-booking' ); ?></th><td><?php $num( 'proposal_hours', $s['proposal_hours'], 'ore', 1 ); ?> &nbsp; <?php esc_html_e( 'max', 'photo-booking' ); ?> <?php $num( 'proposal_max_slots', $s['proposal_max_slots'], 'date proposte', 1 ); ?></td></tr>
		</table>

		<h2><?php esc_html_e( 'Area privata: spostamenti e disdette', 'photo-booking' ); ?></h2>
		<table class="form-table">
			<tr><th><?php esc_html_e( 'Spostamento autonomo', 'photo-booking' ); ?></th><td><?php $num( 'reschedule_max', $s['reschedule_max'], 'volte, fino a' ); ?> <?php $num( 'reschedule_min_hours', $s['reschedule_min_hours'], 'ore prima' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Rimborso o credito a scelta', 'photo-booking' ); ?></th><td><?php $num( 'refund_min_days', $s['refund_min_days'], 'giorni prima o più' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Solo credito', 'photo-booking' ); ?></th><td><?php esc_html_e( 'fino a', 'photo-booking' ); ?> <?php $num( 'credit_min_hours', $s['credit_min_hours'], 'ore prima (sotto: importo trattenuto)' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Validità del credito', 'photo-booking' ); ?></th><td><?php $num( 'credit_validity_months', $s['credit_validity_months'], 'mesi', 1 ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Scadenza pagamento (inserimenti manuali)', 'photo-booking' ); ?></th><td><?php $num( 'manual_payment_days', $s['manual_payment_days'], 'giorni' ); ?><p class="description"><?php esc_html_e( 'Per gli appuntamenti inseriti dallo studio senza alcun pagamento: il cliente riceve un promemoria 24 ore prima della scadenza e, se non paga l\'acconto, la prenotazione scade da sola e la data torna libera. 0 = nessuna scadenza.', 'photo-booking' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Pulizia automatica', 'photo-booking' ); ?></th><td><?php $num( 'purge_stale_days', $s['purge_stale_days'], 'giorni' ); ?><p class="description"><?php esc_html_e( 'Le prenotazioni online mai pagate (blocchi scaduti durante il checkout) vengono eliminate dopo questi giorni. 0 = mai.', 'photo-booking' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Testo condizioni', 'photo-booking' ); ?></th><td><textarea name="policy_text" rows="7" class="large-text"><?php echo esc_textarea( $s['policy_text'] ); ?></textarea><p class="description"><?php esc_html_e( 'Una regola per riga. Mostrato nel modulo, nelle email e nell\'area privata. Tienilo coerente con i numeri qui sopra.', 'photo-booking' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Promemoria', 'photo-booking' ); ?></th><td><label><input type="checkbox" name="reminder_enabled" value="1" <?php checked( $s['reminder_enabled'] ); ?>> <?php esc_html_e( 'Invia un promemoria il giorno prima dell\'appuntamento', 'photo-booking' ); ?></label></td></tr>
		</table>
		<p class="description"><?php echo wp_kses_post( sprintf( __( 'Testi e oggetti di tutte le email si modificano in <a href="%s">WooCommerce → Impostazioni → Email</a> (voci "Photo Booking").', 'photo-booking' ), esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ) ) ); ?></p>

		<h2 id="r2"><?php esc_html_e( 'Consegne – Cloudflare R2', 'photo-booking' ); ?></h2>
		<table class="form-table">
			<tr><th><?php esc_html_e( 'Account ID', 'photo-booking' ); ?></th><td><input type="text" class="regular-text" name="r2_account_id" value="<?php echo esc_attr( $s['r2_account_id'] ); ?>"></td></tr>
			<tr><th><?php esc_html_e( 'Access Key ID', 'photo-booking' ); ?></th><td><input type="text" class="regular-text" name="r2_access_key" value="<?php echo esc_attr( $s['r2_access_key'] ); ?>" autocomplete="off"></td></tr>
			<tr><th><?php esc_html_e( 'Secret Access Key', 'photo-booking' ); ?></th><td><input type="password" class="regular-text" name="r2_secret_key" value="" placeholder="<?php echo $s['r2_secret_key'] ? esc_attr__( '•••••• (salvata – lascia vuoto per non cambiarla)', 'photo-booking' ) : ''; ?>" autocomplete="new-password"></td></tr>
			<tr><th><?php esc_html_e( 'Bucket', 'photo-booking' ); ?></th><td><input type="text" class="regular-text" name="r2_bucket" value="<?php echo esc_attr( $s['r2_bucket'] ); ?>"></td></tr>
			<tr><th><?php esc_html_e( 'Endpoint (facoltativo)', 'photo-booking' ); ?></th><td><input type="url" class="regular-text" name="r2_endpoint" value="<?php echo esc_attr( $s['r2_endpoint'] ); ?>" placeholder="https://&lt;account&gt;.r2.cloudflarestorage.com"><p class="description"><?php esc_html_e( 'Solo se usi la giurisdizione EU (https://<account>.eu.r2.cloudflarestorage.com) o un altro storage S3-compatibile.', 'photo-booking' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Cartella nel bucket', 'photo-booking' ); ?></th><td><input type="text" class="regular-text" name="r2_prefix" value="<?php echo esc_attr( $s['r2_prefix'] ); ?>"></td></tr>
			<tr><th><?php esc_html_e( 'Durata consegne', 'photo-booking' ); ?></th><td><?php $num( 'delivery_days', $s['delivery_days'], 'giorni', 1 ); ?> · <?php esc_html_e( 'avviso', 'photo-booking' ); ?> <?php $num( 'delivery_warn_days', $s['delivery_warn_days'], 'giorni prima' ); ?> · <?php esc_html_e( 'file eliminato', 'photo-booking' ); ?> <?php $num( 'delivery_purge_days', $s['delivery_purge_days'], 'giorni dopo la scadenza' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Validità link di download', 'photo-booking' ); ?></th><td><?php $num( 'download_link_minutes', $s['download_link_minutes'], 'minuti', 1 ); ?></td></tr>
		</table>
		<details class="pb-help"><summary><?php esc_html_e( 'Configurazione del bucket (CORS) – da fare una volta', 'photo-booking' ); ?></summary>
			<ol>
				<li><?php esc_html_e( 'Cloudflare → R2 → Crea bucket (privato, NON attivare l\'accesso pubblico).', 'photo-booking' ); ?></li>
				<li><?php esc_html_e( 'R2 → Gestisci token API → Crea token con permesso "Object Read & Write" limitato a questo bucket. Copia Access Key ID e Secret.', 'photo-booking' ); ?></li>
				<li><?php esc_html_e( 'Bucket → Impostazioni → Criteri CORS → incolla:', 'photo-booking' ); ?><pre><?php echo esc_html( $cors ); ?></pre></li>
				<li><?php esc_html_e( '(Consigliato) Bucket → Impostazioni → Regole ciclo di vita: elimina gli oggetti dopo 90 giorni e i caricamenti multipart incompleti dopo 2 giorni, come rete di sicurezza.', 'photo-booking' ); ?></li>
			</ol>
		</details>

		<h2><?php esc_html_e( 'Cookie banner', 'photo-booking' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Il banner è sempre attivo: i cookie tecnici non richiedono consenso, i codici qui sotto partono solo dopo che il visitatore ha accettato la relativa categoria. Lascia vuoto se non usi statistiche o marketing.', 'photo-booking' ); ?></p>
		<table class="form-table">
			<tr><th><?php esc_html_e( 'Codice statistiche', 'photo-booking' ); ?></th><td><textarea name="cookie_scripts_stats" rows="5" class="large-text code" <?php disabled( ! current_user_can( 'unfiltered_html' ) ); ?>><?php echo esc_textarea( $s['cookie_scripts_stats'] ); ?></textarea><p class="description"><?php esc_html_e( 'Es. il tag di Google Analytics 4, incollato così com\'è (con i tag <script>).', 'photo-booking' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Codice marketing', 'photo-booking' ); ?></th><td><textarea name="cookie_scripts_marketing" rows="5" class="large-text code" <?php disabled( ! current_user_can( 'unfiltered_html' ) ); ?>><?php echo esc_textarea( $s['cookie_scripts_marketing'] ); ?></textarea><p class="description"><?php esc_html_e( 'Es. il Pixel di Meta.', 'photo-booking' ); ?></p></td></tr>
		</table>

		<h2><?php esc_html_e( 'Avanzate', 'photo-booking' ); ?></h2>
		<table class="form-table">
			<tr><th><?php esc_html_e( 'URL FullCalendar', 'photo-booking' ); ?></th><td><input type="url" class="large-text" name="fullcalendar_src" value="<?php echo esc_attr( $s['fullcalendar_src'] ); ?>"><p class="description"><?php esc_html_e( 'Se presente, viene usato il file locale assets/vendor/fullcalendar/index.global.min.js.', 'photo-booking' ); ?></p></td></tr>
		</table>
		<?php submit_button( __( 'Salva impostazioni', 'photo-booking' ) ); ?>
	</form>

	<div class="pb-two">
		<div class="pb-box">
			<h2><?php esc_html_e( 'Test Cloudflare R2', 'photo-booking' ); ?></h2>
			<?php Admin::form_open( 'r2_test' ); ?>
				<button class="button"><?php esc_html_e( 'Verifica connessione', 'photo-booking' ); ?></button>
			</form>
		</div>
		<div class="pb-box">
			<h2><?php esc_html_e( 'Attività pianificate', 'photo-booking' ); ?></h2>
			<p class="description">
				<?php echo $last ? esc_html( sprintf( __( 'Ultima esecuzione: %s', 'photo-booking' ), wp_date( 'd/m/Y H:i', $last ) ) ) : esc_html__( 'Mai eseguite.', 'photo-booking' ); ?><br>
				<?php esc_html_e( 'Scadenza blocchi e proposte, promemoria, scadenze consegne. Per affidabilità configura un cron di sistema che chiami wp-cron.php ogni 5-15 minuti.', 'photo-booking' ); ?>
			</p>
			<?php Admin::form_open( 'run_cron' ); ?>
				<button class="button"><?php esc_html_e( 'Esegui ora', 'photo-booking' ); ?></button>
			</form>
		</div>
	</div>
</div>
