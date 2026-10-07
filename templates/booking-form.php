<?php
/**
 * Booking flow: servizio → pacchetto ed extra → data e ora → profilo e pagamento.
 * Override in yourtheme/photo-booking/booking-form.php
 *
 * @var array  $packages
 * @var int    $selected
 * @var array  $values
 * @var array  $errors
 * @var float  $credit
 * @var bool   $logged_in
 * @var string $login_url
 */

defined( 'ABSPATH' ) || exit;

$total_steps = 4;
$fmt         = static function ( $v ) {
	return html_entity_decode( wp_strip_all_tags( wc_price( $v, array( 'decimals' => ( floor( $v ) == $v ? 0 : 2 ) ) ) ) ); // phpcs:ignore WordPress.PHP.StrictComparisons
};
?>
<form class="pb-flow" id="prenota-flow" method="post" action="" novalidate
	data-selected="<?php echo esc_attr( $selected ); ?>"
	data-start="<?php echo esc_attr( $values['start'] ); ?>"
	data-tier="<?php echo esc_attr( $values['tier'] ); ?>"
	data-extras="<?php echo esc_attr( implode( ',', $values['extras'] ) ); ?>"
	data-logged-in="<?php echo $logged_in ? '1' : '0'; ?>"
	data-has-errors="<?php echo $errors ? '1' : '0'; ?>">
	<?php wp_nonce_field( 'pb_book', 'pb_nonce' ); ?>
	<input type="hidden" name="pb_action" value="book">
	<input type="hidden" name="product_id" value="<?php echo esc_attr( $selected ?: '' ); ?>">
	<input type="hidden" name="start" value="<?php echo esc_attr( $values['start'] ); ?>">
	<input type="hidden" name="tier" value="<?php echo esc_attr( $values['tier'] ); ?>">

	<?php /* STEP 1 — servizio */ ?>
	<section class="pb-flow-step pb-flow-dark" data-step="1">
		<div class="pb-flow-wrap">
			<p class="pb-eyebrow pb-eyebrow-light"><?php echo esc_html( sprintf( /* translators: 1: step 2: total */ __( 'Step %1$d di %2$d', 'photo-booking' ), 1, $total_steps ) ); ?></p>
			<h2 class="pb-title pb-title-light"><?php esc_html_e( 'Scegli il servizio.', 'photo-booking' ); ?></h2>

			<?php if ( $errors ) : ?>
				<div class="pb-flow-errors" role="alert"><?php foreach ( $errors as $e ) : ?><p><?php echo wp_kses_post( $e ); ?></p><?php endforeach; ?></div>
			<?php endif; ?>

			<div class="pb-services">
				<?php foreach ( $packages as $i => $p ) : ?>
					<?php $soldout = ( null !== $p['remaining'] && $p['remaining'] <= 0 ); ?>
					<button type="button" class="pb-service<?php echo $soldout ? ' is-soldout' : ''; ?>" style="--i:<?php echo (int) $i; ?>"
						data-id="<?php echo esc_attr( $p['id'] ); ?>"
						data-name="<?php echo esc_attr( $p['name'] ); ?>"
						data-price="<?php echo esc_attr( $p['price'] ); ?>"
						data-deposit="<?php echo esc_attr( $p['deposit'] ); ?>"
						data-first="<?php echo esc_attr( $p['first'] ); ?>"
						data-tiers="<?php echo esc_attr( count( $p['tiers'] ) ); ?>"
						<?php disabled( $soldout ); ?>>
						<?php if ( $p['image'] ) : ?>
							<img class="pb-service-img" src="<?php echo esc_url( $p['image'] ); ?>" alt="<?php echo esc_attr( $p['name'] ); ?>" loading="lazy">
						<?php endif; ?>
						<span class="pb-service-overlay">
							<span class="pb-service-label"><?php echo esc_html( $p['label'] ? $p['label'] : __( 'Servizio', 'photo-booking' ) ); ?></span>
							<span class="pb-service-name"><?php echo esc_html( $p['name'] ); ?></span>
							<span class="pb-service-price">
								<?php
								echo $soldout ? esc_html__( 'Esaurito', 'photo-booking' ) : esc_html( sprintf( /* translators: %s price */ __( 'Da %s', 'photo-booking' ), $fmt( $p['from'] ) ) );
								?>
							</span>
							<span class="pb-service-cta"><?php esc_html_e( 'Seleziona →', 'photo-booking' ); ?></span>
						</span>
					</button>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<?php /* STEP 2 — sotto-pacchetto ed extra */ ?>
	<section class="pb-flow-step" data-step="2" hidden>
		<div class="pb-flow-wrap">
			<button type="button" class="pb-backlink" data-go="1"><span aria-hidden="true">‹</span> <span data-fill="service"></span></button>
			<p class="pb-eyebrow"><?php echo esc_html( sprintf( /* translators: 1: step 2: total */ __( 'Step %1$d di %2$d', 'photo-booking' ), 2, $total_steps ) ); ?></p>
			<h2 class="pb-title"><?php esc_html_e( 'Scegli il pacchetto.', 'photo-booking' ); ?></h2>

			<?php foreach ( $packages as $p ) : ?>
				<?php
				if ( ! $p['tiers'] ) {
					continue;
				}
				?>
				<div class="pb-tiers" data-for="<?php echo esc_attr( $p['id'] ); ?>" hidden>
					<?php foreach ( $p['tiers'] as $ti => $t ) : ?>
						<button type="button" class="pb-tier" aria-pressed="false" style="--i:<?php echo (int) $ti; ?>"
							data-key="<?php echo esc_attr( $t['key'] ); ?>" data-name="<?php echo esc_attr( $t['name'] ); ?>" data-price="<?php echo esc_attr( $t['price'] ); ?>">
							<span class="pb-tier-label"><?php echo esc_html( $p['name'] ); ?></span>
							<span class="pb-tier-name"><?php echo esc_html( $t['name'] ); ?></span>
							<span class="pb-tier-price"><?php echo esc_html( $fmt( $t['price'] ) ); ?></span>
							<?php if ( $t['description'] ) : ?>
								<span class="pb-tier-desc"><?php echo esc_html( $t['description'] ); ?></span>
							<?php endif; ?>
							<?php if ( $t['includes'] ) : ?>
								<span class="pb-tier-includes">
									<?php foreach ( array_filter( array_map( 'trim', explode( "\n", $t['includes'] ) ) ) as $line ) : ?>
										<span><?php echo esc_html( $line ); ?></span>
									<?php endforeach; ?>
								</span>
							<?php endif; ?>
							<span class="pb-tier-cta"><span class="pb-off"><?php esc_html_e( 'Seleziona', 'photo-booking' ); ?></span><span class="pb-on"><?php esc_html_e( 'Selezionato', 'photo-booking' ); ?></span></span>
						</button>
					<?php endforeach; ?>
				</div>
				<?php foreach ( $p['tiers'] as $t ) : ?>
					<?php
					if ( ! $t['extras'] ) {
						continue;
					}
					?>
					<div class="pb-extras" data-for="<?php echo esc_attr( $p['id'] . ':' . $t['key'] ); ?>" hidden>
						<p class="pb-eyebrow"><?php echo esc_html( sprintf( /* translators: %s sub-package */ __( 'Extra per %s', 'photo-booking' ), $t['name'] ) ); ?></p>
						<h3 class="pb-subtitle"><?php esc_html_e( 'Vuoi aggiungere qualcosa?', 'photo-booking' ); ?></h3>
						<?php foreach ( $t['extras'] as $e ) : ?>
							<label class="pb-extra">
								<input type="checkbox" name="extras[]" value="<?php echo esc_attr( $e['key'] ); ?>" data-price="<?php echo esc_attr( $e['price'] ); ?>" data-name="<?php echo esc_attr( $e['name'] ); ?>" disabled>
								<span class="pb-box" aria-hidden="true"></span>
								<span class="pb-extra-name"><?php echo esc_html( $e['name'] ); ?></span>
								<span class="pb-extra-price">+ <?php echo esc_html( $fmt( $e['price'] ) ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			<?php endforeach; ?>

			<div class="pb-continue">
				<button type="button" class="pb-btn" data-next="3" disabled><?php esc_html_e( 'Continua', 'photo-booking' ); ?> <span aria-hidden="true">→</span></button>
				<span class="pb-continue-note" data-fill="tier-total"></span>
			</div>
		</div>
	</section>

	<?php /* STEP 3 — data e ora */ ?>
	<section class="pb-flow-step" data-step="3" hidden>
		<div class="pb-flow-wrap">
			<button type="button" class="pb-backlink" data-go="back"><span aria-hidden="true">‹</span> <span data-fill="service-tier"></span></button>
			<p class="pb-eyebrow"><?php echo esc_html( sprintf( /* translators: 1: step 2: total */ __( 'Step %1$d di %2$d', 'photo-booking' ), 3, $total_steps ) ); ?></p>
			<h2 class="pb-title"><?php esc_html_e( 'Scegli la data.', 'photo-booking' ); ?></h2>
			<div class="pb-picker" aria-live="polite"></div>
			<div class="pb-continue">
				<button type="button" class="pb-btn" data-next="4" disabled><?php esc_html_e( 'Continua', 'photo-booking' ); ?> <span aria-hidden="true">→</span></button>
				<span class="pb-continue-note" data-fill="when-short"></span>
			</div>
			<?php if ( ! $logged_in ) : ?>
				<p class="pb-loginbox"><?php esc_html_e( 'Hai già un account?', 'photo-booking' ); ?> <a href="<?php echo esc_url( $login_url ); ?>" class="pb-login-link"><?php esc_html_e( 'Accedi qui', 'photo-booking' ); ?></a></p>
			<?php endif; ?>
		</div>
	</section>

	<?php /* STEP 4 — profilo e pagamento */ ?>
	<section class="pb-flow-step" data-step="4" hidden>
		<div class="pb-flow-wrap">
			<div class="pb-recap">
				<button type="button" class="pb-backlink" data-go="3"><?php esc_html_e( 'Cambia data', 'photo-booking' ); ?></button>
				<p class="pb-recap-line" data-fill="recap"></p>
			</div>
			<div class="pb-profile">
				<p class="pb-eyebrow"><?php echo esc_html( sprintf( /* translators: 1: step 2: total */ __( 'Step %1$d di %2$d', 'photo-booking' ), 4, $total_steps ) ); ?></p>
				<?php if ( $logged_in ) : ?>
					<h2 class="pb-title"><?php esc_html_e( 'Conferma i tuoi dati.', 'photo-booking' ); ?></h2>
					<p class="pb-lead"><?php esc_html_e( 'La prenotazione verrà aggiunta alla tua area personale, dove trovi acconto, saldo e la tua galleria.', 'photo-booking' ); ?></p>
				<?php else : ?>
					<h2 class="pb-title"><?php esc_html_e( 'Crea il tuo profilo.', 'photo-booking' ); ?></h2>
					<p class="pb-lead"><?php echo wp_kses_post( __( 'Il tuo spazio personale dove seguire ogni passo insieme: acconto, saldo, e infine la tua <em>galleria</em>.', 'photo-booking' ) ); ?></p>
					<p class="pb-loginbox"><?php esc_html_e( 'Hai già un account?', 'photo-booking' ); ?> <a href="<?php echo esc_url( $login_url ); ?>" class="pb-login-link"><?php esc_html_e( 'Accedi qui', 'photo-booking' ); ?></a></p>
				<?php endif; ?>

				<div class="pb-fields">
					<p class="pb-f"><label for="pb-first"><?php esc_html_e( 'Nome', 'photo-booking' ); ?></label><input id="pb-first" type="text" name="first_name" autocomplete="given-name" placeholder="Maria" required value="<?php echo esc_attr( $values['first_name'] ); ?>"></p>
					<p class="pb-f"><label for="pb-last"><?php esc_html_e( 'Cognome', 'photo-booking' ); ?></label><input id="pb-last" type="text" name="last_name" autocomplete="family-name" placeholder="Rossi" required value="<?php echo esc_attr( $values['last_name'] ); ?>"></p>
					<p class="pb-f"><label for="pb-phone"><?php esc_html_e( 'Telefono', 'photo-booking' ); ?></label><input id="pb-phone" type="tel" name="phone" autocomplete="tel" placeholder="+39 320 000 0000" required value="<?php echo esc_attr( $values['phone'] ); ?>"></p>
					<p class="pb-f"><label for="pb-email"><?php esc_html_e( 'Email', 'photo-booking' ); ?></label><input id="pb-email" type="email" name="email" autocomplete="email" placeholder="maria@email.com" required value="<?php echo esc_attr( $values['email'] ); ?>" <?php echo $logged_in ? 'readonly' : ''; ?>></p>
					<?php if ( ! $logged_in ) : ?>
						<p class="pb-f pb-f-full"><label for="pb-pass"><?php esc_html_e( 'Scegli una password', 'photo-booking' ); ?></label><input id="pb-pass" type="password" name="password" autocomplete="new-password" placeholder="••••••••" minlength="8" required></p>
					<?php endif; ?>
				</div>

				<div class="pb-pay">
					<p class="pb-eyebrow"><?php esc_html_e( 'Riepilogo', 'photo-booking' ); ?></p>
					<div class="pb-pay-lines" data-fill="lines"></div>
					<div class="pb-modes">
						<label class="pb-mode" data-mode="deposit">
							<input type="radio" name="mode" value="deposit" <?php checked( $values['mode'], 'deposit' ); ?>>
							<span class="pb-radio" aria-hidden="true"></span>
							<span><strong><?php esc_html_e( 'Paga ora l\'acconto', 'photo-booking' ); ?> <em data-amount="deposit"></em></strong>
							<small><?php esc_html_e( 'Il saldo lo paghi quando vuoi dalla tua area personale, oppure in studio.', 'photo-booking' ); ?></small></span>
						</label>
						<label class="pb-mode" data-mode="full">
							<input type="radio" name="mode" value="full" <?php checked( $values['mode'], 'full' ); ?>>
							<span class="pb-radio" aria-hidden="true"></span>
							<span><strong><?php esc_html_e( 'Paga ora l\'intero importo', 'photo-booking' ); ?> <em data-amount="full"></em></strong>
							<small><?php esc_html_e( 'Non dovrai pensare più a nulla.', 'photo-booking' ); ?></small></span>
						</label>
					</div>
					<?php if ( $credit > 0 ) : ?>
						<p class="pb-small"><?php echo wp_kses_post( sprintf( /* translators: %s amount */ __( 'Hai un credito di %s: verrà applicato automaticamente al pagamento.', 'photo-booking' ), wc_price( $credit ) ) ); ?></p>
					<?php endif; ?>
					<details class="pb-policy">
						<summary><?php esc_html_e( 'Condizioni di prenotazione e disdetta', 'photo-booking' ); ?></summary>
						<?php echo wp_kses_post( \PhotoBooking\Policy::policy_html() ); ?>
					</details>
					<label class="pb-check"><input type="checkbox" name="accept_policy" value="1" required><span class="pb-box" aria-hidden="true"></span><span><?php esc_html_e( 'Ho letto e accetto le condizioni di prenotazione e disdetta.', 'photo-booking' ); ?></span></label>
				</div>

				<button type="submit" class="pb-btn pb-submit" disabled><?php esc_html_e( 'Prenota', 'photo-booking' ); ?> <span aria-hidden="true">→</span></button>
				<p class="pb-small"><?php esc_html_e( 'Dopo il pagamento la richiesta arriva allo studio, che la confermerà al più presto via email. Da quel momento gestisci tutto dalla tua area personale.', 'photo-booking' ); ?></p>
			</div>
		</div>
	</section>
</form>
