<?php
/**
 * Password dimenticata, con la grafica della pagina di accesso.
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
$reset = isset( $_GET['reset-link-sent'] ) && 'true' === $_GET['reset-link-sent']; // phpcs:ignore WordPress.Security.NonceVerification
?>
<div class="pb-login">
	<aside class="pb-login-visual" style="background-image:url('<?php echo esc_url( $image ); ?>')">
		<div class="pb-login-visual-inner">
			<a class="pb-login-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"><?php else : ?><?php echo esc_html( get_bloginfo( 'name' ) ); ?><?php endif; ?></a>
			<div class="pb-login-claim">
				<h1><?php esc_html_e( 'Nessun problema, succede.', 'photo-booking' ); ?></h1>
				<p><?php esc_html_e( 'Ti mandiamo un link per scegliere una nuova password e tornare nella tua area riservata.', 'photo-booking' ); ?></p>
			</div>
			<p class="pb-login-copy">© <?php echo esc_html( gmdate( 'Y' ) . ' ' . get_bloginfo( 'name' ) ); ?></p>
		</div>
	</aside>
	<main class="pb-login-panel">
		<a class="pb-login-back" href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>"><span aria-hidden="true">←</span> <?php esc_html_e( 'Torna all\'accesso', 'photo-booking' ); ?></a>
		<div class="pb-login-box">
			<?php if ( $reset ) : ?>
				<h2><?php esc_html_e( 'Controlla la posta', 'photo-booking' ); ?></h2>
				<p class="pb-login-sub"><?php esc_html_e( 'Se l\'indirizzo è registrato, ti abbiamo inviato un\'email con il link per scegliere la nuova password. Guarda anche nello spam.', 'photo-booking' ); ?></p>
			<?php else : ?>
				<h2><?php esc_html_e( 'Password dimenticata', 'photo-booking' ); ?></h2>
				<p class="pb-login-sub"><?php esc_html_e( 'Inserisci l\'email con cui hai prenotato', 'photo-booking' ); ?></p>
				<div class="pb-login-notices"><?php wc_print_notices(); ?></div>
				<form method="post" class="woocommerce-ResetPassword lost_reset_password pb-login-form">
					<p class="pb-login-field">
						<label for="user_login"><?php esc_html_e( 'Email', 'photo-booking' ); ?></label>
						<input class="woocommerce-Input" type="text" name="user_login" id="user_login" autocomplete="username" placeholder="nome@esempio.com" />
					</p>
					<?php do_action( 'woocommerce_lostpassword_form' ); ?>
					<p class="pb-login-actions">
						<input type="hidden" name="wc_reset_password" value="true" />
						<button type="submit" class="pb-login-submit" value="<?php esc_attr_e( 'Invia il link', 'photo-booking' ); ?>"><?php esc_html_e( 'Invia il link', 'photo-booking' ); ?></button>
					</p>
					<?php wp_nonce_field( 'lost_password', 'woocommerce-lost-password-nonce' ); ?>
				</form>
			<?php endif; ?>
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
