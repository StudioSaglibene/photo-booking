<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Email registry. Every email is a WooCommerce email (WooCommerce → Impostazioni → Email):
 * same branding, editable subject/heading/text, can be disabled individually.
 */
class Emails {

	public static function init() {
		add_filter( 'woocommerce_email_styles', array( __CLASS__, 'email_styles' ) );
		add_filter( 'woocommerce_email_attachments', array( __CLASS__, 'attachments' ), 10, 3 );
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register' ) );
	}

	/**
	 * Definitions. Placeholders: {customer_name} {package} {date} {time} {appointment_number} {site_title}
	 * {delivery_title} {expires} {amount}
	 */
	public static function definitions() {
		return array(
			// Customer.
			'request_received'   => array(
				'to'      => 'customer',
				'title'   => 'Prenotazione – richiesta ricevuta',
				'subject' => 'Abbiamo ricevuto la tua richiesta di prenotazione',
				'heading' => 'Richiesta ricevuta',
				'intro'   => "Ciao {customer_name},\ngrazie! Abbiamo ricevuto il pagamento e la tua richiesta per {package} il {date} alle {time}.\nTi confermeremo l'appuntamento al più presto: riceverai un'altra email.",
				'button'  => 'account',
			),
			'confirmed'          => array(
				'to'      => 'customer',
				'title'   => 'Prenotazione – confermata',
				'subject' => 'La tua sessione del {date} è confermata',
				'heading' => 'Prenotazione confermata',
				'intro'   => "Ciao {customer_name},\nla tua sessione {package} è confermata per {date} alle {time}. Ti aspettiamo!",
				'button'  => 'account',
			),
			'rejected'           => array(
				'to'      => 'customer',
				'title'   => 'Prenotazione – richiesta non accettata',
				'subject' => 'La tua richiesta di prenotazione',
				'heading' => 'Non è stato possibile confermare',
				'intro'   => "Ciao {customer_name},\npurtroppo non possiamo confermare la sessione del {date} alle {time}.",
				'button'  => 'account',
			),
			'proposal'           => array(
				'to'      => 'customer',
				'title'   => 'Prenotazione – proposta di nuova data',
				'subject' => 'Ti proponiamo una nuova data per la tua sessione',
				'heading' => 'Scegli la nuova data',
				'intro'   => "Ciao {customer_name},\nl'orario che avevi scelto per {package} non è disponibile. Ti proponiamo queste alternative: clicca sulla data che preferisci entro {expires}.\nSe nessuna va bene puoi rifiutare la proposta dalla tua area privata.",
				'button'  => 'appointment',
			),
			'proposal_expired'   => array(
				'to'      => 'customer',
				'title'   => 'Prenotazione – proposta scaduta',
				'subject' => 'La proposta di nuova data è scaduta',
				'heading' => 'Proposta scaduta',
				'intro'   => "Ciao {customer_name},\nnon abbiamo ricevuto risposta alla proposta di nuova data per {package}. Le date proposte sono state liberate: ti ricontatteremo a breve, oppure scrivici tu.",
				'button'  => 'appointment',
			),
			'rescheduled'        => array(
				'to'      => 'customer',
				'title'   => 'Prenotazione – spostata',
				'subject' => 'Il tuo appuntamento è stato spostato',
				'heading' => 'Nuova data confermata',
				'intro'   => "Ciao {customer_name},\nil tuo appuntamento per {package} è stato spostato a {date} alle {time}.",
				'button'  => 'account',
			),
			'rescheduled_pending' => array(
				'to'      => 'customer',
				'title'   => 'Prenotazione – spostata dal cliente (da confermare)',
				'subject' => 'Richiesta di spostamento ricevuta',
				'heading' => 'Nuova data in attesa di conferma',
				'intro'   => "Ciao {customer_name},\nabbiamo ricevuto la tua richiesta di spostare {package} a {date} alle {time}. Lo studio la verificherà e ti confermerà la nuova data al più presto via email.",
				'button'  => 'account',
			),
			'cancelled'          => array(
				'to'      => 'customer',
				'title'   => 'Prenotazione – annullata',
				'subject' => 'La tua prenotazione è stata annullata',
				'heading' => 'Prenotazione annullata',
				'intro'   => "Ciao {customer_name},\nla prenotazione per {package} del {date} alle {time} è stata annullata.",
				'button'  => 'account',
			),
			'balance_received'   => array(
				'to'      => 'customer',
				'title'   => 'Prenotazione – pagamento ricevuto',
				'subject' => 'Pagamento ricevuto – grazie!',
				'heading' => 'Pagamento ricevuto',
				'intro'   => "Ciao {customer_name},\nabbiamo ricevuto il pagamento di {amount} per {package}.",
				'button'  => 'account',
			),
			'reminder'           => array(
				'to'      => 'customer',
				'title'   => 'Prenotazione – promemoria',
				'subject' => 'Ci vediamo domani alle {time}',
				'heading' => 'Ci vediamo domani!',
				'intro'   => "Ciao {customer_name},\nti ricordiamo l'appuntamento di domani, {date} alle {time}, per {package}.",
				'button'  => 'account',
			),
			'credit_issued'      => array(
				'to'      => 'customer',
				'title'   => 'Credito emesso',
				'subject' => 'Hai un credito di {amount}',
				'heading' => 'Il tuo credito',
				'intro'   => "Ciao {customer_name},\nabbiamo creato per te un credito di {amount}, valido fino al {expires}, utilizzabile su qualsiasi servizio in studio. Verrà applicato automaticamente alla tua prossima prenotazione.",
				'button'  => 'book',
			),
			'delivery_ready'     => array(
				'to'      => 'customer',
				'title'   => 'Consegna – nuove foto disponibili',
				'subject' => 'Le tue fotografie sono pronte',
				'heading' => 'Le tue fotografie sono pronte',
				'intro'   => "Ciao {customer_name},\nla consegna \"{delivery_title}\" è disponibile nella tua area privata. Puoi scaricarla fino al {expires}.",
				'button'  => 'deliveries',
			),
			'delivery_expiring'  => array(
				'to'      => 'customer',
				'title'   => 'Consegna – in scadenza',
				'subject' => 'Le tue fotografie scadono il {expires}',
				'heading' => 'Ricordati di scaricare le foto',
				'intro'   => "Ciao {customer_name},\nla consegna \"{delivery_title}\" sarà scaricabile fino al {expires}. Ti consigliamo di scaricarla e salvarne una copia.",
				'button'  => 'deliveries',
			),
			'delivery_expired'   => array(
				'to'      => 'customer',
				'title'   => 'Consegna – scaduta',
				'subject' => 'La consegna "{delivery_title}" è scaduta',
				'heading' => 'Consegna scaduta',
				'intro'   => "Ciao {customer_name},\nla consegna \"{delivery_title}\" non è più scaricabile. Se ti serve ancora, contattaci al più presto.",
				'button'  => '',
			),
			// Admin.
			'admin_new_request'  => array(
				'to'      => 'admin',
				'title'   => 'Admin – nuova richiesta di prenotazione',
				'subject' => '[{site_title}] Nuova richiesta {appointment_number}: {customer_name}, {date} {time}',
				'heading' => 'Nuova richiesta da confermare',
				'intro'   => '{customer_name} ha richiesto {package} per {date} alle {time}.',
				'button'  => 'admin',
			),
			'admin_balance_paid' => array(
				'to'      => 'admin',
				'title'   => 'Admin – saldo pagato online',
				'subject' => '[{site_title}] Saldo pagato {appointment_number}: {customer_name}',
				'heading' => 'Saldo ricevuto',
				'intro'   => '{customer_name} ha pagato online il saldo di {amount}.',
				'button'  => 'admin',
			),
			'admin_proposal_answered' => array(
				'to'      => 'admin',
				'title'   => 'Admin – risposta alla proposta',
				'subject' => '[{site_title}] Risposta alla proposta {appointment_number}: {customer_name}',
				'heading' => 'Il cliente ha risposto',
				'intro'   => '{customer_name} ha risposto alla proposta di nuova data.',
				'button'  => 'admin',
			),
			'admin_proposal_unanswered' => array(
				'to'      => 'admin',
				'title'   => 'Admin – proposta senza risposta',
				'subject' => '[{site_title}] Proposta senza risposta {appointment_number}: {customer_name}',
				'heading' => 'Proposta scaduta',
				'intro'   => '{customer_name} non ha risposto alla proposta. Puoi riproporre altre date, rimborsare o contattarlo.',
				'button'  => 'admin',
			),
			'admin_customer_rescheduled' => array(
				'to'      => 'admin',
				'title'   => 'Admin – il cliente ha spostato',
				'subject' => '[{site_title}] {customer_name} ha spostato l\'appuntamento {appointment_number}',
				'heading' => 'Appuntamento spostato dal cliente: da confermare',
				'intro'   => '{customer_name} ha spostato {package} al {date} alle {time}. La prenotazione è tornata "In attesa di conferma": controlla la nuova data e confermala.',
				'button'  => 'admin',
			),
			'admin_customer_cancelled' => array(
				'to'      => 'admin',
				'title'   => 'Admin – il cliente ha disdetto',
				'subject' => '[{site_title}] {customer_name} ha disdetto {appointment_number}',
				'heading' => 'Disdetta del cliente',
				'intro'   => '{customer_name} ha disdetto {package} del {date} alle {time}.',
				'button'  => 'admin',
			),
			'admin_manual_refund' => array(
				'to'      => 'admin',
				'title'   => 'Admin – rimborso manuale da gestire',
				'subject' => '[{site_title}] Rimborso manuale da gestire {appointment_number}',
				'heading' => 'Rimborso da restituire a mano',
				'intro'   => 'Per la prenotazione di {customer_name} ci sono {amount} pagati in contanti (o con un metodo senza rimborso automatico) da restituire manualmente.',
				'button'  => 'admin',
			),
		);
	}

	public static function register( $emails ) {
		require_once PB_DIR . 'includes/emails/class-booking-email.php';
		foreach ( self::definitions() as $key => $def ) {
			$emails[ 'PB_Email_' . $key ] = new Emails\Booking_Email( $key, $def );
		}
		return $emails;
	}

	/**
	 * Send an email.
	 *
	 * @param string                       $key
	 * @param Appointment|array|object     $object Appointment, or delivery row.
	 * @param array                        $extra
	 */
	/** Email look in line with the booking page: serif headings, black buttons, soft cream background. */
	public static function email_styles( $css ) {
		return $css . '
		#wrapper { background-color: #f2eee8 !important; padding: 48px 0 !important; }
		#template_container { box-shadow: none !important; border-radius: 0 !important; border: 1px solid #e6dfd6 !important; background: #fffefb !important; }
		#template_header { background-color: #fffefb !important; border-bottom: 1px solid #e6dfd6 !important; border-radius: 0 !important; }
		#template_header h1 { color: #141414 !important; font-family: Georgia, "Times New Roman", serif !important; font-weight: 400 !important; font-size: 30px !important; letter-spacing: 0 !important; text-shadow: none !important; }
		#body_content, #body_content_inner, #template_body td { background: #fffefb !important; color: #141414 !important; font-family: Helvetica, Arial, sans-serif !important; font-size: 15px !important; line-height: 1.7 !important; }
		#body_content_inner h2, #body_content_inner h3 { color: #141414 !important; font-family: Georgia, "Times New Roman", serif !important; font-weight: 400 !important; }
		#body_content_inner a { color: #141414 !important; }
		#body_content_inner a.pb-button, #body_content_inner a.button, #body_content_inner .button { display: inline-block !important; padding: 12px 24px !important; border: 1px solid #141414 !important; background: #141414 !important; background-color: #141414 !important; color: #fffefb !important; text-decoration: none !important; font-weight: 500 !important; border-radius: 0 !important; }
		#body_content_inner a.pb-button span, #body_content_inner a.button span { color: #fffefb !important; }
		@media (prefers-color-scheme: dark) { #body_content_inner a.pb-button, #body_content_inner a.pb-button span { background-color: #141414 !important; color: #fffefb !important; } }
		#body_content_inner table.td, #body_content_inner table.td th, #body_content_inner table.td td { border-color: #e6dfd6 !important; color: #141414 !important; }
		#template_footer, #template_footer td, #template_footer #credit { color: #7b6d64 !important; font-family: Helvetica, Arial, sans-serif !important; font-size: 12px !important; }
		';
	}

	/**
	 * Confirmation email: attach the package's own PDF sheet, or a generated one with the details of this booking.
	 */
	public static function attachments( $attachments, $email_id, $object ) {
		if ( 'pb_confirmed' !== $email_id || ! $object instanceof Appointment ) {
			return $attachments;
		}
		$cfg = Package::config( $object->product_id );
		if ( ! empty( $cfg['pdf'] ) ) {
			$file = get_attached_file( $cfg['pdf'] );
			if ( $file && file_exists( $file ) ) {
				$attachments[] = $file;
			}
		}
		$sheet = Pdf::info_sheet( $object );
		if ( $sheet && file_exists( $sheet ) ) {
			$attachments[] = $sheet;
		}
		return $attachments;
	}

	public static function send( $key, $object, array $extra = array() ) {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		$emails = WC()->mailer()->get_emails();
		$email  = $emails[ 'PB_Email_' . $key ] ?? null;
		if ( ! $email ) {
			return false;
		}
		return $email->trigger( $object, $extra );
	}
}
