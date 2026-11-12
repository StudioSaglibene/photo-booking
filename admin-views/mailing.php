<?php
/**
 * @var array      $groups  product_id => name, rows
 * @var array|null $last
 */

use PhotoBooking\Admin\Admin;
use PhotoBooking\Time;

defined( 'ABSPATH' ) || exit;

$total = 0;
foreach ( $groups as $g ) {
	foreach ( $g['tiers'] as $tier ) {
		$total += count( $tier['rows'] );
	}
}
?>
<div class="wrap pb-wrap pb-mailing">
	<h1><?php esc_html_e( 'Invio PDF ai clienti', 'photo-booking' ); ?></h1>
	<p class="pb-mailing-lead"><?php esc_html_e( 'Tre passaggi: scegli il PDF, controlla il testo dell\'email, spunta i clienti. Ogni cliente riceve una sola email con il PDF allegato.', 'photo-booking' ); ?></p>

	<?php Admin::form_open( 'mailing', 'enctype="multipart/form-data" class="pb-mailing-form"' ); ?>
		<div class="pb-mailing-cols">

			<div class="pb-mailing-left">
				<section class="pb-step">
					<h2><span class="pb-step-n">1</span> <?php esc_html_e( 'Il PDF da allegare', 'photo-booking' ); ?></h2>
					<label class="pb-file">
						<input type="file" name="pdf" accept="application/pdf,.pdf" required>
						<span class="pb-file-btn"><?php esc_html_e( 'Scegli il PDF', 'photo-booking' ); ?></span>
						<span class="pb-file-name" data-pb-file><?php esc_html_e( 'Nessun file scelto', 'photo-booking' ); ?></span>
					</label>
					<p class="description"><?php esc_html_e( 'Per esempio il PDF con le indicazioni per le sessioni di Natale. Solo PDF, massimo 10 MB.', 'photo-booking' ); ?></p>
				</section>

				<section class="pb-step">
					<h2><span class="pb-step-n">2</span> <?php esc_html_e( 'Il testo dell\'email', 'photo-booking' ); ?></h2>
					<p><label for="pb-subject"><?php esc_html_e( 'Oggetto', 'photo-booking' ); ?></label>
					<input id="pb-subject" type="text" name="subject" class="large-text" required value="<?php echo esc_attr( __( 'Tutte le informazioni per la tua sessione fotografica', 'photo-booking' ) ); ?>"></p>
					<p><label for="pb-message"><?php esc_html_e( 'Messaggio', 'photo-booking' ); ?> <span class="pb-muted"><?php esc_html_e( '(inizia da solo con "Ciao Nome,")', 'photo-booking' ); ?></span></label>
					<textarea id="pb-message" name="message" rows="11" class="large-text" required><?php echo esc_textarea( __( "grazie per aver scelto il mio studio: non vedo l'ora di fotografarvi.\n\nIn allegato trovi il PDF con tutte le informazioni dettagliate sul pacchetto che hai prenotato: come prepararsi alla sessione, cosa indossare e cosa portare, come si svolge l'appuntamento e come arrivare in studio. Ti consiglio di leggerlo con calma qualche giorno prima.\n\nSe hai dubbi o esigenze particolari, rispondi pure a questa email o scrivimi su WhatsApp: troviamo insieme la soluzione migliore.\n\nA presto,\nViviana", 'photo-booking' ) ); ?></textarea></p>
					<p class="description"><?php esc_html_e( 'Testo preimpostato, valido per tutti i pacchetti: puoi cambiarlo prima dell\'invio. In fondo all\'email c\'è il pulsante verso l\'area privata.', 'photo-booking' ); ?></p>
				</section>
			</div>

			<div class="pb-mailing-right">
				<section class="pb-step pb-step-customers">
					<h2><span class="pb-step-n">3</span> <?php esc_html_e( 'A chi inviarla', 'photo-booking' ); ?></h2>
					<p class="description"><?php echo esc_html( sprintf( __( 'Tutti i pacchetti in listino e, dentro ciascuno, solo i clienti che lo hanno prenotato (%d in tutto, sessioni passate comprese), divisi per sotto-pacchetto. Spunta il pacchetto per prendere tutti i suoi clienti, oppure un solo sotto-pacchetto (es. Neve). Chi ha prenotato più pacchetti compare in ciascun gruppo. Chi si è solo registrato senza prenotare non c\'è. Non compaiono le prenotazioni mai pagate, scadute, rifiutate o annullate.', 'photo-booking' ), $total ) ); ?></p>

					<div class="pb-search-row">
						<input type="search" class="pb-mail-search" placeholder="<?php esc_attr_e( 'Cerca un cliente per nome o email…', 'photo-booking' ); ?>" aria-label="<?php esc_attr_e( 'Cerca cliente', 'photo-booking' ); ?>">
						<span class="pb-search-hint" data-pb-search-hint></span>
					</div>
					<div class="pb-mail-tools">
						<a href="#" data-pb-all="1"><?php esc_html_e( 'Seleziona tutti', 'photo-booking' ); ?></a>
						<a href="#" data-pb-all="0"><?php esc_html_e( 'Deseleziona tutti', 'photo-booking' ); ?></a>
					</div>

					<div class="pb-mail-groups">
					<?php foreach ( $groups as $pid => $g ) : ?>
						<?php
						$n = 0;
						foreach ( $g['tiers'] as $tier ) {
							$n += count( $tier['rows'] );
						}
						?>
						<div class="pb-mail-group <?php echo $n ? '' : 'is-empty'; ?>">
							<label class="pb-mail-group-head">
								<input type="checkbox" data-pb-group="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! $n ); ?>>
								<span class="pb-mail-group-name"><?php echo esc_html( $g['name'] ); ?></span>
								<span class="pb-mail-group-count"><?php echo $n ? esc_html( sprintf( _n( '%d cliente', '%d clienti', $n, 'photo-booking' ), $n ) ) : esc_html__( 'nessun cliente', 'photo-booking' ); ?></span>
							</label>
							<?php foreach ( $g['tiers'] as $tkey => $tier ) : ?>
								<?php $tid = $pid . ':' . $tkey; ?>
								<div class="pb-mail-tier <?php echo $tier['rows'] ? '' : 'is-empty'; ?>">
									<label class="pb-mail-tier-head">
										<input type="checkbox" data-pb-tier="<?php echo esc_attr( $tid ); ?>" data-pb-tier-of="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! $tier['rows'] ); ?>>
										<span class="pb-mail-tier-name"><?php echo esc_html( $tier['name'] ); ?></span>
										<span class="pb-mail-group-count"><?php echo $tier['rows'] ? esc_html( sprintf( _n( '%d cliente', '%d clienti', count( $tier['rows'] ), 'photo-booking' ), count( $tier['rows'] ) ) ) : esc_html__( 'nessun cliente', 'photo-booking' ); ?></span>
									</label>
									<?php if ( $tier['rows'] ) : ?>
									<ul class="pb-mail-list">
										<?php foreach ( $tier['rows'] as $r ) : ?>
											<li class="pb-mail-row" data-pb-text="<?php echo esc_attr( strtolower( $r['name'] . ' ' . $r['email'] ) ); ?>">
												<label>
													<input type="checkbox" name="recipients[]" value="<?php echo esc_attr( $r['id'] ); ?>" data-pb-in="<?php echo esc_attr( $pid ); ?>" data-pb-tier-in="<?php echo esc_attr( $tid ); ?>">
													<span class="pb-mail-who">
														<strong><?php echo esc_html( $r['name'] ); ?></strong>
														<small><?php echo esc_html( $r['email'] ); ?></small>
													</span>
													<span class="pb-mail-when"><?php echo esc_html( $r['date'] ? ucfirst( Time::format_date( $r['date'] ) ) : '' ); ?><small><?php echo esc_html( $r['status'] ); ?></small></span>
												</label>
											</li>
										<?php endforeach; ?>
									</ul>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
					</div>
				</section>
			</div>
		</div>

		<div class="pb-mailing-footer">
			<div class="pb-mailing-summary" data-pb-count aria-live="polite"></div>
			<button class="button button-primary button-hero" data-pb-send disabled onclick="return confirm('<?php echo esc_js( __( 'Inviare l\'email con il PDF ai clienti selezionati?', 'photo-booking' ) ); ?>')"><?php esc_html_e( 'Invia email con PDF', 'photo-booking' ); ?></button>
			<?php if ( $last ) : ?>
				<span class="pb-muted"><?php echo esc_html( sprintf( __( 'Ultimo invio: "%1$s" a %2$d clienti, %3$s.', 'photo-booking' ), $last['subject'], $last['sent'], Time::format_datetime( $last['at'] ) ) ); ?></span>
			<?php endif; ?>
		</div>
	</form>
</div>
<script>
(function () {
	var form = document.querySelector('.pb-mailing-form');
	if (!form) { return; }
	var count = form.querySelector('[data-pb-count]');
	var send = form.querySelector('[data-pb-send]');
	var search = form.querySelector('.pb-mail-search');
	var hint = form.querySelector('[data-pb-search-hint]');
	var file = form.querySelector('input[name="pdf"]');
	var fileName = form.querySelector('[data-pb-file]');
	var t = {
		none: <?php echo wp_json_encode( __( 'Nessun cliente selezionato: spunta un gruppo o i singoli nomi.', 'photo-booking' ) ); ?>,
		one: <?php echo wp_json_encode( __( 'L\'email con il PDF andrà a 1 cliente.', 'photo-booking' ) ); ?>,
		many: <?php echo wp_json_encode( __( 'L\'email con il PDF andrà a %d clienti.', 'photo-booking' ) ); ?>,
		found: <?php echo wp_json_encode( __( '%d risultati', 'photo-booking' ) ); ?>,
		nofile: <?php echo wp_json_encode( __( 'Nessun file scelto', 'photo-booking' ) ); ?>
	};
	function syncHead(head, selector) {
		var items = form.querySelectorAll(selector);
		var on = 0; items.forEach(function (b) { if (b.checked) { on++; } });
		head.checked = items.length > 0 && on === items.length;
		head.indeterminate = on > 0 && on < items.length;
	}
	function refresh() {
		var n = 0;
		form.querySelectorAll('input[name="recipients[]"]').forEach(function (b) { if (b.checked) { n++; } });
		count.textContent = n === 0 ? t.none : (n === 1 ? t.one : t.many.replace('%d', n));
		count.classList.toggle('is-ready', n > 0);
		send.disabled = n === 0;
		form.querySelectorAll('[data-pb-tier]').forEach(function (h) { syncHead(h, 'input[data-pb-tier-in="' + h.getAttribute('data-pb-tier') + '"]'); });
		form.querySelectorAll('[data-pb-group]').forEach(function (h) { syncHead(h, 'input[data-pb-in="' + h.getAttribute('data-pb-group') + '"]'); });
	}
	function setAll(selector, value) {
		form.querySelectorAll(selector).forEach(function (b) {
			if (b.closest('.pb-mail-row').hidden) { return; } // only the names currently shown by the search
			b.checked = value;
		});
	}
	form.addEventListener('change', function (e) {
		var g = e.target.getAttribute('data-pb-group');
		var tier = e.target.getAttribute('data-pb-tier');
		if (g !== null) { setAll('input[data-pb-in="' + g + '"]', e.target.checked); }       // whole package: every sub-package
		if (tier !== null) { setAll('input[data-pb-tier-in="' + tier + '"]', e.target.checked); } // one sub-package only
		refresh();
	});
	form.querySelectorAll('[data-pb-all]').forEach(function (a) {
		a.addEventListener('click', function (e) {
			e.preventDefault();
			var v = a.getAttribute('data-pb-all') === '1';
			form.querySelectorAll('.pb-mail-row:not([hidden]) input[name="recipients[]"]').forEach(function (b) { b.checked = v; });
			refresh();
		});
	});
	search.addEventListener('input', function () {
		var q = search.value.trim().toLowerCase();
		var found = 0;
		form.querySelectorAll('.pb-mail-row').forEach(function (row) {
			var hit = !q || row.getAttribute('data-pb-text').indexOf(q) !== -1;
			row.hidden = !hit; if (hit) { found++; }
		});
		form.querySelectorAll('.pb-mail-tier').forEach(function (box) {
			box.hidden = !!q && box.querySelectorAll('.pb-mail-row:not([hidden])').length === 0;
		});
		form.querySelectorAll('.pb-mail-group').forEach(function (box) {
			box.hidden = !!q && box.querySelectorAll('.pb-mail-row:not([hidden])').length === 0;
		});
		hint.textContent = q ? t.found.replace('%d', found) : '';
	});
	file.addEventListener('change', function () { fileName.textContent = file.files.length ? file.files[0].name : t.nofile; });
	refresh();
})();
</script>
