<?php
/**
 * @var \PhotoBooking\Appointment[] $items
 * @var string $status
 * @var string $search
 * @var string $when
 * @var int    $paged
 * @var bool   $has_more
 */

use PhotoBooking\Admin\Admin;
use PhotoBooking\Appointment;
use PhotoBooking\Time;

defined( 'ABSPATH' ) || exit;

$base    = admin_url( 'admin.php?page=pb-list' );
$filters = array(
	''                         => __( 'Attive', 'photo-booking' ),
	'todo'                     => __( 'Da gestire', 'photo-booking' ) . ' (' . Admin::pending_count() . ')',
	Appointment::CONFIRMED     => __( 'Confermate', 'photo-booking' ),
	Appointment::PROPOSED      => __( 'Proposta inviata', 'photo-booking' ),
	'balance'                  => __( 'Con saldo da incassare', 'photo-booking' ),
	Appointment::COMPLETED     => __( 'Svolte', 'photo-booking' ),
	'archive'                  => __( 'Archivio', 'photo-booking' ),
);
$is_archive = 'archive' === $status;
?>
<div class="wrap pb-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Prenotazioni', 'photo-booking' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=pb-new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Nuovo appuntamento', 'photo-booking' ); ?></a>
	<hr class="wp-header-end">

	<ul class="subsubsub">
		<?php
		$links = array();
		foreach ( $filters as $k => $label ) {
			$links[] = '<li><a href="' . esc_url( add_query_arg( array( 'status' => $k, 'when' => $when ), $base ) ) . '"' . ( $status === $k ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo implode( ' | </li>', $links ) . '</li>'; // phpcs:ignore
		?>
	</ul>

	<?php if ( $is_archive ) : ?>
		<div class="notice notice-info inline pb-archive-note"><p>
			<?php esc_html_e( 'Qui finiscono le prenotazioni rifiutate, annullate e scadute. Non compaiono nel calendario né nelle statistiche; i pagamenti restano negli ordini WooCommerce.', 'photo-booking' ); ?>
			<?php Admin::form_open( 'purge_archive', 'class="pb-inline-form"' ); ?>
				<input type="hidden" name="days" value="30">
				<button class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Eliminare definitivamente tutte le prenotazioni in archivio chiuse da più di 30 giorni?', 'photo-booking' ) ); ?>')"><?php esc_html_e( 'Svuota l\'archivio (più vecchie di 30 giorni)', 'photo-booking' ); ?></button>
			</form>
		</p></div>
	<?php endif; ?>

	<form method="get" class="pb-list-filters">
		<input type="hidden" name="page" value="pb-list">
		<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
		<select name="when">
			<option value="upcoming" <?php selected( $when, 'upcoming' ); ?>><?php esc_html_e( 'Da oggi in poi', 'photo-booking' ); ?></option>
			<option value="past" <?php selected( $when, 'past' ); ?>><?php esc_html_e( 'Passate', 'photo-booking' ); ?></option>
			<option value="all" <?php selected( $when, 'all' ); ?>><?php esc_html_e( 'Tutte le date', 'photo-booking' ); ?></option>
		</select>
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Nome, email, telefono, #numero', 'photo-booking' ); ?>">
		<button class="button"><?php esc_html_e( 'Filtra', 'photo-booking' ); ?></button>
	</form>

	<table class="widefat striped pb-table">
		<thead><tr>
			<th><?php esc_html_e( 'Quando', 'photo-booking' ); ?></th>
			<th><?php esc_html_e( 'Cliente', 'photo-booking' ); ?></th>
			<th><?php esc_html_e( 'Pacchetto', 'photo-booking' ); ?></th>
			<th><?php esc_html_e( 'Stato', 'photo-booking' ); ?></th>
			<th><?php esc_html_e( 'Pagamento', 'photo-booking' ); ?></th>
			<th class="num"><?php esc_html_e( 'Versato / Totale', 'photo-booking' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( ! $items ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'Nessuna prenotazione.', 'photo-booking' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $items as $a ) : ?>
			<tr>
				<td><a href="<?php echo esc_url( $a->admin_url() ); ?>"><strong><?php echo esc_html( wp_date( 'D j M Y', $a->start()->getTimestamp() ) ); ?></strong><br><?php echo esc_html( Time::format_time( $a->start_at ) . '–' . Time::format_time( $a->end_at ) ); ?></a> <span class="pb-muted"><?php echo esc_html( $a->number() ); ?></span></td>
				<td><?php echo esc_html( $a->customer_name ); ?><br><span class="pb-muted"><?php echo esc_html( $a->customer_phone ); ?></span></td>
				<td><?php echo esc_html( $a->package_name() ); ?></td>
				<td><?php echo Admin::badge( $a ); // phpcs:ignore ?></td>
				<td><?php echo esc_html( $a->payment_label() ); ?></td>
				<td class="num"><?php echo wp_kses_post( wc_price( $a->paid ) . ' / ' . wc_price( $a->total ) ); ?><?php if ( $a->balance_due() > 0 && ! $a->is_closed() ) : ?><br><span class="pb-due"><?php echo wp_kses_post( sprintf( __( 'da saldare %s', 'photo-booking' ), wc_price( $a->balance_due() ) ) ); ?></span><?php endif; ?>
					<?php if ( $is_archive ) : ?>
						<?php Admin::form_open( 'delete', 'class="pb-inline-form"' ); ?>
							<input type="hidden" name="id" value="<?php echo esc_attr( $a->id ); ?>">
							<button class="button-link button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Eliminare definitivamente questa prenotazione?', 'photo-booking' ) ); ?>')"><?php esc_html_e( 'Elimina', 'photo-booking' ); ?></button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<div class="tablenav"><div class="tablenav-pages">
		<?php if ( $paged > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>">‹ <?php esc_html_e( 'Precedenti', 'photo-booking' ); ?></a><?php endif; ?>
		<?php if ( $has_more ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>"><?php esc_html_e( 'Successivi', 'photo-booking' ); ?> ›</a><?php endif; ?>
	</div></div>
</div>
