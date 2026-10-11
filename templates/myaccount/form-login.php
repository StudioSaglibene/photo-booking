<?php
/**
 * Login dell'area clienti (sostituisce myaccount/form-login.php di WooCommerce).
 * Override in yourtheme/photo-booking/myaccount/form-login.php
 *
 * Filtri: pb_login_image (URL foto a sinistra), pb_login_logo (URL logo chiaro).
 */

defined( 'ABSPATH' ) || exit;

use PhotoBooking\Package;
use PhotoBooking\Settings;

$image = '';
foreach ( Package::all() as $pkg ) {
	if ( $pkg->get_image_id() && ( ! $image || false !== stripos( $pkg->get_name(), 'matern' ) ) ) {
		$image = wp_get_attachment_image_url( $pkg->get_image_id(), 'large' );
	}
}
$image = apply_filters( 'pb_login_image', $image );
$theme = (array) get_option( 'apollo13_option_rife', array() );
$logo  = ! empty( $theme['logo_image']['url'] ) ? $theme['logo_image']['url'] : ( get_theme_mod( 'custom_logo' ) ? wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'full' ) : '' );
$logo  = apply_filters( 'pb_login_logo', $logo );
$book_url = Settings::booking_page_url();

do_action( 'woocommerce_before_customer_login_form' );
?>
<div class="pb-login">
	<aside class="pb-login-visual" style="background-image:url('<?php echo esc_url( $image ); ?>')">
		<div class="pb-login-visual-inner">
			<a class="pb-login-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"><?php else : ?><?php echo esc_html( get_bloginfo( 'name' ) ); ?><?php endif; ?>
			</a>
			<div class="pb-login-claim">
				<h1><?php esc_html_e( 'L\'inizio del tuo viaggio nei ricordi', 'photo-booking' ); ?></h1>
				<p><?php esc_html_e( 'Accedi alla tua area riservata per gestire le tue prenotazioni, visualizzare le gallerie e custodire i momenti più preziosi della tua famiglia.', 'photo-booking' ); ?></p>
			</div>
			<p class="pb-login-copy">© <?php echo esc_html( gmdate( 'Y' ) . ' ' . get_bloginfo( 'name' ) ); ?></p>
		</div>
	</aside>

	<main class="pb-login-panel">
		<a class="pb-login-back" href="<?php echo esc_url( home_url( '/' ) ); ?>"><span aria-hidden="true">←</span> <?php esc_html_e( 'Torna al sito', 'photo-booking' ); ?></a>
		<div class="pb-login-box">
			<h2><?php esc_html_e( 'Bentornato', 'photo-booking' ); ?></h2>
			<p class="pb-login-sub"><?php esc_html_e( 'Inserisci le tue credenziali per accedere', 'photo-booking' ); ?></p>

			<div class="pb-login-notices"><?php wc_print_notices(); ?></div>

			<form class="woocommerce-form woocommerce-form-login login pb-login-form" method="post" novalidate>
				<?php do_action( 'woocommerce_login_form_start' ); ?>
				<p class="pb-login-field">
					<label for="username"><?php esc_html_e( 'Email', 'photo-booking' ); ?></label>
					<input type="text" name="username" id="username" autocomplete="username" placeholder="nome@esempio.com" value="<?php echo ( ! empty( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>" /><?php // phpcs:ignore WordPress.Security.NonceVerification.Missing ?>
				</p>
				<p class="pb-login-field">
					<label for="password"><?php esc_html_e( 'Password', 'photo-booking' ); ?> <a class="pb-login-lost" href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Password dimenticata?', 'photo-booking' ); ?></a></label>
					<input class="woocommerce-Input" type="password" name="password" id="password" autocomplete="current-password" placeholder="••••••••" />
				</p>
				<?php do_action( 'woocommerce_login_form' ); ?>
				<p class="pb-login-actions">
					<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
					<input type="hidden" name="rememberme" value="forever">
					<input type="hidden" name="redirect" value="<?php echo esc_url( ! empty( $_GET['pb_to'] ) ? wp_validate_redirect( rawurldecode( wp_unslash( $_GET['pb_to'] ) ), wc_get_page_permalink( 'myaccount' ) ) : wc_get_page_permalink( 'myaccount' ) ); // phpcs:ignore WordPress.Security.NonceVerification ?>">
					<button type="submit" class="pb-login-submit" name="login" value="<?php esc_attr_e( 'Accedi', 'photo-booking' ); ?>"><?php esc_html_e( 'Accedi', 'photo-booking' ); ?></button>
				</p>
				<?php do_action( 'woocommerce_login_form_end' ); ?>
			</form>

		</div>
	</main>
</div>
<script>
/* Il tema stampa gli avvisi WooCommerce sopra il contenuto: li spostiamo dentro il modulo. */
(function () {
	var box = document.querySelector('.pb-login-notices');
	if (!box) { return; }
	document.querySelectorAll('.woocommerce-error, .woocommerce-message, .woocommerce-info').forEach(function (n) {
		if (!box.contains(n)) { box.appendChild(n); }
	});
	document.querySelectorAll('.woocommerce-notices-wrapper').forEach(function (w) { if (!w.children.length) { w.remove(); } });
})();
</script>
<?php do_action( 'woocommerce_after_customer_login_form' ); ?>
