<?php
/**
 * WP test kütüphanesi yapılandırması — değerler wpcli konteynerinin ortamından gelir.
 * Test koşusu tabloları SİLER: test DB adı site DB adıyla aynıysa hemen durur.
 */

$mhm_tests_db = getenv( 'WP_TESTS_DB_NAME' ) ?: '';
if ( '' === $mhm_tests_db || getenv( 'WORDPRESS_DB_NAME' ) === $mhm_tests_db ) {
	fwrite( STDERR, "Test DB adı boş ya da site DB'siyle aynı — durduruldu.\n" );
	exit( 1 );
}

// Lokal Docker: /var/www/html/ · CI: tools/ci/install-wp.sh WP_CORE_DIR'i verir.
define( 'ABSPATH', rtrim( getenv( 'WP_CORE_DIR' ) ?: '/var/www/html', '/' ) . '/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_TESTS_MULTISITE', false );
define( 'WP_DEBUG', true );

define( 'DB_NAME', $mhm_tests_db );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASS' ) );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
