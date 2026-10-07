<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI: wp photo-booking <command>
 */
class Cli {

	public static function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'photo-booking', __CLASS__ );
		}
	}

	/**
	 * Create the sample Christmas packages (draft prices, editable later) and the booking page.
	 *
	 * ## OPTIONS
	 *
	 * [--year=<year>]
	 * : Year of the Christmas sessions. Default: current year.
	 *
	 * [--publish]
	 * : Publish the packages immediately (default: draft).
	 *
	 * @subcommand seed-natale
	 */
	public function seed_natale( $args, $assoc ) {
		$year   = (int) ( $assoc['year'] ?? gmdate( 'Y' ) );
		$status = isset( $assoc['publish'] ) ? 'publish' : 'draft';
		$term   = term_exists( 'natale', 'product_cat' );
		if ( ! $term ) {
			$term = wp_insert_term( 'Natale', 'product_cat', array( 'slug' => 'natale' ) );
		}
		$cat   = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		$all   = array( "$year-11-28", "$year-11-29", "$year-12-05", "$year-12-06", "$year-12-12", "$year-12-13" );
		$suns  = array( "$year-11-29", "$year-12-06", "$year-12-13" );
		$range = '09:30-13:00, 14:30-18:00';
		$win   = static function ( $dates ) use ( $range ) {
			return array_map(
				static function ( $d ) use ( $range ) {
					return array( 'date' => $d, 'ranges' => $range );
				},
				$dates
			);
		};
		$packages = array(
			array( 'Natale Mini', 150, 40, 30, 15, $all, 0, "Set natalizio in studio\nGuida agli abiti\n5 foto digitali scelte da te", 'La mattina di Natale in versione essenziale.' ),
			array( 'Natale Classico', 220, 60, 30, 15, $all, 0, "Tutto del Mini\nGalleria completa in alta risoluzione", 'Il servizio più scelto: tutte le foto della sessione.' ),
			array( 'Natale Ricordo', 290, 80, 45, 15, $all, 0, "Tutto del Classico\n3 stampe 20×30\nBreve video backstage", 'Per chi vuole qualcosa da tenere in mano e regalare.' ),
			array( 'Natale in Famiglia', 380, 100, 60, 15, $suns, 8, "Pensato per famiglie allargate, con i nonni\nGalleria completa\nPiccolo album 20×20", 'Solo la domenica, otto posti in tutto.' ),
		);
		$instructions = "Arriva 10 minuti prima. Porta un cambio per i più piccoli e una piccola merenda.\nNella guida trovi gli abiti a tema disponibili in studio e i consigli sui colori.";
		$order        = 0;
		foreach ( $packages as $p ) {
			$existing = get_posts( array( 'post_type' => 'product', 'title' => $p[0], 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) );
			$product  = $existing ? wc_get_product( $existing[0] ) : new \WC_Product_Pb_Package();
			if ( ! $product instanceof \WC_Product_Pb_Package ) {
				\WP_CLI::warning( "Esiste già un prodotto '{$p[0]}' di un altro tipo, salto." );
				continue;
			}
			$product->set_name( $p[0] );
			$product->set_status( $status );
			$product->set_regular_price( (string) $p[1] );
			$product->set_short_description( $p[8] );
			$product->set_category_ids( array( $cat ) );
			$product->set_virtual( true );
			$product->set_sold_individually( true );
			$product->set_menu_order( $order++ );
			$product->update_meta_data( '_pb_deposit', $p[2] );
			$product->update_meta_data( '_pb_duration', $p[3] );
			$product->update_meta_data( '_pb_buffer', $p[4] );
			$product->update_meta_data( '_pb_windows', $win( $p[5] ) );
			$product->update_meta_data( '_pb_capacity', $p[6] ? $p[6] : '' );
			$product->update_meta_data( '_pb_includes', $p[7] );
			$product->update_meta_data( '_pb_instructions', $instructions );
			$id = $product->save();
			\WP_CLI::log( sprintf( '%s #%d (%s €, acconto %s €, %d min)', $p[0], $id, $p[1], $p[2], $p[3] ) );
		}

		if ( ! Settings::get( 'booking_page_id' ) || ! get_post( Settings::get( 'booking_page_id' ) ) ) {
			$page = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Prenota',
					'post_name'    => 'prenota',
					'post_content' => '<!-- wp:shortcode -->[prenotazione]<!-- /wp:shortcode -->',
				)
			);
			Settings::update( array( 'booking_page_id' => $page ) );
			\WP_CLI::log( 'Pagina di prenotazione creata: ' . get_permalink( $page ) );
		}
		\WP_CLI::success( 'Pacchetti di Natale creati (' . $status . ').' );
	}

	/**
	 * Run scheduled jobs now.
	 */
	public function cron() {
		Cron::run();
		\WP_CLI::success( 'Eseguito.' );
	}
}
