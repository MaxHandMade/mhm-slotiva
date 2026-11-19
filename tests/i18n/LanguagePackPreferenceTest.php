<?php
/**
 * Policy D13: the language pack wins, the plugin catalogue fills the keys the pack lacks (PHP and JS).
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\I18n;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core's textdomain registry is replaced per test and restored in tear_down.

use MHM\Slotiva\I18n\LanguagePackPreference;
use WP_Textdomain_Registry;
use WP_UnitTestCase;

class LanguagePackPreferenceTest extends WP_UnitTestCase {

	private const DOMAIN = 'mhm-slotiva';
	private const LOCALE = 'tr_TR';
	private const HANDLE = 'slotiva-i18n-test';

	/** A source URL outside this site's plugins/content URLs, like a plugin checked out beside the WP install (CI). */
	private const SRC = 'https://assets.example.test/checkout/build/checkout/index.js';

	/**
	 * Fake pack files created by the test (removed in tear_down).
	 *
	 * @var string[]
	 */
	private array $created = array();

	/**
	 * The registry the test replaces.
	 *
	 * @var mixed
	 */
	private $orig_registry;

	private string $locale = self::LOCALE;

	public function set_up(): void {
		parent::set_up();
		$this->orig_registry = $GLOBALS['wp_textdomain_registry'];
		$this->reset_state();
		$this->locale = self::LOCALE;
		add_filter( 'locale', fn() => $this->locale );
		if ( ! is_dir( WP_LANG_DIR . '/plugins' ) ) {
			wp_mkdir_p( WP_LANG_DIR . '/plugins' );
		}
		if ( ! wp_is_writable( WP_LANG_DIR . '/plugins' ) ) {
			// Never a silent skip: WP_LANG_DIR is a constant the WP test library fixes, so the test cannot relocate it.
			$this->fail( 'WP_LANG_DIR/plugins (' . WP_LANG_DIR . '/plugins) must be writable by the test user: the i18n policy tests write fake language packs there (docker/Dockerfile.cli does it for the local image; CI owns /tmp/wordpress-tests-lib).' );
		}
	}

	public function tear_down(): void {
		foreach ( $this->created as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.
			}
		}
		$this->created = array();
		wp_deregister_script( self::HANDLE );
		$GLOBALS['wp_textdomain_registry'] = $this->orig_registry;
		$this->reset_state();
		parent::tear_down();
	}

	/**
	 * Forgets everything the loader cached about the domain (registry paths, file lists, loaded catalogues).
	 */
	private function reset_state(): void {
		unload_textdomain( self::DOMAIN );
		unset( $GLOBALS['l10n_unloaded'][ self::DOMAIN ] );
		$GLOBALS['wp_textdomain_registry'] = new WP_Textdomain_Registry();
		wp_cache_flush();
	}

	private function write_pack( string $name, string $contents ): string {
		$file            = WP_LANG_DIR . '/plugins/' . $name;
		$this->created[] = $file;
		file_put_contents( $file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- Test fixture.
		$this->reset_state(); // Anything that translated before the pack existed has cached the plugin-only catalogue.
		return $file;
	}

	private function tr( string $text ): string {
		return translate( $text, self::DOMAIN ); // phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction,WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain -- Dynamic by design.
	}

	public function test_php_without_a_pack_uses_the_plugin_catalogue(): void {
		$this->assertSame( 'Tarih', $this->tr( 'Date' ) );
		$this->assertSame( 'Saat', $this->tr( 'Time' ) );
	}

	public function test_php_pack_wins_and_plugin_catalogue_fills_missing_keys(): void {
		$this->write_pack(
			self::DOMAIN . '-' . self::LOCALE . '.l10n.php',
			"<?php
return array( 'domain' => 'mhm-slotiva', 'language' => 'tr_TR', 'plural-forms' => 'nplurals=2; plural=(n > 1);', 'messages' => array( 'Date' => 'PACK-Tarih' ) );
"
		);
		$this->assertSame( 'PACK-Tarih', $this->tr( 'Date' ), 'The pack must win where it has the key.' );
		$this->assertSame( 'Saat', $this->tr( 'Time' ), 'A key missing in the pack falls back to the plugin catalogue.' );
		$this->assertSame( 'Untranslated source string xyz', $this->tr( 'Untranslated source string xyz' ) );
	}

	public function test_php_pack_as_mo_file_also_wins(): void {
		$mo = new \MO();
		$mo->set_header( 'Language', self::LOCALE );
		$mo->set_header( 'Plural-Forms', 'nplurals=2; plural=(n > 1);' );
		$mo->add_entry(
			new \Translation_Entry(
				array(
					'singular'     => 'Date',
					'translations' => array( 'MO-Tarih' ),
				)
			)
		);
		$file            = WP_LANG_DIR . '/plugins/' . self::DOMAIN . '-' . self::LOCALE . '.mo';
		$this->created[] = $file;
		$this->assertTrue( $mo->export_to_file( $file ) );
		$this->reset_state();

		$this->assertSame( 'MO-Tarih', $this->tr( 'Date' ) );
		$this->assertSame( 'Saat', $this->tr( 'Time' ) );
	}

	public function test_php_pack_still_wins_after_a_locale_switch(): void {
		$this->write_pack(
			self::DOMAIN . '-' . self::LOCALE . '.l10n.php',
			"<?php\nreturn array( 'domain' => 'mhm-slotiva', 'language' => 'tr_TR', 'plural-forms' => 'nplurals=2; plural=(n > 1);', 'messages' => array( 'Date' => 'PACK-Tarih' ) );\n"
		);
		$this->assertSame( 'PACK-Tarih', $this->tr( 'Date' ) );

		// What WP_Locale_Switcher does: unload the domain, then just-in-time loading runs again for the new locale.
		$this->locale = 'en_US';
		unload_textdomain( self::DOMAIN, true );
		$this->assertSame( 'Date', $this->tr( 'Date' ) );
		$this->locale = self::LOCALE;
		unload_textdomain( self::DOMAIN, true );

		$this->assertSame( 'PACK-Tarih', $this->tr( 'Date' ) );
		$this->assertSame( 'Saat', $this->tr( 'Time' ) );
	}

	/**
	 * Registers a script whose bundled JSON is the real checkout catalogue; returns [ bundled file, pack file, keys ].
	 *
	 * @return array{0:string,1:string,2:string[]}
	 */
	private function js_fixture(): array {
		$bundled = SLOTIVA_DIR . '/languages/' . self::DOMAIN . '-' . self::LOCALE . '-' . md5( 'build/checkout/index.js' ) . '.json';
		$this->assertFileExists( $bundled );
		$keys = array_keys( json_decode( (string) file_get_contents( $bundled ), true )['locale_data']['messages'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		$keys = array_values( array_filter( $keys, static fn( $k ) => '' !== $k ) );

		// Core derives the catalogue name from the src relative to this site's plugins dir; pin it so the test does not
		// depend on where the plugin is installed (CI checks it out beside WP, not under wp-content/plugins).
		add_filter(
			'load_script_textdomain_relative_path',
			static fn( $relative, $src ) => self::SRC === $src ? 'build/checkout/index.js' : $relative,
			10,
			2
		);
		wp_register_script( self::HANDLE, self::SRC, array(), '1', true );
		wp_set_script_translations( self::HANDLE, self::DOMAIN, SLOTIVA_DIR . '/languages' );

		return array( $bundled, WP_LANG_DIR . '/plugins/' . basename( $bundled ), $keys );
	}

	public function test_js_without_a_pack_uses_the_plugin_json(): void {
		list( $bundled ) = $this->js_fixture();

		$json = load_script_textdomain( self::HANDLE, self::DOMAIN, SLOTIVA_DIR . '/languages' );
		$this->assertSame( file_get_contents( $bundled ), $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
	}

	public function test_js_pack_wins_and_plugin_json_fills_missing_keys(): void {
		list( $bundled, $pack_file, $keys ) = $this->js_fixture();
		$this->assertGreaterThan( 2, count( $keys ) );

		$bundled_data                    = json_decode( (string) file_get_contents( $bundled ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		$pack                            = $bundled_data;
		$pack['locale_data']['messages'] = array(
			''       => $bundled_data['locale_data']['messages'][''],
			$keys[0] => array( 'PACK-JS' ),
		);
		$this->write_pack( basename( $pack_file ), (string) wp_json_encode( $pack ) );

		$json = load_script_textdomain( self::HANDLE, self::DOMAIN, SLOTIVA_DIR . '/languages' );
		$this->assertIsString( $json );
		$data = json_decode( $json, true )['locale_data']['messages'];

		$this->assertSame( array( 'PACK-JS' ), $data[ $keys[0] ], 'The pack wins where it has the key.' );
		$this->assertSame( $bundled_data['locale_data']['messages'][ $keys[1] ], $data[ $keys[1] ], 'A missing key falls back to the plugin JSON.' );
		$this->assertCount( count( $bundled_data['locale_data']['messages'] ), $data );
	}

	public function test_js_filters_ignore_other_text_domains(): void {
		$file = SLOTIVA_DIR . '/languages/x.json';
		$this->assertSame( $file, LanguagePackPreference::prefer_pack_file( $file, 'h', 'other-domain' ) );
		$this->assertSame( '{"a":1}', LanguagePackPreference::complete_from_bundled( '{"a":1}', $file, 'h', 'other-domain' ) );
		$this->assertFalse( LanguagePackPreference::prefer_pack_file( false, 'h', self::DOMAIN ) );
	}

	public function test_plugin_no_longer_calls_load_plugin_textdomain(): void {
		$this->assertStringNotContainsString( 'load_plugin_textdomain', (string) file_get_contents( SLOTIVA_DIR . '/mhm-slotiva.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
	}
}
