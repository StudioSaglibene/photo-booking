<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * "Pacchetto fotografico" product type: registration, admin fields, configuration access.
 */
class Package {

	const TYPE = 'pb_package';

	public static function init() {
		require_once PB_DIR . 'includes/class-wc-product-pb-package.php';

		add_filter( 'product_type_selector', array( __CLASS__, 'add_type' ) );
		add_filter( 'woocommerce_product_class', array( __CLASS__, 'product_class' ), 10, 2 );
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tabs' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_process_product_meta_' . self::TYPE, array( __CLASS__, 'save' ) );
		add_action( 'admin_footer', array( __CLASS__, 'admin_js' ) );
		add_action( 'woocommerce_' . self::TYPE . '_add_to_cart', array( __CLASS__, 'single_add_to_cart' ) );
	}

	public static function add_type( $types ) {
		$types[ self::TYPE ] = __( 'Pacchetto fotografico', 'photo-booking' );
		return $types;
	}

	public static function product_class( $classname, $type ) {
		return self::TYPE === $type ? 'WC_Product_Pb_Package' : $classname;
	}

	public static function is_package( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		return $product instanceof \WC_Product && self::TYPE === $product->get_type();
	}

	/**
	 * Package configuration.
	 *
	 * @param \WC_Product|int $product
	 * @return array
	 */
	public static function config( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		if ( ! $product ) {
			return array();
		}
		$price   = (float) $product->get_price( 'edit' );
		$deposit = (float) $product->get_meta( '_pb_deposit' );
		if ( $deposit <= 0 || $deposit > $price ) {
			$deposit = $price;
		}
		$windows = $product->get_meta( '_pb_windows' );
		return array(
			'id'           => $product->get_id(),
			'name'         => $product->get_name(),
			'price'        => $price,
			'deposit'      => round( $deposit, 2 ),
			'duration'     => max( 5, (int) ( $product->get_meta( '_pb_duration' ) ?: 30 ) ),
			'buffer'       => max( 0, (int) $product->get_meta( '_pb_buffer' ) ),
			'windows'      => is_array( $windows ) ? $windows : array(),
			'capacity'     => max( 0, (int) $product->get_meta( '_pb_capacity' ) ),
			'includes'     => (string) $product->get_meta( '_pb_includes' ),
			'instructions' => (string) $product->get_meta( '_pb_instructions' ),
			'label'        => (string) $product->get_meta( '_pb_label' ),
			'tiers'        => self::tiers( $product ),
			'from'         => self::from_price( $product ),
		);
	}

	/**
	 * Sub-packages (e.g. Standard / Medium / Premium), each with its own price and optional extras.
	 *
	 * @param \WC_Product|int $product
	 * @return array[] { key, name, price, description, includes, extras: { key, name, price }[] }
	 */
	public static function tiers( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		$tiers   = $product ? $product->get_meta( '_pb_tiers' ) : array();
		return is_array( $tiers ) ? array_values( $tiers ) : array();
	}

	/** Lowest bookable price ("da €…"): cheapest sub-package, or the product price. */
	public static function from_price( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		if ( ! $product ) {
			return 0.0;
		}
		$prices = wp_list_pluck( self::tiers( $product ), 'price' );
		return $prices ? (float) min( $prices ) : (float) $product->get_price( 'edit' );
	}

	/**
	 * Price of a sub-package + extras chosen by the customer.
	 *
	 * @param int      $product_id
	 * @param string   $tier_key
	 * @param string[] $extra_keys
	 * @return array|\WP_Error { total, tier, extras }
	 */
	public static function quote( $product_id, $tier_key = '', array $extra_keys = array() ) {
		$cfg = self::config( $product_id );
		if ( ! $cfg ) {
			return new \WP_Error( 'pb_package', __( 'Pacchetto non valido.', 'photo-booking' ) );
		}
		if ( ! $cfg['tiers'] ) {
			return array(
				'total'  => $cfg['price'],
				'tier'   => null,
				'extras' => array(),
			);
		}
		$tier = null;
		foreach ( $cfg['tiers'] as $t ) {
			if ( $t['key'] === $tier_key ) {
				$tier = $t;
			}
		}
		if ( ! $tier ) {
			return new \WP_Error( 'pb_tier', __( 'Scegli un pacchetto.', 'photo-booking' ) );
		}
		$extras = array();
		$total  = (float) $tier['price'];
		foreach ( $tier['extras'] as $e ) {
			if ( in_array( $e['key'], $extra_keys, true ) ) {
				$extras[] = $e;
				$total   += (float) $e['price'];
			}
		}
		return array(
			'total'  => round( $total, 2 ),
			'tier'   => $tier,
			'extras' => $extras,
		);
	}

	/**
	 * Published packages, optionally filtered by product category slug.
	 *
	 * @return \WC_Product[]
	 */
	public static function all( $category = '' ) {
		$args = array(
			'type'    => self::TYPE,
			'status'  => 'publish',
			'limit'   => -1,
			'orderby' => array(
				'menu_order' => 'ASC',
				'price'      => 'ASC',
			),
		);
		if ( $category ) {
			$args['category'] = array_map( 'trim', explode( ',', $category ) );
		}
		return wc_get_products( $args );
	}

	/** Remaining places for a package with limited capacity (null = unlimited). */
	public static function remaining_places( $product_id ) {
		$cfg = self::config( $product_id );
		if ( empty( $cfg['capacity'] ) ) {
			return null;
		}
		global $wpdb;
		$t      = Install::table( 'appointments' );
		$active = Appointment::active_statuses();
		$ph     = implode( ',', array_fill( 0, count( $active ), '%s' ) );
		$now    = Time::now_db();
		// Expired holds don't count.
		$sql   = "SELECT COUNT(*) FROM {$t} WHERE product_id = %d AND status IN ($ph) AND NOT (status = %s AND hold_expires_at IS NOT NULL AND hold_expires_at < %s)";
		$used  = (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( (int) $product_id ), $active, array( Appointment::PENDING_PAYMENT, $now ) ) ) ); // phpcs:ignore
		return max( 0, $cfg['capacity'] - $used );
	}

	/* ------------------------------------------------------------------ */
	/* Admin                                                              */
	/* ------------------------------------------------------------------ */

	public static function tabs( $tabs ) {
		$tabs['pb_package'] = array(
			'label'    => __( 'Pacchetto fotografico', 'photo-booking' ),
			'target'   => 'pb_package_data',
			'class'    => array( 'show_if_' . self::TYPE ),
			'priority' => 15,
		);
		foreach ( array( 'shipping', 'linked_product', 'attribute' ) as $hide ) {
			if ( isset( $tabs[ $hide ] ) ) {
				$tabs[ $hide ]['class'][] = 'hide_if_' . self::TYPE;
			}
		}
		return $tabs;
	}

	public static function panel() {
		global $post;
		$product = wc_get_product( $post->ID );
		$windows = $product ? $product->get_meta( '_pb_windows' ) : array();
		$windows = is_array( $windows ) ? $windows : array();
		echo '<div id="pb_package_data" class="panel woocommerce_options_panel hidden">';
		echo '<div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'                => '_pb_deposit',
				'label'             => __( 'Acconto', 'photo-booking' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'description'       => __( 'Importo minimo da pagare online alla prenotazione. Vuoto = intero importo obbligatorio.', 'photo-booking' ),
				'desc_tip'          => true,
				'data_type'         => 'price',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_pb_duration',
				'label'             => __( 'Durata sessione (minuti)', 'photo-booking' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 5, 'step' => 5 ),
				'value'             => $product ? ( $product->get_meta( '_pb_duration' ) ?: 30 ) : 30,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_pb_buffer',
				'label'             => __( 'Pausa dopo la sessione (minuti)', 'photo-booking' ),
				'description'       => __( 'Tempo tecnico prima del cliente successivo.', 'photo-booking' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0, 'step' => 5 ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_pb_capacity',
				'label'             => __( 'Posti massimi', 'photo-booking' ),
				'description'       => __( 'Numero massimo di prenotazioni per questo pacchetto. Vuoto = illimitati.', 'photo-booking' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
			)
		);
		echo '</div><div class="options_group">';
		woocommerce_wp_textarea_input(
			array(
				'id'          => '_pb_includes',
				'label'       => __( 'Cosa include', 'photo-booking' ),
				'description' => __( 'Una voce per riga. Mostrato nella scelta del pacchetto.', 'photo-booking' ),
				'desc_tip'    => true,
				'rows'        => 4,
			)
		);
		woocommerce_wp_textarea_input(
			array(
				'id'          => '_pb_instructions',
				'label'       => __( 'Indicazioni per il cliente', 'photo-booking' ),
				'description' => __( 'Inviate nella conferma e visibili nell\'area privata (es. cosa portare, abiti, orari di arrivo).', 'photo-booking' ),
				'desc_tip'    => true,
				'rows'        => 4,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_pb_label',
				'label'       => __( 'Etichetta nella griglia', 'photo-booking' ),
				'description' => __( 'Piccola scritta sopra il nome nella pagina Prenota (es. Servizio, Sessioni di Natale). Vuoto = Servizio.', 'photo-booking' ),
				'desc_tip'    => true,
			)
		);
		echo '</div>';
		self::tiers_panel( $product ? self::tiers( $product ) : array() );
		echo '<div class="options_group pb-windows">';
		echo '<p class="form-field"><label>' . esc_html__( 'Date dedicate', 'photo-booking' ) . '</label><span class="description">' . esc_html__( 'Se compilate, il pacchetto è prenotabile SOLO in queste date e fasce (es. Natale). Se vuote valgono gli orari generali dello studio. Fasce nel formato 09:30-13:00, 14:30-18:00.', 'photo-booking' ) . '</span></p>';
		echo '<table class="widefat pb-windows-table" style="margin:0 12px 12px;width:calc(100% - 24px)"><thead><tr><th>' . esc_html__( 'Data', 'photo-booking' ) . '</th><th>' . esc_html__( 'Fasce orarie', 'photo-booking' ) . '</th><th></th></tr></thead><tbody>';
		$rows = $windows ? $windows : array();
		foreach ( $rows as $w ) {
			self::window_row( $w['date'] ?? '', $w['ranges'] ?? '' );
		}
		echo '</tbody></table>';
		echo '<script type="text/template" id="tmpl-pb-window-row">';
		self::window_row( '', '' );
		echo '</script>';
		echo '<p style="margin:0 12px 12px"><button type="button" class="button pb-add-window">' . esc_html__( '+ Aggiungi data', 'photo-booking' ) . '</button></p>';
		echo '</div>';
		wp_nonce_field( 'pb_package_save', 'pb_package_nonce' );
		echo '</div>';
	}

	/** Sub-packages + extras repeater. */
	private static function tiers_panel( array $tiers ) {
		echo '<div class="options_group pb-tiers-group">';
		echo '<p class="form-field"><label>' . esc_html__( 'Sotto-pacchetti', 'photo-booking' ) . '</label><span class="description">' . esc_html__( 'Il cliente sceglie uno di questi dopo aver scelto il servizio. Ognuno ha il suo prezzo e i suoi extra facoltativi. Se non ne aggiungi nessuno vale il prezzo del prodotto.', 'photo-booking' ) . '</span></p>';
		echo '<div class="pb-tiers">';
		foreach ( $tiers as $i => $t ) {
			self::tier_box( $i, $t );
		}
		echo '</div>';
		echo '<script type="text/template" id="tmpl-pb-tier">';
		self::tier_box( '__i__', array() );
		echo '</script><script type="text/template" id="tmpl-pb-extra">';
		self::extra_row( '__i__', '__j__', array() );
		echo '</script>';
		echo '<p style="margin:0 12px 12px"><button type="button" class="button pb-add-tier">' . esc_html__( '+ Aggiungi sotto-pacchetto', 'photo-booking' ) . '</button></p>';
		echo '</div>';
	}

	private static function tier_box( $i, array $t ) {
		$t    = wp_parse_args( $t, array( 'key' => '', 'name' => '', 'price' => '', 'description' => '', 'includes' => '', 'extras' => array() ) );
		$base = '_pb_tiers[' . $i . ']';
		?>
		<div class="pb-tier" data-i="<?php echo esc_attr( $i ); ?>" style="margin:0 12px 12px;padding:12px;border:1px solid #dcdcde;background:#fafafa">
			<input type="hidden" name="<?php echo esc_attr( $base ); ?>[key]" value="<?php echo esc_attr( $t['key'] ); ?>">
			<p style="display:flex;gap:8px;margin:0 0 8px">
				<input type="text" style="flex:2" name="<?php echo esc_attr( $base ); ?>[name]" placeholder="<?php esc_attr_e( 'Nome (es. Standard)', 'photo-booking' ); ?>" value="<?php echo esc_attr( $t['name'] ); ?>">
				<input type="text" class="wc_input_price" style="flex:1" name="<?php echo esc_attr( $base ); ?>[price]" placeholder="<?php echo esc_attr( __( 'Prezzo', 'photo-booking' ) . ' (' . get_woocommerce_currency_symbol() . ')' ); ?>" value="<?php echo esc_attr( '' === $t['price'] ? '' : wc_format_localized_price( $t['price'] ) ); ?>">
				<button type="button" class="button-link-delete pb-del-tier" title="<?php esc_attr_e( 'Elimina sotto-pacchetto', 'photo-booking' ); ?>">&times;</button>
			</p>
			<p style="margin:0 0 8px"><textarea style="width:100%" rows="2" name="<?php echo esc_attr( $base ); ?>[description]" placeholder="<?php esc_attr_e( 'Descrizione breve', 'photo-booking' ); ?>"><?php echo esc_textarea( $t['description'] ); ?></textarea></p>
			<p style="margin:0 0 8px"><textarea style="width:100%" rows="3" name="<?php echo esc_attr( $base ); ?>[includes]" placeholder="<?php esc_attr_e( 'Cosa include, una voce per riga', 'photo-booking' ); ?>"><?php echo esc_textarea( $t['includes'] ); ?></textarea></p>
			<strong><?php esc_html_e( 'Extra', 'photo-booking' ); ?></strong>
			<div class="pb-extras" data-next="<?php echo esc_attr( count( $t['extras'] ) ); ?>">
				<?php foreach ( $t['extras'] as $j => $e ) : ?>
					<?php self::extra_row( $i, $j, $e ); ?>
				<?php endforeach; ?>
			</div>
			<p style="margin:6px 0 0"><button type="button" class="button button-small pb-add-extra"><?php esc_html_e( '+ Aggiungi extra', 'photo-booking' ); ?></button></p>
		</div>
		<?php
	}

	private static function extra_row( $i, $j, array $e ) {
		$e    = wp_parse_args( $e, array( 'key' => '', 'name' => '', 'price' => '' ) );
		$base = '_pb_tiers[' . $i . '][extras][' . $j . ']';
		?>
		<p class="pb-extra" style="display:flex;gap:8px;margin:6px 0 0">
			<input type="hidden" name="<?php echo esc_attr( $base ); ?>[key]" value="<?php echo esc_attr( $e['key'] ); ?>">
			<input type="text" style="flex:2" name="<?php echo esc_attr( $base ); ?>[name]" placeholder="<?php esc_attr_e( 'Extra (es. 5 foto in più)', 'photo-booking' ); ?>" value="<?php echo esc_attr( $e['name'] ); ?>">
			<input type="text" class="wc_input_price" style="flex:1" name="<?php echo esc_attr( $base ); ?>[price]" placeholder="<?php echo esc_attr( __( 'Prezzo', 'photo-booking' ) . ' (' . get_woocommerce_currency_symbol() . ')' ); ?>" value="<?php echo esc_attr( '' === $e['price'] ? '' : wc_format_localized_price( $e['price'] ) ); ?>">
			<button type="button" class="button-link-delete pb-del-extra">&times;</button>
		</p>
		<?php
	}

	/** Sanitize the posted sub-packages; keys stay stable so existing bookings keep their reference. */
	private static function sanitize_tiers( $raw ) {
		$tiers = array();
		$used  = array();
		foreach ( (array) $raw as $t ) {
			$name = sanitize_text_field( wp_unslash( $t['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$key = sanitize_key( wp_unslash( $t['key'] ?? '' ) );
			$key = $key && ! isset( $used[ $key ] ) ? $key : self::unique_key( $name, $used );
			$used[ $key ] = true;
			$extras       = array();
			$used_e       = array();
			foreach ( (array) ( $t['extras'] ?? array() ) as $e ) {
				$ename = sanitize_text_field( wp_unslash( $e['name'] ?? '' ) );
				if ( '' === $ename ) {
					continue;
				}
				$ekey            = sanitize_key( wp_unslash( $e['key'] ?? '' ) );
				$ekey            = $ekey && ! isset( $used_e[ $ekey ] ) ? $ekey : self::unique_key( $ename, $used_e );
				$used_e[ $ekey ] = true;
				$extras[]        = array(
					'key'   => $ekey,
					'name'  => $ename,
					'price' => (float) wc_format_decimal( wp_unslash( $e['price'] ?? 0 ) ),
				);
			}
			$tiers[] = array(
				'key'         => $key,
				'name'        => $name,
				'price'       => (float) wc_format_decimal( wp_unslash( $t['price'] ?? 0 ) ),
				'description' => sanitize_textarea_field( wp_unslash( $t['description'] ?? '' ) ),
				'includes'    => sanitize_textarea_field( wp_unslash( $t['includes'] ?? '' ) ),
				'extras'      => $extras,
			);
		}
		return $tiers;
	}

	private static function unique_key( $name, array $used ) {
		$base = sanitize_key( sanitize_title( $name ) ) ?: 'item';
		$key  = $base;
		for ( $n = 2; isset( $used[ $key ] ); $n++ ) {
			$key = $base . '-' . $n;
		}
		return $key;
	}

	private static function window_row( $date, $ranges ) {
		echo '<tr><td><input type="date" name="_pb_windows_date[]" value="' . esc_attr( $date ) . '"></td><td><input type="text" style="width:100%" name="_pb_windows_ranges[]" placeholder="09:30-18:00" value="' . esc_attr( $ranges ) . '"></td><td><button type="button" class="button-link-delete pb-del-window">&times;</button></td></tr>';
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['pb_package_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pb_package_nonce'] ), 'pb_package_save' ) ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$product->update_meta_data( '_pb_deposit', isset( $_POST['_pb_deposit'] ) && '' !== $_POST['_pb_deposit'] ? wc_format_decimal( wp_unslash( $_POST['_pb_deposit'] ) ) : '' );
		$product->update_meta_data( '_pb_duration', max( 5, absint( $_POST['_pb_duration'] ?? 30 ) ) );
		$product->update_meta_data( '_pb_buffer', absint( $_POST['_pb_buffer'] ?? 0 ) );
		$product->update_meta_data( '_pb_capacity', isset( $_POST['_pb_capacity'] ) && '' !== $_POST['_pb_capacity'] ? absint( $_POST['_pb_capacity'] ) : '' );
		$product->update_meta_data( '_pb_includes', sanitize_textarea_field( wp_unslash( $_POST['_pb_includes'] ?? '' ) ) );
		$product->update_meta_data( '_pb_instructions', wp_kses_post( wp_unslash( $_POST['_pb_instructions'] ?? '' ) ) );
		$product->update_meta_data( '_pb_label', sanitize_text_field( wp_unslash( $_POST['_pb_label'] ?? '' ) ) );
		$product->update_meta_data( '_pb_tiers', self::sanitize_tiers( $_POST['_pb_tiers'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$dates   = (array) ( $_POST['_pb_windows_date'] ?? array() );
		$ranges  = (array) ( $_POST['_pb_windows_ranges'] ?? array() );
		$windows = array();
		foreach ( $dates as $i => $d ) {
			$d = sanitize_text_field( wp_unslash( $d ) );
			$r = sanitize_text_field( wp_unslash( $ranges[ $i ] ?? '' ) );
			if ( Time::is_valid_date( $d ) && Availability::parse_ranges( $r ) ) {
				$windows[] = array(
					'date'   => $d,
					'ranges' => $r,
				);
			}
		}
		usort(
			$windows,
			static function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);
		$product->update_meta_data( '_pb_windows', $windows );
		// phpcs:enable
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->save();
	}

	public static function admin_js() {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		?>
		<script>
		jQuery(function($){
			// Show price fields and general tab for the package type.
			$('.options_group.pricing').addClass('show_if_<?php echo esc_js( self::TYPE ); ?>');
			$('#general_product_data ._tax_status_field').closest('.options_group').addClass('show_if_<?php echo esc_js( self::TYPE ); ?>');
			$('input#_virtual, input#_downloadable').closest('label').addClass('hide_if_<?php echo esc_js( self::TYPE ); ?>');
			$('body').on('woocommerce-product-type-change', function(){});
			$('select#product-type').trigger('change');
			$(document).on('click', '.pb-add-window', function(){
				$('.pb-windows-table tbody').append($('#tmpl-pb-window-row').html());
			});
			$(document).on('click', '.pb-del-window', function(){ $(this).closest('tr').remove(); });
			var tierSeq = Date.now();
			$(document).on('click', '.pb-add-tier', function(){
				$('.pb-tiers').append($('#tmpl-pb-tier').html().split('__i__').join('n' + (tierSeq++)));
			});
			$(document).on('click', '.pb-del-tier', function(){
				if (confirm('<?php echo esc_js( __( 'Eliminare questo sotto-pacchetto e i suoi extra?', 'photo-booking' ) ); ?>')) { $(this).closest('.pb-tier').remove(); }
			});
			$(document).on('click', '.pb-add-extra', function(){
				var tier = $(this).closest('.pb-tier'), box = tier.find('.pb-extras'), j = 'n' + (tierSeq++);
				box.append($('#tmpl-pb-extra').html().split('__i__').join(tier.data('i')).split('__j__').join(j));
			});
			$(document).on('click', '.pb-del-extra', function(){ $(this).closest('.pb-extra').remove(); });
		});
		</script>
		<?php
	}

	/** Single product page: replace add-to-cart with a "Prenota" link. */
	public static function single_add_to_cart() {
		global $product;
		$cfg = self::config( $product );
		echo '<p class="pb-single-cta"><a class="button alt" href="' . esc_url( Settings::booking_page_url( array( 'pacchetto' => $product->get_id() ) ) ) . '">' . esc_html__( 'Prenota questa sessione', 'photo-booking' ) . '</a></p>';
		if ( $cfg['deposit'] < $cfg['price'] ) {
			/* translators: %s deposit amount */
			echo '<p class="pb-single-deposit">' . wp_kses_post( sprintf( __( 'Prenoti con un acconto di %s.', 'photo-booking' ), wc_price( $cfg['deposit'] ) ) ) . '</p>';
		}
	}
}
