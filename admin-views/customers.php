<?php
/**
 * @var array  $rows
 * @var int    $total
 * @var string $search
 * @var int    $paged
 * @var int    $per
 */

use PhotoBooking\Admin\Admin;
use PhotoBooking\Time;

defined( 'ABSPATH' ) || exit;

$base = admin_url( 'admin.php?page=pb-customers' );
?>
<div class="wrap pb-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Clienti', 'photo-booking' ); ?></h1>
	<a href="#pb-new-customer" class="page-title-action"><?php esc_html_e( 'Nuovo cliente', 'photo-booking' ); ?></a>
	<hr class="wp-header-end">

	<div class="pb-detail">
		<div class="pb-detail-main">
			<form method="get" class="pb-list-filters">
				<input type="hidden" name="page" value="pb-customers">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Nome, email o telefono', 'photo-booking' ); ?>">
				<button class="button"><?php esc_html_e( 'Cerca', 'photo-booking' ); ?></button>
				<?php if ( $search ) : ?><a class="button-link" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Tutti', 'photo-booking' ); ?></a><?php endif; ?>
				<span class="pb-muted"><?php echo esc_html( sprintf( _n( '%d cliente', '%d clienti', $total, 'photo-booking' ), $total ) ); ?></span>
			</form>

			<table class="widefat striped pb-table">
				<thead><tr>
					<th><?php esc_html_e( 'Cliente', 'photo-booking' ); ?></th>
					<th><?php esc_html_e( 'Contatti', 'photo-booking' ); ?></th>
					<th class="num"><?php esc_html_e( 'Prenotazioni', 'photo-booking' ); ?></th>
					<th><?php esc_html_e( 'Ultima sessione', 'photo-booking' ); ?></th>
					<th class="num"><?php esc_html_e( 'Pagato', 'photo-booking' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Nessun cliente.', 'photo-booking' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $c ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $c['name'] ); ?></strong><br><span class="pb-muted"><?php echo esc_html( sprintf( __( 'dal %s', 'photo-booking' ), wp_date( 'j M Y', strtotime( $c['registered'] ) ) ) ); ?></span></td>
						<td><a href="mailto:<?php echo esc_attr( $c['email'] ); ?>"><?php echo esc_html( $c['email'] ); ?></a><br><span class="pb-muted"><?php echo esc_html( $c['phone'] ); ?></span></td>
						<td class="num"><?php if ( $c['bookings'] ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=pb-list&when=all&s=' . rawurlencode( $c['email'] ) ) ); ?>"><?php echo (int) $c['bookings']; ?></a><?php else : ?><span class="pb-muted">0</span><?php endif; ?></td>
						<td><?php echo $c['last'] ? esc_html( ucfirst( Time::format_date( $c['last'] ) ) ) : '<span class="pb-muted">—</span>'; ?></td>
						<td class="num"><?php echo wp_kses_post( wc_price( $c['paid'] ) ); ?></td>
						<td class="pb-actions-cell">
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=pb-new&customer=' . $c['id'] ) ); ?>"><?php esc_html_e( 'Nuovo appuntamento', 'photo-booking' ); ?></a>
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=pb-deliveries&customer=' . $c['id'] ) ); ?>"><?php esc_html_e( 'Consegne', 'photo-booking' ); ?></a>
							<a class="button-link" href="<?php echo esc_url( get_edit_user_link( $c['id'] ) ); ?>"><?php esc_html_e( 'Modifica', 'photo-booking' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div class="tablenav"><div class="tablenav-pages">
				<?php if ( $paged > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>">‹ <?php esc_html_e( 'Precedenti', 'photo-booking' ); ?></a><?php endif; ?>
				<?php if ( $paged * $per < $total ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>"><?php esc_html_e( 'Successivi', 'photo-booking' ); ?> ›</a><?php endif; ?>
			</div></div>
		</div>

		<div class="pb-detail-side">
			<div class="pb-box" id="pb-new-customer">
				<h2><?php esc_html_e( 'Nuovo cliente', 'photo-booking' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Crea la scheda del cliente senza fissare un appuntamento. L\'appuntamento si aggiunge quando serve, da "Nuovo appuntamento".', 'photo-booking' ); ?></p>
				<?php Admin::form_open( 'new_customer' ); ?>
					<p><label><?php esc_html_e( 'Nome', 'photo-booking' ); ?><input type="text" name="first_name" class="widefat" required></label></p>
					<p><label><?php esc_html_e( 'Cognome', 'photo-booking' ); ?><input type="text" name="last_name" class="widefat"></label></p>
					<p><label><?php esc_html_e( 'Email', 'photo-booking' ); ?><input type="email" name="email" class="widefat" required></label></p>
					<p><label><?php esc_html_e( 'Telefono', 'photo-booking' ); ?><input type="text" name="phone" class="widefat"></label></p>
					<p><label><?php esc_html_e( 'Note interne', 'photo-booking' ); ?><textarea name="notes" rows="2" class="widefat"></textarea></label></p>
					<p><label><input type="checkbox" name="welcome" value="1" checked> <?php esc_html_e( 'Invia email di benvenuto con il link per scegliere la password', 'photo-booking' ); ?></label></p>
					<button class="button button-primary"><?php esc_html_e( 'Crea cliente', 'photo-booking' ); ?></button>
				</form>
			</div>
		</div>
	</div>
</div>
