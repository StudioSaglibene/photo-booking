<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Tiny PDF writer (text only, Helvetica, A4) with no external library: enough for the
 * "informazioni per la tua sessione" sheet attached to the confirmation email.
 */
class Pdf {

	const W      = 595.28;
	const H      = 841.89;
	const MARGIN = 56;

	private $pages   = array();
	private $current = '';
	private $y       = 0;

	public function __construct() {
		$this->new_page();
	}

	private function new_page() {
		if ( '' !== $this->current ) {
			$this->pages[] = $this->current;
		}
		$this->current = '';
		$this->y       = self::H - self::MARGIN;
	}

	private static function esc( $text ) {
		$text = (string) iconv( 'UTF-8', 'ISO-8859-1//TRANSLIT', $text );
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $text );
	}

	/** Approximate Helvetica width (average 0.5 em). */
	private static function wrap( $text, $size, $max_width ) {
		$max_chars = max( 10, (int) floor( $max_width / ( $size * 0.5 ) ) );
		$lines     = array();
		foreach ( explode( "\n", $text ) as $para ) {
			$para = trim( $para );
			if ( '' === $para ) {
				$lines[] = '';
				continue;
			}
			$line = '';
			foreach ( explode( ' ', $para ) as $word ) {
				if ( '' !== $line && strlen( $line . ' ' . $word ) > $max_chars ) {
					$lines[] = $line;
					$line    = $word;
				} else {
					$line = '' === $line ? $word : $line . ' ' . $word;
				}
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * @param string $text
	 * @param int    $size
	 * @param bool   $bold
	 * @param float  $after   space after the block (points)
	 */
	public function text( $text, $size = 11, $bold = false, $after = 8, $indent = 0 ) {
		$font    = $bold ? 'F2' : 'F1';
		$leading = $size * 1.45;
		foreach ( self::wrap( $text, $size, self::W - 2 * self::MARGIN - $indent ) as $line ) {
			if ( $this->y - $leading < self::MARGIN ) {
				$this->new_page();
			}
			$this->y -= $leading;
			if ( '' !== $line ) {
				$this->current .= sprintf( "BT /%s %d Tf %.2f %.2f Td (%s) Tj ET\n", $font, $size, self::MARGIN + $indent, $this->y, self::esc( $line ) );
			}
		}
		$this->y -= $after;
	}

	public function rule( $after = 12 ) {
		$this->y -= 4;
		$this->current .= sprintf( "0.8 G %.2f %.2f m %.2f %.2f l S\n", self::MARGIN, $this->y, self::W - self::MARGIN, $this->y );
		$this->y -= $after;
	}

	/** Render to a PDF string. */
	public function output() {
		$pages   = array_merge( $this->pages, array( $this->current ) );
		$objects = array();
		$objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
		$kids      = array();
		$n         = count( $pages );
		for ( $i = 0; $i < $n; $i++ ) {
			$kids[] = ( 5 + 2 * $i ) . ' 0 R';
		}
		$objects[] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $n . ' >>';
		$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		foreach ( $pages as $i => $content ) {
			$objects[] = sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, 6 + 2 * $i );
			$objects[] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
		}
		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $i => $obj ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $i + 1 ) . " 0 obj\n" . $obj . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= 'xref\n0 ' . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		$pdf  = str_replace( 'xref\n', "xref\n", $pdf );
		foreach ( $offsets as $o ) {
			$pdf .= sprintf( "%010d 00000 n \n", $o );
		}
		$pdf .= 'trailer << /Size ' . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
		return $pdf;
	}

	/**
	 * The information sheet for a confirmed appointment. Returns the path of the PDF file (uploads/photo-booking/).
	 */
	public static function info_sheet( Appointment $appt ) {
		$cfg = Package::config( $appt->product_id );
		$s   = Settings::all();
		$pdf = new self();
		$pdf->text( $s['business_name'] ? $s['business_name'] : get_bloginfo( 'name' ), 10, false, 2 );
		$pdf->text( __( 'Informazioni per la tua sessione', 'photo-booking' ), 22, true, 4 );
		$pdf->text( $appt->package_name(), 14, false, 10 );
		$pdf->rule();
		$pdf->text( __( 'Quando', 'photo-booking' ), 9, true, 2 );
		$pdf->text( ucfirst( Time::format_date( $appt->start_at ) ) . ' · ' . Time::format_time( $appt->start_at ) . ' – ' . Time::format_time( $appt->end_at ) . ' (' . sprintf( __( '%d minuti', 'photo-booking' ), $cfg['duration'] ?? 0 ) . ')', 11, false, 10 );
		if ( ! empty( $s['studio_address'] ) ) {
			$pdf->text( __( 'Dove', 'photo-booking' ), 9, true, 2 );
			$pdf->text( $s['studio_address'], 11, false, 10 );
		}
		$pdf->text( __( 'Prenotazione', 'photo-booking' ), 9, true, 2 );
		$pdf->text( $appt->number() . ' · ' . $appt->customer_name, 11, false, 10 );
		$extras = $appt->extras_label();
		if ( ! empty( $cfg['includes'] ) || $extras ) {
			$pdf->text( __( 'Cosa include', 'photo-booking' ), 9, true, 2 );
			$includes = '';
			foreach ( $appt->get_meta( 'tier' ) ? (array) ( $cfg['tiers'] ?? array() ) : array() as $t ) {
				if ( $t['key'] === $appt->get_meta( 'tier' ) ) {
					$includes = $t['includes'];
				}
			}
			if ( '' === $includes ) {
				$includes = (string) $cfg['includes'];
			}
			foreach ( array_filter( array_map( 'trim', explode( "\n", $includes ) ) ) as $line ) {
				$pdf->text( '• ' . $line, 11, false, 1 );
			}
			if ( $extras ) {
				$pdf->text( '• ' . __( 'Extra', 'photo-booking' ) . ': ' . $extras, 11, false, 1 );
			}
			$pdf->text( '', 11, false, 6 );
		}
		if ( ! empty( $cfg['instructions'] ) ) {
			$pdf->text( __( 'Come prepararsi', 'photo-booking' ), 9, true, 2 );
			$pdf->text( wp_strip_all_tags( (string) $cfg['instructions'] ), 11, false, 10 );
		}
		$pdf->text( __( 'Pagamento', 'photo-booking' ), 9, true, 2 );
		$pdf->text( sprintf( __( 'Totale %1$s · versato %2$s · da saldare %3$s', 'photo-booking' ), html_entity_decode( wp_strip_all_tags( wc_price( $appt->total ) ) ), html_entity_decode( wp_strip_all_tags( wc_price( $appt->paid ) ) ), html_entity_decode( wp_strip_all_tags( wc_price( $appt->balance_due() ) ) ) ), 11, false, 10 );
		$policy = array_filter( array_map( 'trim', explode( "\n", (string) $s['policy_text'] ) ) );
		if ( $policy ) {
			$pdf->text( __( 'Spostamenti e disdette', 'photo-booking' ), 9, true, 2 );
			foreach ( $policy as $line ) {
				$pdf->text( '• ' . $line, 10, false, 1 );
			}
			$pdf->text( '', 10, false, 6 );
		}
		$pdf->rule();
		$contact = array_filter( array( $s['business_email'], $s['business_phone'], home_url( '/' ) ) );
		$pdf->text( __( 'Per qualsiasi dubbio scrivici o chiamaci:', 'photo-booking' ) . ' ' . implode( ' · ', $contact ), 10, false, 4 );
		$pdf->text( __( 'Ti aspettiamo!', 'photo-booking' ), 10, true, 0 );

		$dir = wp_upload_dir();
		$path = trailingslashit( $dir['basedir'] ) . 'photo-booking';
		wp_mkdir_p( $path );
		if ( ! file_exists( $path . '/index.html' ) ) {
			file_put_contents( $path . '/index.html', '' ); // phpcs:ignore
			file_put_contents( $path . '/.htaccess', "Deny from all\n" ); // phpcs:ignore
		}
		$file = $path . '/informazioni-' . $appt->id . '-' . substr( md5( $appt->id . NONCE_SALT ), 0, 8 ) . '.pdf';
		file_put_contents( $file, $pdf->output() ); // phpcs:ignore
		return $file;
	}
}
