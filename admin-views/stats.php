<?php
/**
 * @var string        $from
 * @var string        $to
 * @var int           $product
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
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'pb-stats', 'from' => $q['from'], 'to' => $q['to'], 'product_id' => $product ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a> ·
			<?php endforeach; ?>
		</span>
	</form>

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
</div>
