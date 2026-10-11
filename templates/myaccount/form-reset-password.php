<?php
/**
 * Scelta della nuova password (dal link ricevuto via email), con la grafica della pagina di accesso.
 *
 * @var array $args  ( key, login )
 */

defined( 'ABSPATH' ) || exit;

use PhotoBooking\Package;

$image = '';
foreach ( Package::all() as $pkg ) {
	if ( $pkg->get_image_id() && ( ! $image || false !== stripos( $pkg->get_name(), 'matern' ) ) ) {
		$image = wp_get_attachment_image_url( $pkg->get_image_id(), 'large' );
	}
}
$image = apply_filters( 'pb_login_image', $image );
$theme = (array) get_option( 'apollo13_option_rife', array() );
$logo  = apply_filters( 'pb_login_logo', ! empty( $theme['logo_image']['url'] ) ? $theme['logo_image']['url'] : '' );
?>
<div class="pb-login">
	<aside class="pb-login-visual" style="background-image:url('<?php echo esc_url( $image ); ?>')">
		<div class="pb-login-visual-inner">
			<a class="pb-login-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"><?php else : ?><?php echo esc_html( get_bloginfo( 'name' ) ); ?><?php endif; ?></a>
			<div class="pb-login-claim">
				<h1><?php esc_html_e( 'Scegli la nuova password', 'photo-booking' ); ?></h1>
				<p><?php esc_html_e( 'Almeno 8 caratteri. Poi potrai accedere subito alla tua area riservata.', 'photo-booking' ); ?></p>
			</div>
			<p class="pb-login-copy">© <?php echo esc_html( gmdate( 'Y' ) . ' ' . get_bloginfo( 'name' ) ); ?></p>
		</div>
	</aside>
	<main class="pb-login-panel">
		<div class="pb-login-box">
			<h2><?php esc_html_e( 'Nuova password', 'photo-booking' ); ?></h2>
			<p class="pb-login-sub"><?php esc_html_e( 'Scrivila due volte per sicurezza', 'photo-booking' ); ?></p>
			<div class="pb-login-notices"><?php wc_print_notices(); ?></div>
			<form method="post" class="woocommerce-ResetPassword lost_reset_password pb-login-form">
				<p class="pb-login-field">
					<label for="password_1"><?php esc_html_e( 'Nuova password', 'photo-booking' ); ?></label>
					<input type="password" class="woocommerce-Input" name="password_1" id="password_1" autocomplete="new-password" placeholder="••••••••" />
				</p>
				<p class="pb-login-field">
					<label for="password_2"><?php esc_html_e( 'Ripeti la password', 'photo-booking' ); ?></label>
					<input type="password" class="woocommerce-Input" name="password_2" id="password_2" autocomplete="new-password" placeholder="••••••••" />
				</p>
				<input type="hidden" name="reset_key" value="<?php echo esc_attr( $args['key'] ); ?>" />
				<input type="hidden" name="reset_login" value="<?php echo esc_attr( $args['login'] ); ?>" />
				<?php do_action( 'woocommerce_resetpassword_form' ); ?>
				<p class="pb-login-actions">
					<input type="hidden" name="wc_reset_password" value="true" />
					<button type="submit" class="pb-login-submit" value="<?php esc_attr_e( 'Salva la password', 'photo-booking' ); ?>"><?php esc_html_e( 'Salva la password', 'photo-booking' ); ?></button>
				</p>
				<?php wp_nonce_field( 'reset_password', 'woocommerce-reset-password-nonce' ); ?>
			</form>
		</div>
	</main>
</div>
<script>
(function () {
	var box = document.querySelector('.pb-login-notices');
	if (!box) { return; }
	document.querySelectorAll('.woocommerce-error, .woocommerce-message, .woocommerce-info').forEach(function (n) { if (!box.contains(n)) { box.appendChild(n); } });
})();
</script>
