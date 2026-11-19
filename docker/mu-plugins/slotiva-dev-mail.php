<?php
/**
 * Plugin Name: Slotiva dev — mail to Mailpit
 *
 * Yalniz yerel yigin; docker/ ZIP'e girmez. wp_mail'i konteyner agindaki Mailpit'e (SMTP 1025) yonlendirir.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'phpmailer_init',
	static function ( $phpmailer ) {
		$phpmailer->isSMTP();
		$phpmailer->Host     = getenv( 'MAILPIT_HOST' ) ? getenv( 'MAILPIT_HOST' ) : 'mailpit';
		$phpmailer->Port     = (int) ( getenv( 'MAILPIT_PORT' ) ? getenv( 'MAILPIT_PORT' ) : 1025 );
		$phpmailer->SMTPAuth = false;
	}
);

// Ana makine adi "localhost" TLD'siz: PHPMailer "From" adresini gecersiz sayar.
add_filter(
	'wp_mail_from',
	static function () {
		return 'slotiva@localhost.test';
	}
);
