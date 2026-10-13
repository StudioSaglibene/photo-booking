<?php
/**
 * Cookie banner (Garante privacy, linee guida 10 giugno 2021): technical cookies always on,
 * statistics / marketing scripts blocked until consent, "Rifiuta" as easy as "Accetta",
 * choice stored for 6 months, preferences reopenable from the footer.
 */

namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

class Cookies {

	const COOKIE = 'pb_consent';
	const DAYS   = 180;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_head', array( __CLASS__, 'consent_defaults' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 5 );
		add_filter( 'widget_text', array( __CLASS__, 'footer_link' ), 20 );
		add_shortcode( 'pb_cookie_preferences', array( __CLASS__, 'shortcode' ) );
	}

	private static function active() {
		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->editor ) && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			return false;
		}
		return true;
	}

	public static function assets() {
		if ( ! self::active() ) {
			return;
		}
		wp_enqueue_style( 'pb-cookies', PB_URL . 'assets/css/cookies.css', array(), pb_asset_ver( 'assets/css/cookies.css' ) );
		wp_enqueue_script( 'pb-cookies', PB_URL . 'assets/js/cookies.js', array(), pb_asset_ver( 'assets/js/cookies.js' ), true );
		wp_localize_script(
			'pb-cookies',
			'pbCookies',
			array(
				'name'   => self::COOKIE,
				'days'   => self::DAYS,
				'secure' => is_ssl(),
			)
		);
	}

	/** Google Consent Mode v2 defaults: everything denied until the visitor chooses. Harmless when no tag is loaded. */
	public static function consent_defaults() {
		if ( ! self::active() ) {
			return;
		}
		echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',functionality_storage:'granted',security_storage:'granted',wait_for_update:500});</script>\n";
	}

	public static function policy_url( $slug ) {
		$page = get_page_by_path( $slug );
		return $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
	}

	public static function render() {
		if ( ! self::active() ) {
			return;
		}
		$s      = Settings::all();
		$cookie = self::policy_url( 'cookie-policy' );
		$priv   = self::policy_url( 'privacy-policy' );
		// Third-party scripts stay inert (type="text/plain") until the matching category is accepted.
		foreach ( array( 'statistics' => 'cookie_scripts_stats', 'marketing' => 'cookie_scripts_marketing' ) as $cat => $key ) {
			$code = trim( (string) ( $s[ $key ] ?? '' ) );
			if ( '' === $code ) {
				continue;
			}
			echo '<template data-pb-consent="' . esc_attr( $cat ) . '">' . $code . "</template>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- admin-entered tags, printed inert.
		}
		?>
		<div class="pb-cookie" id="pb-cookie" role="dialog" aria-modal="false" aria-labelledby="pb-cookie-title" aria-describedby="pb-cookie-text" hidden>
			<button type="button" class="pb-cookie-close" data-pb-cookie="reject" aria-label="<?php esc_attr_e( 'Chiudi e continua senza accettare', 'photo-booking' ); ?>">&times;</button>
			<div class="pb-cookie-main">
				<p class="pb-cookie-eyebrow"><?php esc_html_e( 'Cookie', 'photo-booking' ); ?></p>
				<h2 class="pb-cookie-title" id="pb-cookie-title"><?php esc_html_e( 'La tua privacy, la tua scelta.', 'photo-booking' ); ?></h2>
				<p class="pb-cookie-text" id="pb-cookie-text">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: 1: cookie policy url, 2: privacy policy url */
							__( 'Questo sito usa cookie tecnici, necessari per prenotare e accedere alla tua area. Con il tuo consenso possiamo usare anche cookie statistici e di marketing: puoi accettarli, rifiutarli o scegliere categoria per categoria, e cambiare idea quando vuoi. Leggi la <a href="%1$s">Cookie policy</a> e la <a href="%2$s">Privacy policy</a>.', 'photo-booking' ),
							esc_url( $cookie ),
							esc_url( $priv )
						)
					);
					?>
				</p>
				<div class="pb-cookie-actions">
					<button type="button" class="pb-cookie-btn pb-cookie-btn--fill" data-pb-cookie="accept"><?php esc_html_e( 'Accetta tutti', 'photo-booking' ); ?></button>
					<button type="button" class="pb-cookie-btn" data-pb-cookie="reject"><?php esc_html_e( 'Rifiuta', 'photo-booking' ); ?></button>
					<button type="button" class="pb-cookie-link" data-pb-cookie="customize" aria-expanded="false" aria-controls="pb-cookie-prefs"><?php esc_html_e( 'Personalizza', 'photo-booking' ); ?></button>
				</div>
			</div>
			<div class="pb-cookie-prefs" id="pb-cookie-prefs" hidden>
				<label class="pb-cookie-row">
					<span class="pb-cookie-switch is-locked"><input type="checkbox" checked disabled></span>
					<span><strong><?php esc_html_e( 'Necessari', 'photo-booking' ); ?></strong><small><?php esc_html_e( 'Sempre attivi: accesso, carrello della prenotazione, sicurezza dei pagamenti.', 'photo-booking' ); ?></small></span>
				</label>
				<label class="pb-cookie-row">
					<span class="pb-cookie-switch"><input type="checkbox" name="statistics"></span>
					<span><strong><?php esc_html_e( 'Statistiche', 'photo-booking' ); ?></strong><small><?php esc_html_e( 'Ci aiutano a capire come viene usato il sito, in forma aggregata.', 'photo-booking' ); ?></small></span>
				</label>
				<label class="pb-cookie-row">
					<span class="pb-cookie-switch"><input type="checkbox" name="marketing"></span>
					<span><strong><?php esc_html_e( 'Marketing', 'photo-booking' ); ?></strong><small><?php esc_html_e( 'Permettono di mostrarti contenuti e annunci in linea con i tuoi interessi.', 'photo-booking' ); ?></small></span>
				</label>
				<div class="pb-cookie-actions">
					<button type="button" class="pb-cookie-btn pb-cookie-btn--fill" data-pb-cookie="save"><?php esc_html_e( 'Salva preferenze', 'photo-booking' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/** Adds "Preferenze cookie" next to the legal links of the footer widget. */
	public static function footer_link( $text ) {
		if ( ! self::active() || false === strpos( $text, 'Cookie policy' ) || false !== strpos( $text, 'pb-cookie-open' ) ) {
			return $text;
		}
		return $text . ' · <a href="#cookie-preferences" class="pb-cookie-open">' . esc_html__( 'Preferenze cookie', 'photo-booking' ) . '</a>';
	}

	public static function shortcode() {
		return '<a href="#cookie-preferences" class="pb-cookie-open">' . esc_html__( 'Gestisci le preferenze cookie', 'photo-booking' ) . '</a>';
	}
}
