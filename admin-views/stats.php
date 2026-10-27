<?php
/**
 * @var string        $from
 * @var string        $to
 * @var int           $product
 * @var string        $tab
 * @var array         $stats
 * @var \WC_Product[] $packages
 */

use PhotoBooking\Time;

defined( 'ABSPATH' ) || exit;

$year  = Time::now()->format( 'Y' );
$month = Time::now()->format( 'Y-m' );
$quick = array(
	__( 'Questo mese', 'photo-booking' )  => array( 'from' => $month . '-01', 'to' => Time::now()->format( 'Y-m-t' ) ),
	__( 'Quest\'anno', 'photo-booking' )  => array( 'from' => $year . '-01-01', 'to' => $year . '-12-31' ),
	__( 'Anno scorso', 'photo-booking' )  => array( 'from' => ( $year - 1 ) . '-01-01', 'to' => ( $year - 1 ) . '-12-31' ),
	__( 'Da sempre', 'photo-booking' )    => array( 'from' => '2000-01-01', 'to' => ( $year + 5 ) . '-12-31' ),
);
$t = $stats['totals'];
?>
<div class="wrap pb-wrap">
	<h1><?php esc_html_e( 'Statistiche', 'photo-booking' ); ?></h1>

	<form method="get" class="pb-list-filters">
		<input type="hidden" name="page" value="pb-stats">
		<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
		<label><?php esc_html_e( 'Dal', 'photo-booking' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>"></label>
		<label><?php esc_html_e( 'al', 'photo-booking' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>"></label>
		<select name="product_id">
			<option value="0"><?php esc_html_e( 'Tutti i pacchetti', 'photo-booking' ); ?></option>
			<?php foreach ( $packages as $p ) : ?>
				<option value="<?php echo esc_attr( $p->get_id() ); ?>" <?php selected( $product, $p->get_id() ); ?>><?php echo esc_html( $p->get_name() ); ?></option>
			<?php endforeach; ?>
		</select>
		<button class="button"><?php esc_html_e( 'Aggiorna', 'photo-booking' ); ?></button>
		<span class="pb-muted">
			<?php foreach ( $quick as $label => $q ) : ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'pb-stats', 'tab' => $tab, 'from' => $q['from'], 'to' => $q['to'], 'product_id' => $product ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a> ·
			<?php endforeach; ?>
		</span>
	</form>

	<h2 class="nav-tab-wrapper pb-stats-tabs">
		<a class="nav-tab <?php echo 'riepilogo' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'pb-stats', 'tab' => 'riepilogo', 'from' => $from, 'to' => $to, 'product_id' => $product ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Riepilogo', 'photo-booking' ); ?></a>
		<a class="nav-tab <?php echo 'pacchetti' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'pb-stats', 'tab' => 'pacchetti', 'from' => $from, 'to' => $to, 'product_id' => $product ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Per pacchetto', 'photo-booking' ); ?></a>
	</h2>

	<?php if ( 'riepilogo' === $tab ) : ?>
	<?php
	$collected = $t['deposits'] + $t['balances'];
	$pkg_label = $product ? sprintf( __( 'prenotazioni di %s', 'photo-booking' ), wp_strip_all_tags( $stats['packages'] ? reset( $stats['packages'] )['name'] : '' ) ) : __( 'pacchetti prenotati', 'photo-booking' );
	$period    = Time::format_date( $from . ' 00:00:00' ) . ' – ' . Time::format_date( $to . ' 00:00:00' );
	?>
	<?php
	// Donut: bookings by payment situation. Bar: money collected vs outstanding.
	$donut = static function ( array $slices, $total ) {
		$r = 54; $c = 2 * M_PI * $r; $offset = 0; $out = '';
		foreach ( $slices as $sl ) {
			if ( $sl['v'] <= 0 ) { continue; }
			$len  = $c * $sl['v'] / max( 1, $total );
			$out .= '<circle r="' . $r . '" cx="70" cy="70" fill="none" stroke="' . esc_attr( $sl['color'] ) . '" stroke-width="22" stroke-dasharray="' . esc_attr( round( $len, 2 ) . ' ' . round( $c - $len, 2 ) ) . '" stroke-dashoffset="' . esc_attr( round( -$offset, 2 ) ) . '" transform="rotate(-90 70 70)"><title>' . esc_html( $sl['label'] . ': ' . $sl['v'] ) . '</title></circle>';
			$offset += $len;
		}
		return '<svg class="pb-donut" viewBox="0 0 140 140" width="140" height="140" role="img" aria-label="' . esc_attr__( 'Prenotazioni per stato del pagamento', 'photo-booking' ) . '">' . ( $out ? $out : '<circle r="54" cx="70" cy="70" fill="none" stroke="#e5e5e5" stroke-width="22"/>' ) . '<text x="70" y="66" text-anchor="middle" font-size="30" font-weight="600" fill="#1d2327">' . (int) $total . '</text><text x="70" y="86" text-anchor="middle" font-size="11" fill="#646970">' . esc_html__( 'prenotazioni', 'photo-booking' ) . '</text></svg>';
	};
	$slices = array(
		array( 'label' => __( 'Hanno saldato tutto', 'photo-booking' ), 'v' => $t['paid_count'], 'color' => '#1e8a4c' ),
		array( 'label' => __( 'Hanno dato l\'acconto', 'photo-booking' ), 'v' => $t['deposit_count'], 'color' => '#d9a400' ),
		array( 'label' => __( 'Non hanno pagato nulla', 'photo-booking' ), 'v' => $t['unpaid_count'], 'color' => '#c0392b' ),
	);
	$collected = $t['deposits'] + $t['balances'];
	$value     = max( 0.01, (float) $t['value'] );
	$bars      = array(
		array( 'label' => __( 'Acconti incassati', 'photo-booking' ), 'v' => $t['deposits'], 'color' => '#1e8a4c' ),
		array( 'label' => __( 'Saldi incassati', 'photo-booking' ), 'v' => $t['balances'], 'color' => '#64b57d' ),
		array( 'label' => __( 'Ancora da riscuotere', 'photo-booking' ), 'v' => $t['outstanding'], 'color' => '#c0392b' ),
	);
	$period = Time::format_date( $from . ' 00:00:00' ) . ' – ' . Time::format_date( $to . ' 00:00:00' );
	?>
	<p class="pb-summary-period"><?php echo esc_html( sprintf( __( 'Sessioni dal %s', 'photo-booking' ), $period ) ); ?><?php echo $product && $stats['packages'] ? esc_html( ' · ' . reset( $stats['packages'] )['name'] ) : ''; ?></p>
	<?php if ( ! $t['bookings'] ) : ?>
		<div class="pb-box pb-summary-box"><p class="pb-summary-line"><?php esc_html_e( 'Nessuna prenotazione nel periodo scelto.', 'photo-booking' ); ?></p></div>
	<?php else : ?>
	<div class="pb-summary-grid">
		<div class="pb-box pb-summary-box">
			<h2><?php esc_html_e( 'Prenotazioni', 'photo-booking' ); ?></h2>
			<div class="pb-summary-chart">
				<?php echo $donut( $slices, $t['bookings'] ); // phpcs:ignore ?>
				<ul class="pb-legend-list">
					<?php foreach ( $slices as $sl ) : ?>
						<li><span class="pb-swatch" style="background:<?php echo esc_attr( $sl['color'] ); ?>"></span><strong><?php echo (int) $sl['v']; ?></strong> <?php echo esc_html( $sl['label'] ); ?><?php if ( $t['bookings'] ) : ?> <em>(<?php echo esc_html( round( 100 * $sl['v'] / $t['bookings'] ) ); ?>%)</em><?php endif; ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<p class="pb-summary-small"><?php echo esc_html( sprintf( _n( '%d sessione già svolta.', '%d sessioni già svolte.', $t['completed'], 'photo-booking' ), $t['completed'] ) ); ?></p>
		</div>

		<div class="pb-box pb-summary-box">
			<h2><?php esc_html_e( 'Soldi', 'photo-booking' ); ?></h2>
			<div class="pb-money-big">
				<div><span><?php esc_html_e( 'Incassato', 'photo-booking' ); ?></span><strong class="pb-ok"><?php echo esc_html( wp_strip_all_tags( wc_price( $collected ) ) ); ?></strong></div>
				<div><span><?php esc_html_e( 'Da riscuotere', 'photo-booking' ); ?></span><strong class="pb-due"><?php echo esc_html( wp_strip_all_tags( wc_price( $t['outstanding'] ) ) ); ?></strong></div>
				<div><span><?php esc_html_e( 'Valore totale', 'photo-booking' ); ?></span><strong><?php echo esc_html( wp_strip_all_tags( wc_price( $t['value'] ) ) ); ?></strong></div>
			</div>
			<div class="pb-bar" role="img" aria-label="<?php esc_attr_e( 'Incassato e da riscuotere sul valore totale', 'photo-booking' ); ?>">
				<?php foreach ( $bars as $bar ) : ?>
					<?php if ( $bar['v'] > 0 ) : ?><span style="width:<?php echo esc_attr( round( 100 * $bar['v'] / $value, 2 ) ); ?>%;background:<?php echo esc_attr( $bar['color'] ); ?>" title="<?php echo esc_attr( $bar['label'] . ': ' . wp_strip_all_tags( wc_price( $bar['v'] ) ) ); ?>"></span><?php endif; ?>
				<?php endforeach; ?>
			</div>
			<ul class="pb-legend-list pb-legend-row">
				<?php foreach ( $bars as $bar ) : ?>
					<li><span class="pb-swatch" style="background:<?php echo esc_attr( $bar['color'] ); ?>"></span><?php echo esc_html( $bar['label'] ); ?> <strong><?php echo wp_kses_post( wc_price( $bar['v'] ) ); ?></strong> <em>(<?php echo esc_html( round( 100 * $bar['v'] / $value ) ); ?>%)</em></li>
				<?php endforeach; ?>
			</ul>
			<p class="pb-summary-small"><?php echo esc_html( sprintf( __( 'Incassato %1$d%% del valore delle prenotazioni.', 'photo-booking' ), round( 100 * $collected / $value ) ) ); ?>
			<?php if ( $t['outstanding'] > 0 ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=pb-list&status=balance&when=all' ) ); ?>"><?php esc_html_e( 'Vedi chi deve ancora pagare →', 'photo-booking' ); ?></a><?php endif; ?></p>
		</div>
	</div>
	<?php endif; ?>
	<?php endif; ?>

	<?php if ( 'pacchetti' === $tab ) : ?>
	<div class="pb-kpis">
		<div class="pb-kpi"><span><?php esc_html_e( 'Prenotazioni', 'photo-booking' ); ?></span><strong><?php echo (int) $t['bookings']; ?></strong><small><?php echo esc_html( sprintf( __( 'di cui %d svolte', 'photo-booking' ), $t['completed'] ) ); ?></small></div>
		<div class="pb-kpi"><span><?php esc_html_e( 'Valore totale', 'photo-booking' ); ?></span><strong><?php echo wp_kses_post( wc_price( $t['value'] ) ); ?></strong><small><?php esc_html_e( 'somma dei prezzi delle prenotazioni', 'photo-booking' ); ?></small></div>
		<div class="pb-kpi"><span><?php esc_html_e( 'Acconti incassati', 'photo-booking' ); ?></span><strong><?php echo wp_kses_post( wc_price( $t['deposits'] ) ); ?></strong><small><?php esc_html_e( 'online o in studio', 'photo-booking' ); ?></small></div>
		<div class="pb-kpi"><span><?php esc_html_e( 'Saldi incassati', 'photo-booking' ); ?></span><strong><?php echo wp_kses_post( wc_price( $t['balances'] ) ); ?></strong><small><?php esc_html_e( 'oltre l\'acconto', 'photo-booking' ); ?></small></div>
		<div class="pb-kpi pb-kpi-due"><span><?php esc_html_e( 'Da riscuotere', 'photo-booking' ); ?></span><strong><?php echo wp_kses_post( wc_price( $t['outstanding'] ) ); ?></strong><small><?php esc_html_e( 'acconti e saldi ancora dovuti', 'photo-booking' ); ?></small></div>
	</div>

	<table class="widefat striped pb-table pb-stats-table">
		<thead><tr>
			<th><?php esc_html_e( 'Pacchetto', 'photo-booking' ); ?></th>
			<th class="num"><?php esc_html_e( 'Prenotazioni', 'photo-booking' ); ?></th>
			<th class="num"><?php esc_html_e( 'Svolte', 'photo-booking' ); ?></th>
			<th class="num"><?php esc_html_e( 'Valore', 'photo-booking' ); ?></th>
			<th class="num"><?php esc_html_e( 'Acconti', 'photo-booking' ); ?></th>
			<th class="num"><?php esc_html_e( 'Saldi', 'photo-booking' ); ?></th>
			<th class="num"><?php esc_html_e( 'Da riscuotere', 'photo-booking' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( ! $stats['packages'] ) : ?>
			<tr><td colspan="7"><?php esc_html_e( 'Nessuna prenotazione nel periodo.', 'photo-booking' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $stats['packages'] as $pid => $r ) : ?>
			<tr>
				<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'pb-stats', 'from' => $from, 'to' => $to, 'product_id' => $pid ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $r['name'] ); ?></a></td>
				<td class="num"><strong><?php echo (int) $r['bookings']; ?></strong></td>
				<td class="num"><?php echo (int) $r['completed']; ?></td>
				<td class="num"><?php echo wp_kses_post( wc_price( $r['value'] ) ); ?></td>
				<td class="num"><?php echo wp_kses_post( wc_price( $r['deposits'] ) ); ?></td>
				<td class="num"><?php echo wp_kses_post( wc_price( $r['balances'] ) ); ?></td>
				<td class="num"><?php echo $r['outstanding'] > 0 ? '<span class="pb-due">' . wp_kses_post( wc_price( $r['outstanding'] ) ) . '</span>' : '—'; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
		<tfoot><tr>
			<th><?php esc_html_e( 'Totale', 'photo-booking' ); ?></th>
			<th class="num"><?php echo (int) $t['bookings']; ?></th>
			<th class="num"><?php echo (int) $t['completed']; ?></th>
			<th class="num"><?php echo wp_kses_post( wc_price( $t['value'] ) ); ?></th>
			<th class="num"><?php echo wp_kses_post( wc_price( $t['deposits'] ) ); ?></th>
			<th class="num"><?php echo wp_kses_post( wc_price( $t['balances'] ) ); ?></th>
			<th class="num"><?php echo wp_kses_post( wc_price( $t['outstanding'] ) ); ?></th>
		</tr></tfoot>
	</table>
	<p class="description">
		<?php echo esc_html( sprintf( __( 'Periodo per data della sessione. Escluse le prenotazioni chiuse: nel periodo ce ne sono %1$d tra rifiutate, annullate e scadute, con %2$s trattenuti come acconto non rimborsabile.', 'photo-booking' ), $stats['closed']['count'], wp_strip_all_tags( wc_price( $stats['closed']['kept'] ) ) ) ); ?>
		<?php esc_html_e( '"Acconti" è la parte pagata fino all\'importo dell\'acconto del pacchetto, "Saldi" tutto il resto, comunque sia stato pagato.', 'photo-booking' ); ?>
	</p>
	<?php endif; ?>
</div>
