<?php
/**
 * My account – deliveries.
 *
 * @var array $deliveries
 */

use PhotoBooking\Deliveries;
use PhotoBooking\Time;

defined( 'ABSPATH' ) || exit;
?>
<div class="pb-account">
	<?php if ( ! $deliveries ) : ?>
		<p class="pb-muted"><?php esc_html_e( 'Non ci sono ancora consegne. Riceverai una email quando le tue fotografie saranno pronte.', 'photo-booking' ); ?></p>
	<?php else : ?>
		<div class="pb-deliveries">
			<?php foreach ( $deliveries as $d ) : ?>
				<?php
				$active    = 'active' === $d['status'] && ( ! $d['expires_at'] || $d['expires_at'] > Time::now_db() );
				$days_left = $d['expires_at'] ? (int) floor( ( Time::parse( $d['expires_at'] )->getTimestamp() - time() ) / DAY_IN_SECONDS ) : null;
				?>
				<div class="pb-delivery<?php echo $active ? '' : ' is-expired'; ?>">
					<div class="pb-delivery-icon" aria-hidden="true">ZIP</div>
					<div class="pb-delivery-body">
						<strong class="pb-delivery-title"><?php echo esc_html( $d['title'] ); ?></strong>
						<span class="pb-delivery-meta">
							<?php echo esc_html( size_format( (int) $d['size'], 1 ) ); ?>
							· <?php echo esc_html( sprintf( /* translators: %s date */ __( 'caricata il %s', 'photo-booking' ), wp_date( 'j F Y', Time::parse( $d['uploaded_at'] )->getTimestamp() ) ) ); ?>
						</span>
						<span class="pb-delivery-exp">
							<?php if ( $active ) : ?>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1 date 2 days */
										_n( 'Scaricabile fino al %1$s (manca %2$d giorno)', 'Scaricabile fino al %1$s (mancano %2$d giorni)', max( 0, $days_left ), 'photo-booking' ),
										wp_date( 'j F Y', Time::parse( $d['expires_at'] )->getTimestamp() ),
										max( 0, $days_left )
									)
								);
								?>
							<?php else : ?>
								<?php esc_html_e( 'Scaduta – contatta lo studio se ti serve ancora.', 'photo-booking' ); ?>
							<?php endif; ?>
						</span>
					</div>
					<div class="pb-delivery-action">
						<?php if ( $active ) : ?>
							<a class="button alt pb-download" href="<?php echo esc_url( Deliveries::download_link( $d ) ); ?>" data-wait="<?php esc_attr_e( 'Attendi 30 secondi…', 'photo-booking' ); ?>"><?php esc_html_e( 'Scarica ZIP', 'photo-booking' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="pb-muted"><?php esc_html_e( 'Consiglio: dopo il download salva una copia delle foto su un altro dispositivo o servizio cloud.', 'photo-booking' ); ?></p>
	<?php endif; ?>
</div>

<script id="pb-download-guard">
/* Un download ogni 30 secondi: dopo il clic il pulsante resta disattivato (il server applica lo stesso limite). */
(function () {
	document.querySelectorAll('a.pb-download').forEach(function (a) {
		a.addEventListener('click', function (e) {
			if (a.classList.contains('is-waiting')) { e.preventDefault(); return; }
			var label = a.textContent, left = 30;
			a.classList.add('is-waiting');
			a.setAttribute('aria-disabled', 'true');
			var tick = setInterval(function () {
				left--;
				a.textContent = a.getAttribute('data-wait').replace('30', String(left));
				if (left <= 0) { clearInterval(tick); a.classList.remove('is-waiting'); a.removeAttribute('aria-disabled'); a.textContent = label; }
			}, 1000);
			a.textContent = a.getAttribute('data-wait');
		});
	});
	if (location.search.indexOf('pb_wait=1') !== -1 && window.pbToast) { window.pbToast('<?php echo esc_js( __( 'Hai appena scaricato questo file: attendi 30 secondi prima di un nuovo download.', 'photo-booking' ) ); ?>', 'info'); }
})();
</script>
