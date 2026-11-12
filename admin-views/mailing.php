<?php
/**
 * @var array      $groups
 * @var bool       $past
 * @var array|null $last
 */

use PhotoBooking\Admin\Admin;
use PhotoBooking\Time;

defined( 'ABSPATH' ) || exit;

$total = 0;
foreach ( $groups as $g ) {
	$total += count( $g['rows'] );
}
?>
<div class="wrap pb-wrap">
	<h1><?php esc_html_e( 'Invio PDF ai clienti', 'photo-booking' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Carica un PDF (per esempio le indicazioni per la sessione di Natale), scrivi un\'email generica e scegli a destra i clienti a cui mandarla, raggruppati per pacchetto acquistato. Ogni cliente riceve una sola email con il PDF allegato.', 'photo-booking' ); ?></p>
	<?php if ( $last ) : ?>
		<p class="pb-muted"><?php echo esc_html( sprintf( __( 'Ultimo invio: "%1$s" con %2$s a %3$d clienti, il %4$s.', 'photo-booking' ), $last['subject'], $last['file'], $last['sent'], Time::format_datetime( $last['at'] ) ) ); ?></p>
	<?php endif; ?>

	<?php Admin::form_open( 'mailing', 'enctype="multipart/form-data" class="pb-mailing-form"' ); ?>
		<div class="pb-detail">
			<div class="pb-detail-main">
				<div class="pb-box">
					<h2><?php esc_html_e( '1. PDF ed email', 'photo-booking' ); ?></h2>
					<p><label><?php esc_html_e( 'PDF da allegare', 'photo-booking' ); ?><br><input type="file" name="pdf" accept="application/pdf,.pdf" required></label></p>
					<p><label><?php esc_html_e( 'Oggetto', 'photo-booking' ); ?><br><input type="text" name="subject" class="large-text" required value="<?php echo esc_attr( __( 'Tutte le informazioni per la tua sessione fotografica', 'photo-booking' ) ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Testo', 'photo-booking' ); ?><br><textarea name="message" rows="11" class="large-text" required><?php echo esc_textarea( __( "grazie per aver scelto il mio studio: non vedo l'ora di fotografarvi.\n\nIn allegato trovi il PDF con tutte le informazioni dettagliate sul pacchetto che hai prenotato: come prepararsi alla sessione, cosa indossare e cosa portare, come si svolge l'appuntamento e come arrivare in studio. Ti consiglio di leggerlo con calma qualche giorno prima.\n\nSe hai dubbi o esigenze particolari, rispondi pure a questa email o scrivimi su WhatsApp: troviamo insieme la soluzione migliore.\n\nA presto,\nViviana", 'photo-booking' ) ); ?></textarea></label></p>
					<p class="description"><?php esc_html_e( 'Testo preimpostato, valido per tutti i pacchetti: puoi modificarlo prima dell\'invio.', 'photo-booking' ); ?></p>
					<p class="description"><?php esc_html_e( 'L\'email inizia con "Ciao Nome," e finisce con il pulsante verso l\'area privata. Lo stesso testo va a tutti i clienti selezionati.', 'photo-booking' ); ?></p>
					<p><button class="button button-primary button-large" onclick="return confirm('<?php echo esc_js( __( 'Inviare l\'email con il PDF ai clienti selezionati?', 'photo-booking' ) ); ?>')"><?php esc_html_e( 'Invia email con PDF', 'photo-booking' ); ?></button> <span class="pb-muted" data-pb-count></span></p>
				</div>
			</div>
			<div class="pb-detail-side">
				<div class="pb-box">
					<h2><?php esc_html_e( '2. Clienti per pacchetto', 'photo-booking' ); ?></h2>
					<p class="description"><?php esc_html_e( 'In ogni gruppo ci sono solo i clienti che hanno prenotato quel pacchetto: chi ne ha prenotati più di uno compare in ciascun gruppo. Puoi spuntare un gruppo intero o i singoli nomi.', 'photo-booking' ); ?></p>
					<p class="pb-muted">
						<?php if ( $past ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=pb-mailing' ) ); ?>"><?php esc_html_e( 'Mostra solo le sessioni da oggi in poi', 'photo-booking' ); ?></a>
						<?php else : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=pb-mailing&past=1' ) ); ?>"><?php esc_html_e( 'Includi anche le sessioni passate', 'photo-booking' ); ?></a>
						<?php endif; ?>
						· <a href="#" data-pb-all="1"><?php esc_html_e( 'Seleziona tutti', 'photo-booking' ); ?></a> · <a href="#" data-pb-all="0"><?php esc_html_e( 'Nessuno', 'photo-booking' ); ?></a>
					</p>
					<?php if ( ! $groups ) : ?>
						<p><?php esc_html_e( 'Nessun cliente con una prenotazione attiva.', 'photo-booking' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $groups as $pid => $g ) : ?>
						<details class="pb-mail-group" open>
							<summary><label><input type="checkbox" data-pb-group="<?php echo esc_attr( $pid ); ?>"> <strong><?php echo esc_html( $g['name'] ); ?></strong> <span class="pb-muted">(<?php echo count( $g['rows'] ); ?>)</span></label></summary>
							<ul>
								<?php foreach ( $g['rows'] as $r ) : ?>
									<li><label><input type="checkbox" name="recipients[]" value="<?php echo esc_attr( $r['id'] ); ?>" data-pb-in="<?php echo esc_attr( $pid ); ?>"> <?php echo esc_html( $r['name'] ); ?> <span class="pb-muted"><?php echo esc_html( $r['email'] . ' · ' . ucfirst( Time::format_date( $r['date'] ) ) . ( $r['tier'] ? ' · ' . $r['tier'] : '' ) ); ?></span></label></li>
								<?php endforeach; ?>
							</ul>
						</details>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</form>
</div>
<script>
(function () {
	var form = document.querySelector('.pb-mailing-form');
	if (!form) { return; }
	var count = form.querySelector('[data-pb-count]');
	function refresh() {
		var n = form.querySelectorAll('input[name="recipients[]"]:checked').length;
		count.textContent = n ? n + ' <?php echo esc_js( __( 'destinatari selezionati', 'photo-booking' ) ); ?>' : '<?php echo esc_js( __( 'nessun destinatario selezionato', 'photo-booking' ) ); ?>';
		form.querySelectorAll('[data-pb-group]').forEach(function (g) {
			var boxes = form.querySelectorAll('input[data-pb-in="' + g.getAttribute('data-pb-group') + '"]');
			var on = 0; boxes.forEach(function (b) { if (b.checked) { on++; } });
			g.checked = boxes.length && on === boxes.length; g.indeterminate = on > 0 && on < boxes.length;
		});
	}
	form.addEventListener('change', function (e) {
		var g = e.target.getAttribute('data-pb-group');
		if (g !== null) { form.querySelectorAll('input[data-pb-in="' + g + '"]').forEach(function (b) { b.checked = e.target.checked; }); }
		refresh();
	});
	form.querySelectorAll('[data-pb-all]').forEach(function (a) {
		a.addEventListener('click', function (e) { e.preventDefault(); var v = a.getAttribute('data-pb-all') === '1'; form.querySelectorAll('input[name="recipients[]"]').forEach(function (b) { b.checked = v; }); refresh(); });
	});
	form.querySelectorAll('summary label').forEach(function (l) { l.addEventListener('click', function (e) { e.stopPropagation(); }); });
	refresh();
})();
</script>
