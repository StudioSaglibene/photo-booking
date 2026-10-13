<?php
/**
 * On-page SEO shipped with the plugin: Yoast titles/descriptions per page, demo content kept out of the index,
 * social profiles and default Open Graph image, LocalBusiness schema for the studio.
 */

namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

class Seo {

	public static function init() {
		add_filter( 'wpseo_schema_organization', array( __CLASS__, 'local_business' ) );
	}

	/** Title, description and focus keyphrase per page slug (pages first, then demo content to hide). */
	public static function pages() {
		return array(
			'home'                  => array(
				'title' => 'Fotografa Newborn e Famiglia a Palermo | Viviana Saglibene',
				'desc'  => 'Servizi fotografici newborn, maternità, bebè, famiglia e smash cake a Palermo, Capaci e Carini. Studio accogliente, stile naturale. Prenota online data e orario.',
				'kw'    => 'fotografa newborn Palermo',
			),
			'maternita'             => array(
				'title' => 'Servizio Fotografico Maternità a Palermo | Viviana Saglibene',
				'desc'  => 'Foto gravidanza a Palermo, in studio o all\'aperto, tra la 27ª e la 33ª settimana, con abiti cuciti a mano a disposizione. Anche con papà e fratellini. Prenota online.',
				'kw'    => 'servizio fotografico maternità Palermo',
			),
			'neonato'               => array(
				'title' => 'Servizio Fotografico Newborn a Palermo | Viviana Saglibene',
				'desc'  => 'Foto neonato nei primi giorni di vita, in uno studio sicuro e accogliente a Capaci (Palermo). Sessione di circa 3 ore nel rispetto dei tempi del bambino. Prenota online.',
				'kw'    => 'servizio fotografico newborn Palermo',
			),
			'bebe'                  => array(
				'title' => 'Servizio Fotografico Bebè a Palermo | Viviana Saglibene',
				'desc'  => 'Foto bebè dai 6 mesi: sorrisi, prime conquiste e smash cake per il primo compleanno, in studio a Capaci (Palermo). Immagini spontanee e naturali. Prenota online.',
				'kw'    => 'servizio fotografico bebè Palermo',
			),
			'famiglia'              => array(
				'title' => 'Servizio Fotografico Famiglia a Palermo | Viviana Saglibene',
				'desc'  => 'Foto di famiglia all\'aperto al tramonto o in studio, senza pose forzate: momenti veri tra Palermo, Capaci e Carini. Scegli pacchetto, data e orario online.',
				'kw'    => 'servizio fotografico famiglia Palermo',
			),
			'lo-studio'             => array(
				'title' => 'Studio Fotografico a Capaci (Palermo) | Viviana Saglibene',
				'desc'  => 'Uno studio accogliente in Via Mariagrazia Costanzo 1 a Capaci, pensato per neonati, bambini e famiglie: luce naturale, abiti e set curati, stile dolce e raffinato.',
				'kw'    => 'studio fotografico Capaci',
			),
			'chi-sono'              => array(
				'title' => 'Chi sono: fotografa di famiglia a Palermo | Viviana Saglibene',
				'desc'  => 'Dal 2019 fotografo famiglie, gravidanze e neonati a Palermo. Mamma di due bambini, racconto emozioni autentiche con uno stile naturale e delicato. Scopri la mia storia.',
				'kw'    => 'fotografa di famiglia Palermo',
			),
			'contatti'              => array(
				'title' => 'Contatti: fotografa a Palermo e Capaci | Viviana Saglibene',
				'desc'  => 'Contatta Viviana Saglibene per un servizio fotografico newborn, maternità o famiglia a Palermo: telefono, email e studio a Capaci. Oppure prenota online in pochi minuti.',
				'kw'    => 'fotografa Palermo contatti',
			),
			'prenota'               => array(
				'title' => 'Prenota un Servizio Fotografico a Palermo | Viviana Saglibene',
				'desc'  => 'Prenota online la tua sessione: newborn, maternità, famiglia, smash cake e Natale. Scegli pacchetto, data e orario, paga l\'acconto con carta e ricevi la conferma via email.',
				'kw'    => 'prenota servizio fotografico Palermo',
			),
			'condizioni-di-vendita' => array( 'noindex' => true ),
			// Theme demo content still published on the site: keep it out of Google until it is deleted or rewritten.
			'albums'                => array( 'noindex' => true ),
			'blog'                  => array( 'noindex' => true ),
		);
	}

	/** Demo posts shipped with the theme (lorem ipsum): noindex, out of the sitemap. */
	public static function demo_posts() {
		return array( 'night-colors', 'north-pole', 'lost-paradise', 'black-coffee', 'fruit-boom', 'adventure-trip', 'last-shoot', 'last-day-in-vegas' );
	}

	public static function apply() {
		foreach ( self::pages() as $slug => $d ) {
			$page = get_page_by_path( $slug );
			if ( ! $page ) {
				continue;
			}
			self::set_meta( $page->ID, $d );
		}
		foreach ( self::demo_posts() as $slug ) {
			$post = get_page_by_path( $slug, OBJECT, 'post' );
			if ( $post ) {
				self::set_meta( $post->ID, array( 'noindex' => true ) );
			}
		}

		$titles = get_option( 'wpseo_titles', array() );
		if ( is_array( $titles ) ) {
			$titles['separator']          = 'sc-pipe';
			$titles['title-home-wpseo']   = self::pages()['home']['title'];
			$titles['metadesc-home-wpseo'] = self::pages()['home']['desc'];
			$titles['company_or_person']  = 'company';
			$titles['company_name']       = 'Viviana Saglibene Fotografia';
			$titles['website_name']       = 'Viviana Saglibene Fotografia';
			$titles['noindex-album']      = true;
			update_option( 'wpseo_titles', $titles );
		}

		$social = get_option( 'wpseo_social', array() );
		if ( is_array( $social ) ) {
			$social['facebook_site']  = 'https://www.facebook.com/VivianaPhotographer/';
			$social['instagram_url']  = 'https://www.instagram.com/vivianasaglibenefotografia/';
			$social['opengraph']      = true;
			$social['twitter']        = true;
			$og                       = self::find_attachment( 'DSC08828' );
			if ( $og ) {
				$social['og_default_image']    = wp_get_attachment_image_url( $og, 'large' );
				$social['og_default_image_id'] = $og;
			}
			update_option( 'wpseo_social', $social );
		}
		update_option( 'pb_seo_version', PB_VERSION, false );
	}

	private static function set_meta( $post_id, array $d ) {
		if ( ! empty( $d['noindex'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', '1' );
		} else {
			update_post_meta( $post_id, '_yoast_wpseo_title', $d['title'] );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $d['desc'] );
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $d['kw'] );
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-title', $d['title'] );
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-description', $d['desc'] );
		}
		// A save refreshes the Yoast indexable that caches title, description and robots.
		wp_update_post( array( 'ID' => $post_id ) );
	}

	private static function find_attachment( $name ) {
		$found = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids', 's' => $name ) );
		return $found ? (int) $found[0] : 0;
	}

	/** Yoast's Organization piece becomes a LocalBusiness with the studio's address and contacts. */
	public static function local_business( $data ) {
		$s = Settings::all();
		$data['@type'] = array( 'Organization', 'LocalBusiness', 'ProfessionalService' );
		$data['name']  = 'Viviana Saglibene Fotografia';
		$data['description'] = 'Fotografa di famiglia a Palermo: servizi fotografici newborn, maternità, bebè, famiglia, smash cake e Natale in studio a Capaci.';
		$data['address'] = array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => 'Via Mariagrazia Costanzo 1',
			'postalCode'      => '90040',
			'addressLocality' => 'Capaci',
			'addressRegion'   => 'PA',
			'addressCountry'  => 'IT',
		);
		if ( ! empty( $s['business_phone'] ) ) {
			$data['telephone'] = $s['business_phone'];
		}
		if ( ! empty( $s['business_email'] ) ) {
			$data['email'] = $s['business_email'];
		}
		$data['priceRange'] = '€€';
		$data['areaServed'] = array(
			array( '@type' => 'City', 'name' => 'Palermo' ),
			array( '@type' => 'City', 'name' => 'Capaci' ),
			array( '@type' => 'City', 'name' => 'Carini' ),
		);
		$data['knowsAbout'] = array( 'Fotografia newborn', 'Fotografia maternità', 'Fotografia di famiglia', 'Smash cake' );
		$data['hasMap']     = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( 'Via Mariagrazia Costanzo 1, 90040 Capaci PA' );
		return $data;
	}
}
