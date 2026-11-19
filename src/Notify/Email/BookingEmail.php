<?php
/**
 * Slotiva e-postalarının ortak tabanı (spec §7).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Notify\Email;

use MHM\Slotiva\Notify\Format;
use MHM\Slotiva\Notify\Ics;
use MHM\Slotiva\Reservation\Reservation;
use MHM\Slotiva\Reservation\ReservationRepository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation alanları sözleşmeyle camelCase.

/**
 * WC e-posta ayarlarından açılır/kapanır; şablonlar WC şablon yükleyicisiyle ezilir:
 * `{tema}/woocommerce/emails/slotiva-*.php` (ve `emails/plain/…`), varsayılan `{eklenti}/templates/`.
 * Takvim dosyaları geçici dosya olarak eklenir (`woocommerce_email_attachments` süzgeci korunur) ve gönderimden sonra silinir.
 */
abstract class BookingEmail extends \WC_Email {

	/**
	 * Bu gönderimin rezervasyonları (başlangıca göre artan).
	 *
	 * @var list<Reservation>
	 */
	public array $reservations = array();

	/**
	 * Sipariş (elle eklenende null).
	 *
	 * @var \WC_Order|null
	 */
	public ?\WC_Order $order = null;

	/**
	 * Bağlam (ör. çakışma nedeni).
	 *
	 * @var string
	 */
	public string $reason = '';

	/**
	 * Eklenecek takvim yöntemi; '' = ek yok.
	 *
	 * @var string
	 */
	protected string $ics_method = '';

	/**
	 * Bu gönderimde yazılan geçici dosyalar.
	 *
	 * @var list<string>
	 */
	private array $tmp_files = array();

	/**
	 * Alt sınıf kimliği, başlığı ve şablon adını verir.
	 *
	 * @param string $id       E-posta kimliği (`slotiva_…`).
	 * @param string $slug     Şablon adı (`slotiva-customer-confirmed`).
	 * @param bool   $customer Müşteriye mi.
	 */
	protected function setup( string $id, string $slug, bool $customer ): void {
		$this->id             = $id;
		$this->customer_email = $customer;
		$this->template_base  = trailingslashit( SLOTIVA_DIR ) . 'templates/';
		$this->template_html  = 'emails/' . $slug . '.php';
		$this->template_plain = 'emails/plain/' . $slug . '.php';
		$this->placeholders   = array(
			'{booking_date}' => '',
			'{order_number}' => '',
		);
		parent::__construct();
		if ( ! $customer ) {
			$this->recipient = (string) $this->get_option( 'recipient', (string) get_option( 'admin_email' ) );
		}
	}

	/**
	 * Gönderir.
	 *
	 * @param list<Reservation> $reservations Rezervasyonlar.
	 * @param \WC_Order|null    $order        Sipariş.
	 * @param string            $reason       Bağlam.
	 * @return bool Gönderildi mi.
	 */
	public function trigger( array $reservations, ?\WC_Order $order = null, string $reason = '' ): bool { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		if ( ! $reservations ) {
			return false;
		}
		usort( $reservations, static fn( Reservation $a, Reservation $b ): int => array( $a->startsAt, $a->id ) <=> array( $b->startsAt, $b->id ) );
		$this->setup_locale();
		try {
			$this->reservations = $reservations;
			$this->order        = $order;
			$this->object       = $order;
			$this->reason       = $reason;
			if ( $this->customer_email ) {
				$this->recipient = $this->customerAddress();
			}
			$this->placeholders['{booking_date}'] = Format::when( $this->reservations[0] );
			$this->placeholders['{order_number}'] = $order ? (string) $order->get_order_number() : '';
			return $this->send_notification();
		} finally {
			$this->restore_locale();
		}
	}

	/**
	 * Müşteri adresi: rezervasyonun anlık kopyası, yoksa siparişin fatura e-postası.
	 */
	private function customerAddress(): string {
		foreach ( $this->reservations as $r ) {
			if ( ReservationRepository::isAnonymised( $r->id ) ) {
				return ''; // Silinmiş kişi: fatura e-postasına düşülmez.
			}
		}
		foreach ( $this->reservations as $r ) {
			if ( '' !== $r->customerEmail && is_email( $r->customerEmail ) ) {
				return $r->customerEmail;
			}
		}
		return $this->order ? (string) $this->order->get_billing_email() : '';
	}

	/**
	 * Yönetici e-postalarına "alıcı" alanı.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		parent::init_form_fields();
		if ( $this->customer_email ) {
			return;
		}
		$recipient         = array(
			'recipient' => array(
				'title'       => __( 'Recipient(s)', 'mhm-slotiva' ),
				'type'        => 'text',
				/* translators: %s: default recipient address. */
				'description' => sprintf( __( 'Enter recipients (comma separated) for this email. Defaults to %s.', 'mhm-slotiva' ), '<code>' . esc_attr( (string) get_option( 'admin_email' ) ) . '</code>' ),
				'placeholder' => '',
				'default'     => '',
				'desc_tip'    => true,
			),
		);
		$this->form_fields = array_slice( $this->form_fields, 0, 1, true ) + $recipient + array_slice( $this->form_fields, 1, null, true );
	}

	/**
	 * Şablona giden değişkenler.
	 *
	 * @param bool $plain Düz metin mi.
	 * @return array<string,mixed>
	 */
	protected function template_args( bool $plain ): array {
		$order = $this->order;
		$url   = '';
		if ( $order ) {
			if ( $this->customer_email ) {
				$url = $order->get_customer_id() ? $order->get_view_order_url() : $order->get_checkout_order_received_url();
			} else {
				$url = $order->get_edit_order_url();
			}
		}
		return array(
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'bookings'           => array_map( static fn( Reservation $r ): array => Format::row( $r, $order ), $this->reservations ),
			'order'              => $order,
			'order_number'       => $order ? (string) $order->get_order_number() : '',
			'order_url'          => $url,
			'reason'             => $this->reason,
			'has_ics'            => '' !== $this->ics_method,
			'sent_to_admin'      => ! $this->customer_email,
			'plain_text'         => $plain,
			'email'              => $this,
		);
	}

	/**
	 * HTML gövde.
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/**
	 * Düz metin gövde.
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	/**
	 * Ekler: süzgeçten gelenler + her rezervasyona ayrı `.ics` (§15.11).
	 *
	 * @return array<int|string,string>
	 */
	public function get_attachments() {
		$files = (array) parent::get_attachments();
		if ( '' === $this->ics_method || ! $this->reservations ) {
			return $files;
		}
		$dir = trailingslashit( get_temp_dir() ) . 'slotiva-' . wp_generate_password( 20, false, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return $files;
		}
		$this->tmp_files[] = $dir;
		foreach ( $this->reservations as $r ) {
			$path = $dir . '/' . Ics::filename( $r );
			if ( false !== file_put_contents( $path, Ics::build( $r, $this->ics_method ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- geçici ek dosyası; WP_Filesystem kimlik bilgisi isteyebilir.
				$files[]           = $path;
				$this->tmp_files[] = $path;
			}
		}
		return $files;
	}

	/**
	 * Gönderir, sonra geçici `.ics` dosyalarını siler.
	 *
	 * @param string       $to          Alıcı.
	 * @param string       $subject     Konu.
	 * @param string       $message     Gövde.
	 * @param string       $headers     Başlıklar.
	 * @param array<mixed> $attachments Ekler.
	 * @return bool
	 */
	public function send( $to, $subject, $message, $headers, $attachments ) {
		try {
			return (bool) parent::send( $to, $subject, $message, $headers, $attachments );
		} finally {
			foreach ( array_reverse( $this->tmp_files ) as $path ) {
				if ( is_dir( $path ) ) {
					rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- kendi geçici dizinimiz.
				} else {
					wp_delete_file( $path );
				}
			}
			$this->tmp_files = array();
		}
	}
}
