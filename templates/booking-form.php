<?php
/**
 * Booking form. Override in yourtheme/photo-booking/booking-form.php
 *
 * @var array $packages
 * @var int   $selected
 * @var array $values
 * @var array $errors
 * @var float $credit
 */

defined( 'ABSPATH' ) || exit;
?>
<form class="pb-booking" method="post" action="" novalidate data-selected="<?php echo esc_attr( $selected ); ?>" data-start="<?php echo esc_attr( $values['start'] ); ?>">
	<?php wp_nonce_field( 'pb_book', 'pb_nonce' ); ?>
	<input type="hidden" name="pb_action" value="book">
	<input type="hidden" name="start" value="<?php echo esc_attr( $values['start'] ); ?>">

	<ol class="pb-progress" aria-label="<?php esc_attr_e( 'Passaggi della prenotazione', 'photo-booking' ); ?>">
		<li data-step="1"><span>1</span> <?php esc_html_e( 'Pacchetto', 'photo-booking' ); ?></li>
		<li data-step="2"><span>2</span> <?php esc_html_e( 'Data e ora', 'photo-booking' ); ?></li>
		<li data-step="3"><span>3</span> <?php esc_html_e( 'I tuoi dati', 'photo-booking' ); ?></li>
		<li data-step="4"><span>4</span> <?php esc_html_e( 'Pagamento', 'photo-booking' ); ?></li>
	</ol>

	<?php if ( $errors ) : ?>
		<div class="pb-errors" role="alert">
			<ul><?php foreach ( $errors as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?></ul>
		</div>
	<?php endif; ?>

	<noscript><p class="pb-errors"><?php esc_html_e( 'Per scegliere data e orario è necessario abilitare JavaScript.', 'photo-booking' ); ?></p></noscript>

	<section class="pb-step" data-step="1">
		<h3 class="pb-step-title"><?php esc_html_e( 'Scegli il pacchetto', 'photo-booking' ); ?></h3>
		<div class="pb-packages">
			<?php foreach ( $packages as $p ) : ?>
				<?php $soldout = ( null !== $p['remaining'] && $p['remaining'] <= 0 ); ?>
				<label class="pb-package<?php echo $soldout ? ' is-soldout' : ''; ?>"
					data-id="<?php echo esc_attr( $p['id'] ); ?>"
					data-name="<?php echo esc_attr( $p['name'] ); ?>"
					data-price="<?php echo esc_attr( $p['price'] ); ?>"
					data-deposit="<?php echo esc_attr( $p['deposit'] ); ?>"
					data-duration="<?php echo esc_attr( $p['duration'] ); ?>"
					data-first="<?php echo esc_attr( $p['first'] ); ?>">
					<input type="radio" name="product_id" value="<?php echo esc_attr( $p['id'] ); ?>" <?php checked( $selected, $p['id'] ); ?> <?php disabled( $soldout ); ?>>
					<?php if ( $p['image'] ) : ?>
						<span class="pb-package-media"><img src="<?php echo esc_url( $p['image'] ); ?>" alt="" loading="lazy"></span>
					<?php endif; ?>
					<span class="pb-package-body">
						<span class="pb-package-name"><?php echo esc_html( $p['name'] ); ?></span>
						<span class="pb-package-meta">
							<strong><?php echo wp_kses_post( wc_price( $p['price'] ) ); ?></strong>
							· <?php echo esc_html( sprintf( /* translators: %d minutes */ __( '%d minuti', 'photo-booking' ), $p['duration'] ) ); ?>
							<?php if ( $p['deposit'] < $p['price'] ) : ?>
								· <?php echo wp_kses_post( sprintf( /* translators: %s amount */ __( 'acconto %s', 'photo-booking' ), wc_price( $p['deposit'] ) ) ); ?>
							<?php endif; ?>
						</span>
						<?php if ( $p['excerpt'] ) : ?>
							<span class="pb-package-excerpt"><?php echo wp_kses_post( wpautop( $p['excerpt'] ) ); ?></span>
						<?php endif; ?>
						<?php if ( $p['includes'] ) : ?>
							<span class="pb-package-includes">
								<?php foreach ( array_filter( array_map( 'trim', explode( "\n", $p['includes'] ) ) ) as $line ) : ?>
									<span><?php echo esc_html( $line ); ?></span>
								<?php endforeach; ?>
							</span>
						<?php endif; ?>
						<?php if ( null !== $p['remaining'] ) : ?>
							<span class="pb-package-places">
								<?php
								echo $soldout ? esc_html__( 'Esaurito', 'photo-booking' ) : esc_html( sprintf( /* translators: %d places */ _n( 'Ultimo posto disponibile', 'Ancora %d posti disponibili', $p['remaining'], 'photo-booking' ), $p['remaining'] ) );
								?>
							</span>
						<?php endif; ?>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="pb-step" data-step="2">
		<h3 class="pb-step-title"><?php esc_html_e( 'Scegli data e orario', 'photo-booking' ); ?></h3>
		<div class="pb-picker" aria-live="polite"></div>
	</section>

	<section class="pb-step" data-step="3">
		<h3 class="pb-step-title"><?php esc_html_e( 'I tuoi dati', 'photo-booking' ); ?></h3>
		<div class="pb-fields">
			<p class="pb-field pb-half"><label for="pb-first"><?php esc_html_e( 'Nome', 'photo-booking' ); ?> *</label><input id="pb-first" type="text" name="first_name" autocomplete="given-name" required value="<?php echo esc_attr( $values['first_name'] ); ?>"></p>
			<p class="pb-field pb-half"><label for="pb-last"><?php esc_html_e( 'Cognome', 'photo-booking' ); ?> *</label><input id="pb-last" type="text" name="last_name" autocomplete="family-name" required value="<?php echo esc_attr( $values['last_name'] ); ?>"></p>
			<p class="pb-field pb-half"><label for="pb-email"><?php esc_html_e( 'Email', 'photo-booking' ); ?> *</label><input id="pb-email" type="email" name="email" autocomplete="email" required value="<?php echo esc_attr( $values['email'] ); ?>"></p>
			<p class="pb-field pb-half"><label for="pb-phone"><?php esc_html_e( 'Telefono', 'photo-booking' ); ?> *</label><input id="pb-phone" type="tel" name="phone" autocomplete="tel" required value="<?php echo esc_attr( $values['phone'] ); ?>"></p>
			<p class="pb-field pb-half"><label for="pb-part"><?php esc_html_e( 'Chi partecipa', 'photo-booking' ); ?></label><input id="pb-part" type="text" name="participants" placeholder="<?php esc_attr_e( 'es. 2 adulti, 2 bambini, nonna', 'photo-booking' ); ?>" value="<?php echo esc_attr( $values['participants'] ); ?>"></p>
			<p class="pb-field pb-half"><label for="pb-kids"><?php esc_html_e( 'Età dei bambini', 'photo-booking' ); ?></label><input id="pb-kids" type="text" name="children" placeholder="<?php esc_attr_e( 'es. 3 anni e 8 mesi', 'photo-booking' ); ?>" value="<?php echo esc_attr( $values['children'] ); ?>"></p>
			<p class="pb-field"><label for="pb-notes"><?php esc_html_e( 'Note per lo studio', 'photo-booking' ); ?></label><textarea id="pb-notes" name="notes" rows="3"><?php echo esc_textarea( $values['notes'] ); ?></textarea></p>
		</div>
	</section>

	<section class="pb-step" data-step="4">
		<h3 class="pb-step-title"><?php esc_html_e( 'Riepilogo e pagamento', 'photo-booking' ); ?></h3>
		<dl class="pb-summary">
			<dt><?php esc_html_e( 'Pacchetto', 'photo-booking' ); ?></dt><dd data-summary="package">—</dd>
			<dt><?php esc_html_e( 'Quando', 'photo-booking' ); ?></dt><dd data-summary="when">—</dd>
			<dt><?php esc_html_e( 'Totale', 'photo-booking' ); ?></dt><dd data-summary="total">—</dd>
		</dl>
		<div class="pb-modes">
			<label class="pb-mode" data-mode="deposit">
				<input type="radio" name="mode" value="deposit" <?php checked( $values['mode'], 'deposit' ); ?>>
				<span><strong><?php esc_html_e( 'Paga solo l\'acconto', 'photo-booking' ); ?> <em data-amount="deposit"></em></strong>
				<small><?php esc_html_e( 'Il saldo lo paghi quando vuoi dalla tua area privata, oppure in studio.', 'photo-booking' ); ?></small></span>
			</label>
			<label class="pb-mode" data-mode="full">
				<input type="radio" name="mode" value="full" <?php checked( $values['mode'], 'full' ); ?>>
				<span><strong><?php esc_html_e( 'Paga l\'intero importo', 'photo-booking' ); ?> <em data-amount="full"></em></strong>
				<small><?php esc_html_e( 'Non dovrai pensare più a nulla.', 'photo-booking' ); ?></small></span>
			</label>
		</div>
		<?php if ( $credit > 0 ) : ?>
			<p class="pb-credit-note">
				<?php echo wp_kses_post( sprintf( /* translators: %s amount */ __( 'Hai un credito di %s: verrà applicato automaticamente al pagamento.', 'photo-booking' ), wc_price( $credit ) ) ); ?>
			</p>
		<?php endif; ?>
		<details class="pb-policy-box">
			<summary><?php esc_html_e( 'Condizioni di prenotazione e disdetta', 'photo-booking' ); ?></summary>
			<?php echo wp_kses_post( \PhotoBooking\Policy::policy_html() ); ?>
		</details>
		<p class="pb-field pb-check"><label><input type="checkbox" name="accept_policy" value="1" required> <?php esc_html_e( 'Ho letto e accetto le condizioni di prenotazione e disdetta.', 'photo-booking' ); ?> *</label></p>
		<p class="pb-info"><?php esc_html_e( 'Dopo il pagamento la richiesta arriva allo studio, che la confermerà al più presto via email. Se l\'orario non fosse disponibile ti proporremo un\'alternativa o ti rimborseremo.', 'photo-booking' ); ?></p>
		<button type="submit" class="pb-submit button alt"><?php esc_html_e( 'Vai al pagamento', 'photo-booking' ); ?></button>
	</section>

	<div class="pb-nav">
		<button type="button" class="pb-back"><?php esc_html_e( '← Indietro', 'photo-booking' ); ?></button>
		<button type="button" class="pb-next button"><?php esc_html_e( 'Avanti →', 'photo-booking' ); ?></button>
	</div>
</form>
