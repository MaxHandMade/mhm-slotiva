<?php
/**
 * Bildirim testleri: gönderilen Slotiva e-postalarını (`woocommerce_email_sent`), içeriklerini (`woocommerce_mail_content`)
 * ve `.ics` eklerini (`phpmailer_init` anında ek dosyaların metni) yakalar. Posta, WP test kütüphanesinin MockPHPMailer'ıdır
 * (`reset_phpmailer_instance` / `tests_retrieve_phpmailer_instance`).
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Support;

use MHM\Slotiva\Notify\Reminders;

trait MailFixtures {

	/**
	 * Gönderilen Slotiva e-postaları.
	 *
	 * @var list<array{id:string,to:string,subject:string,body:string,ics:array<string,string>}>
	 */
	private array $sent = array();

	/**
	 * Son `phpmailer_init` anındaki ekler: dosya adı → metin.
	 *
	 * @var array<string,string>
	 */
	private array $last_attachments = array();

	/**
	 * Son gönderilen e-postanın gövdesi (satır içi stil sonrası).
	 *
	 * @var string
	 */
	private string $last_content = '';

	/**
	 * Saat biçimi yedeği (çekirdek seçeneği; SlotivaTestCase yalnız slotiva_* seçeneklerini geri yükler).
	 *
	 * @var mixed
	 */
	private $time_format_backup = '';

	private function set_up_mail(): void {
		$this->time_format_backup = get_option( 'time_format' );
		update_option( 'time_format', 'H:i' ); // Türkiye sitesi biçimi; şablon site biçimini kullanır.
		reset_phpmailer_instance();
		$this->sent             = array();
		$this->last_attachments = array();
		$this->last_content     = '';
		add_action( 'phpmailer_init', array( $this, 'capture_attachments' ), 999 );
		add_filter( 'woocommerce_mail_content', array( $this, 'capture_content' ), 999 );
		add_action( 'woocommerce_email_sent', array( $this, 'record_email' ), 10, 3 );
	}

	private function tear_down_mail(): void {
		remove_action( 'phpmailer_init', array( $this, 'capture_attachments' ), 999 );
		remove_filter( 'woocommerce_mail_content', array( $this, 'capture_content' ), 999 );
		remove_action( 'woocommerce_email_sent', array( $this, 'record_email' ), 10 );
		reset_phpmailer_instance();
		update_option( 'time_format', $this->time_format_backup );
	}

	/**
	 * Ek dosyaların metni (gönderimden hemen önce; gönderimden sonra geçici dosyalar silinir).
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Posta.
	 */
	public function capture_attachments( $phpmailer ): void {
		$this->last_attachments = array();
		foreach ( $phpmailer->getAttachments() as $a ) {
			$this->last_attachments[ (string) $a[1] ] = (string) file_get_contents( (string) $a[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test: yerel geçici dosya.
		}
	}

	/**
	 * İçerik yakalayıcı.
	 *
	 * @param string $content Gövde.
	 */
	public function capture_content( $content ) {
		$this->last_content = (string) $content;
		return $content;
	}

	/**
	 * Gönderim kaydedici (yalnız `slotiva_*`).
	 *
	 * @param bool      $ok    Gönderildi mi.
	 * @param string    $id    E-posta kimliği.
	 * @param \WC_Email $email E-posta.
	 */
	public function record_email( $ok, $id, $email ): void {
		if ( ! $ok || ! str_starts_with( (string) $id, 'slotiva_' ) ) {
			return;
		}
		$mock         = tests_retrieve_phpmailer_instance();
		$last         = $mock ? end( $mock->mock_sent ) : false;
		$this->sent[] = array(
			'id'      => (string) $id,
			'to'      => (string) $email->get_recipient(),
			'subject' => is_array( $last ) ? (string) $last['subject'] : '',
			'body'    => $this->last_content,
			'ics'     => $this->last_attachments,
		);
	}

	/**
	 * Belirli türdeki gönderimler.
	 *
	 * @param string $id E-posta kimliği.
	 * @return list<array{id:string,to:string,subject:string,body:string,ics:array<string,string>}>
	 */
	private function sent_of( string $id ): array {
		return array_values( array_filter( $this->sent, static fn( array $m ): bool => $m['id'] === $id ) );
	}

	/**
	 * Bekleyen hatırlatma işleri.
	 *
	 * @param int|null $reservation_id Süzgeç.
	 * @return list<array{args:array<string,mixed>,ts:int}>
	 */
	private function reminder_jobs( ?int $reservation_id = null ): array {
		$out = array();
		foreach ( as_get_scheduled_actions(
			array(
				'hook'     => Reminders::HOOK,
				'group'    => 'slotiva',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		) as $action ) {
			$args = $action->get_args();
			if ( null !== $reservation_id && (int) ( $args['reservation_id'] ?? 0 ) !== $reservation_id ) {
				continue;
			}
			$date  = $action->get_schedule()->get_date();
			$out[] = array(
				'args' => $args,
				'ts'   => $date ? $date->getTimestamp() : 0,
			);
		}
		return $out;
	}
}
