<?php
/**
 * Yönetim menüsü (spec §8): üst menü "Slotiva", yetki `manage_woocommerce`, WooCommerce'in hemen altı; alt sayfalar
 * `Menu::addSubpage()` ile (Task 15/17 bu sınıfı düzenlemeden ekler); React paketi sayfaya göre yüklenir.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Admin;

use MHM\Slotiva\Admin\Menu;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- menü genelleri test başına sıfırlanır ve geri yüklenir.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- çekirdek kancaları (admin_menu, menu_order) çekirdeğin yaptığı gibi tetiklenir.
// phpcs:disable WordPress.WP.Capabilities.Unknown -- WooCommerce yetkileri (manage_woocommerce …) WC kurulumunda tanımlıdır.

class MenuTest extends SlotivaTestCase {

	/**
	 * Genel menü yedekleri.
	 *
	 * @var array<string,mixed>
	 */
	private array $globals_backup = array();

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		foreach ( array( 'menu', 'submenu', '_registered_pages', '_parent_pages', 'admin_page_hooks' ) as $g ) {
			$this->globals_backup[ $g ] = $GLOBALS[ $g ] ?? null;
			$GLOBALS[ $g ]              = array();
		}
		Menu::reset();
	}

	public function tear_down(): void {
		foreach ( $this->globals_backup as $g => $v ) {
			$GLOBALS[ $g ] = $v;
		}
		Menu::reset();
		unset( $_GET['page'], $_GET['view'] );
		$this->restore_error_handler();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Menüdeki satırı bulur.
	 *
	 * @param string $slug Menü slug'ı.
	 */
	private function menu_entry( string $slug ): ?array {
		global $menu;
		foreach ( $menu as $position => $item ) {
			if ( $slug === $item[2] ) {
				return array( (string) $position, $item );
			}
		}
		return null;
	}

	public function test_menu_requires_manage_woocommerce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		Menu::register();
		do_action( 'admin_menu', '' );

		$entry = $this->menu_entry( Menu::SLUG );
		$this->assertNotNull( $entry, 'üst menü kaydedildi' );
		$this->assertSame( 'manage_woocommerce', $entry[1][1], 'üst menü yetkisi' );
		$this->assertSame( 'Slotiva', $entry[1][0] );

		global $submenu;
		$this->assertSame( 'slotiva', $submenu['slotiva'][0][2], 'ilk alt sayfa = Randevular (aynı slug)' );
		$this->assertSame( 'Appointments', $submenu['slotiva'][0][0] );
		$this->assertSame( 'manage_woocommerce', $submenu['slotiva'][0][1] );
		$this->assertTrue( current_user_can( 'manage_woocommerce' ) );
		$this->assertNotFalse( has_action( 'toplevel_page_slotiva' ), 'yetkili kullanıcı için sayfa geri çağrısı' );
		ob_start();
		do_action( 'toplevel_page_slotiva' );
		$this->assertSame( 1, substr_count( (string) ob_get_clean(), 'id="slotiva-admin-root"' ), 'sayfa bir kez basılır' );

		// Aboneye sayfa açılmaz: yetkisiz kullanıcı için çekirdek geri çağrıyı bağlamaz.
		$GLOBALS['menu']    = array();
		$GLOBALS['submenu'] = array();
		remove_all_actions( 'toplevel_page_slotiva' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		Menu::reset();
		Menu::register();
		do_action( 'admin_menu', '' );
		$this->assertFalse( current_user_can( 'manage_woocommerce' ) );
		$this->assertFalse( has_action( 'toplevel_page_slotiva' ), 'aboneye sayfa geri çağrısı yok' );
	}

	public function test_menu_sits_directly_after_woocommerce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// WooCommerce 11.2'nin kendi kayıtları: WooCommerce 55.5, eski raporlar 55.6, Ürünler (CPT) 5, Analiz/Pazarlama 56–58.
		add_menu_page( 'Posts', 'Posts', 'edit_posts', 'edit.php', '', '', 5 );
		add_menu_page( 'Products', 'Products', 'edit_products', 'edit.php?post_type=product', '', '', 26 );
		add_menu_page( 'WooCommerce', 'WooCommerce', 'edit_others_shop_orders', 'woocommerce', '', '', '55.5' );
		add_menu_page( 'Sales reports', 'Sales reports', 'view_woocommerce_reports', 'wc-reports', '', '', '55.6' );
		add_menu_page( 'Analytics', 'Analytics', 'view_woocommerce_reports', 'wc-admin&path=/analytics/overview', '', '', 57 );
		add_menu_page( 'Marketing', 'Marketing', 'manage_woocommerce', 'woocommerce-marketing', '', '', 58 );
		add_menu_page( 'Appearance', 'Appearance', 'switch_themes', 'themes.php', '', '', 60 );

		// WC_Admin_Menus::menu_order'ın birebir davranışı: Ürünler WooCommerce'in hemen altına taşınır (öncelik 10).
		$wc_order = static function ( array $order ): array {
			$out = array();
			foreach ( $order as $item ) {
				if ( 'woocommerce' === $item ) {
					$out[] = $item;
					$out[] = 'edit.php?post_type=product';
				} elseif ( 'edit.php?post_type=product' !== $item ) {
					$out[] = $item;
				}
			}
			return $out;
		};

		foreach ( array( true, false ) as $wc_reorders ) {
			if ( $wc_reorders ) {
				add_filter( 'custom_menu_order', '__return_true' );
				add_filter( 'menu_order', $wc_order );
			}
			$GLOBALS['menu'] = array_filter( $GLOBALS['menu'], static fn( $i ) => 'slotiva' !== $i[2] );
			Menu::reset();
			Menu::register();
			do_action( 'admin_menu', '' );

			// Çekirdeğin sıralaması (wp-admin/includes/menu.php): doğal sıralama, sonra custom_menu_order/menu_order.
			global $menu;
			$sorted = $menu;
			uksort( $sorted, 'strnatcasecmp' );
			$slugs = array_values( array_map( static fn( $i ) => $i[2], $sorted ) );
			if ( apply_filters( 'custom_menu_order', false ) ) {
				$slugs = array_values( apply_filters( 'menu_order', $slugs ) );
			}
			$at = array_search( 'woocommerce', $slugs, true );
			$this->assertNotFalse( $at );
			$after = $wc_reorders ? array( 'edit.php?post_type=product', 'slotiva' ) : array( 'slotiva' );
			$this->assertSame( $after, array_slice( $slugs, $at + 1, count( $after ) ), 'WooCommerce bloğunun hemen altı: ' . implode( ', ', $slugs ) );

			remove_filter( 'custom_menu_order', '__return_true' );
			remove_filter( 'menu_order', $wc_order );
		}
	}

	public function test_add_subpage_registers_under_slotiva_and_enqueues_its_bundle(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Menu::register();
		Menu::addSubpage( 'slotiva-hours', 'Working hours', 'hours' );
		Menu::addSubpage( 'slotiva-settings', 'Settings', 'settings' );
		do_action( 'admin_menu', '' );

		global $submenu;
		$slugs = array_map( static fn( $i ) => $i[2], $submenu['slotiva'] );
		$this->assertSame( array( 'slotiva', 'slotiva-hours', 'slotiva-settings' ), $slugs, 'çağrı sırasıyla' );
		$this->assertSame( 'Working hours', $submenu['slotiva'][1][0] );
		$this->assertSame( 'manage_woocommerce', $submenu['slotiva'][1][1] );

		// admin_menu'den sonra eklenen alt sayfa da hemen kaydedilir.
		Menu::addSubpage( 'slotiva-license', 'License', 'license' );
		$this->assertSame( 'slotiva-license', $submenu['slotiva'][3][2] );
		$this->assertSame( array( 'slotiva', 'slotiva-hours', 'slotiva-settings', 'slotiva-license' ), array_keys( Menu::pages() ) );

		// Sayfa → paket eşlemesi: alt sayfa kendi paketini, ana sayfa görünüme göre appointments/detail.
		$this->assertSame( 'hours', Menu::bundleFor( 'slotiva_page_slotiva-hours' ) );
		$this->assertSame( 'appointments', Menu::bundleFor( 'toplevel_page_slotiva' ) );
		$_GET['view'] = 'detail';
		$this->assertSame( 'detail', Menu::bundleFor( 'toplevel_page_slotiva' ) );
		$_GET['view'] = 'nope';
		$this->assertSame( 'appointments', Menu::bundleFor( 'toplevel_page_slotiva' ), 'bilinmeyen görünüm listeye düşer' );
		$this->assertNull( Menu::bundleFor( 'edit.php' ), 'başka ekranda paket yok' );
	}

	public function test_core_pages_schedule_and_settings_follow_appointments(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		Menu::register();
		$this->assertSame( 5, has_action( 'init', array( Menu::class, 'registerCorePages' ) ), 'init/5: sonraki görevlerin (Lisans) varsayılan önceliği ardına düşer' );
		Menu::registerCorePages();
		Menu::addSubpage( 'slotiva-license', 'License', 'license' );
		do_action( 'admin_menu', '' );

		global $submenu;
		$slugs = array_map( static fn( $i ) => $i[2], $submenu['slotiva'] );
		$this->assertSame( array( 'slotiva', Menu::SCHEDULE_SLUG, Menu::SETTINGS_SLUG, 'slotiva-license' ), $slugs, 'spec §8: Randevular · Çalışma saatleri · Ayarlar · Lisans' );
		$this->assertSame( 'Working hours', $submenu['slotiva'][1][0] );
		$this->assertSame( 'Settings', $submenu['slotiva'][2][0] );
		$this->assertSame( 'manage_woocommerce', $submenu['slotiva'][1][1] );
		$this->assertSame( 'manage_woocommerce', $submenu['slotiva'][2][1] );
		$this->assertSame( 'schedule', Menu::bundleFor( 'slotiva_page_' . Menu::SCHEDULE_SLUG ) );
		$this->assertSame( 'settings', Menu::bundleFor( 'slotiva_page_' . Menu::SETTINGS_SLUG ) );

		$data = Menu::pageData( 'settings' );
		$this->assertStringEndsWith( 'admin.php?page=wc-settings&tab=email', $data['emailSettingsUrl'] );
		$this->assertArrayNotHasKey( 'emailSettingsUrl', Menu::pageData( 'schedule' ) );
		$this->assertArrayNotHasKey( 'products', Menu::pageData( 'schedule' ) );
	}

	public function test_docs_base_follows_user_locale(): void {
		$en = self::factory()->user->create(
			array(
				'role'   => 'administrator',
				'locale' => 'en_US',
			)
		);
		$tr = self::factory()->user->create(
			array(
				'role'   => 'administrator',
				'locale' => 'tr_TR',
			)
		);

		wp_set_current_user( $en );
		$this->assertSame( 'https://maxhandmade.github.io/mhm-slotiva-docs/docs/', Menu::pageData( 'settings' )['docsBase'] );
		wp_set_current_user( $tr );
		$this->assertSame( 'https://maxhandmade.github.io/mhm-slotiva-docs/tr/docs/', Menu::pageData( 'license' )['docsBase'], 'every bundle carries it' );
	}

	public function test_enqueue_loads_kit_bundle_and_page_data(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Menu::register();
		do_action( 'admin_menu', '' );
		Menu::enqueue( 'toplevel_page_slotiva' ); // Çekirdeğin diğer admin_enqueue_scripts geri çağrıları ekran ister; doğrudan.

		$this->assertTrue( wp_script_is( 'slotiva-react-appointments', 'enqueued' ), 'React paketi' );
		$this->assertTrue( wp_style_is( 'mhmuicore-admin', 'enqueued' ), 'ui-core kit stili' );
		$script = wp_scripts()->registered['slotiva-react-appointments'];
		$this->assertStringEndsWith( 'build/admin/appointments.js', $script->src );
		$this->assertSame( 'mhm-slotiva', $script->textdomain, 'wp_set_script_translations metin alanı' );

		$inline = implode( "\n", (array) wp_scripts()->get_data( 'slotiva-react-appointments', 'before' ) );
		$this->assertStringContainsString( 'window.slotivaAdmin', $inline );
		$this->assertStringContainsString( '"restRoot"', $inline );
		$this->assertStringContainsString( '"exportNonce"', $inline );
		$this->assertStringContainsString( 'page=slotiva', $inline );

		// Başka ekranda hiçbir şey yüklenmez.
		wp_dequeue_script( 'slotiva-react-appointments' );
		Menu::enqueue( 'edit.php' );
		$this->assertFalse( wp_script_is( 'slotiva-react-appointments', 'enqueued' ) );
	}

	public function test_detail_url_shape(): void {
		$this->assertStringEndsWith( 'admin.php?page=slotiva&view=detail&id=42', Menu::detailUrl( 42 ) );
		$this->assertStringEndsWith( 'admin.php?page=slotiva', Menu::listUrl() );
	}

	public function test_render_prints_mount_node(): void {
		ob_start();
		Menu::render( 'slotiva' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'id="slotiva-admin-root"', $html );
		$this->assertStringContainsString( 'mhmui-admin', $html );
		$this->assertStringContainsString( 'wp-header-end', $html );
	}

	/**
	 * Test içinde kurulan hata yakalayıcı.
	 *
	 * @var bool
	 */
	private bool $handler_set = false;

	private function restore_error_handler(): void {
		if ( $this->handler_set ) {
			restore_error_handler();
			$this->handler_set = false;
		}
	}

	/**
	 * 1.0.4 D1: gizli sihirbaz sayfası (boş üst slug) yüklenirken çekirdek `$title`'ı boş bırakıp
	 * `admin-header.php` içinde `strip_tags( null )` kullanımdan kalkma uyarısı üretiyordu.
	 */
	public function test_setup_page_load_has_a_title_and_emits_no_deprecation(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Menu::register();
		do_action( 'admin_menu', '' );

		$saved = array();
		foreach ( array( 'pagenow', 'plugin_page', 'title', 'typenow', 'parent_file' ) as $g ) {
			$saved[ $g ] = $GLOBALS[ $g ] ?? null;
		}
		$GLOBALS['pagenow']     = 'admin.php';
		$GLOBALS['plugin_page'] = Menu::SETUP_SLUG;
		$GLOBALS['title']       = null;
		$GLOBALS['typenow']     = '';
		$GLOBALS['parent_file'] = '';

		$errors = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- uyarı yakalamak testin amacı.
		set_error_handler(
			static function ( int $no, string $str ) use ( &$errors ): bool {
				$errors[] = $no . ': ' . $str;
				return true;
			}
		);
		$this->handler_set = true;
		try {
			// admin.php sırası: load-{hook} → admin-header.php (`get_admin_page_title(); strip_tags( $title )`).
			do_action( 'load-' . get_plugin_page_hookname( Menu::SETUP_SLUG, '' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- çekirdeğin `load-{hook}` kancası.
			get_admin_page_title();
			$stripped = strip_tags( $GLOBALS['title'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- admin-header.php'nin yaptığı çağrının aynısı.
		} finally {
			$this->restore_error_handler();
			foreach ( $saved as $g => $v ) {
				$GLOBALS[ $g ] = $v;
			}
		}
		$this->assertSame( array(), $errors, 'sihirbaz sayfası yüklenirken uyarı/kullanımdan kalkma yok' );
		$this->assertSame( __( 'Setup', 'mhm-slotiva' ), $stripped );
	}

	public function test_setup_page_is_hidden_and_fullscreen(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// Menü kurulurken hiçbir bildirim/uyarı/kullanımdan kalkma çıkmamalı (boş üst slug, PHP 8.3).
		$errors = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- bildirim yakalamak testin amacı.
		set_error_handler(
			static function ( int $no, string $str ) use ( &$errors ): bool {
				$errors[] = $no . ': ' . $str;
				return true;
			}
		);
		$this->handler_set = true;
		Menu::register();
		do_action( 'admin_menu', '' );
		$this->restore_error_handler();
		$this->assertSame( array(), $errors, 'gizli sayfa kaydı bildirimsiz' );

		// Slotiva alt menüsünde ve üst menülerde görünmez.
		global $submenu, $menu;
		$slugs = array_map( static fn( $i ) => $i[2], $submenu[ Menu::SLUG ] );
		$this->assertNotContains( Menu::SETUP_SLUG, $slugs );
		$this->assertNotContains( Menu::SETUP_SLUG, array_map( static fn( $i ) => $i[2], $menu ) );
		foreach ( $submenu as $parent => $items ) {
			if ( '' !== (string) $parent ) {
				$this->assertNotContains( Menu::SETUP_SLUG, array_map( static fn( $i ) => $i[2], $items ), "altmenü: $parent" );
			}
		}

		// Adresiyle açılır: ekran kancası paketi `setup` döner, sayfa yetkilidir.
		$hook = get_plugin_page_hookname( Menu::SETUP_SLUG, '' );
		$this->assertSame( 'setup', Menu::bundleFor( $hook ) );
		$this->assertTrue( user_can( get_current_user_id(), 'manage_woocommerce' ) );
		$this->assertNotFalse( has_action( $hook ), 'sayfa geri çağrısı bağlandı' );

		$data = Menu::pageData( 'setup' );
		foreach ( array( 'restRoot', 'listUrl', 'scheduleUrl', 'settingsUrl' ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}
		$this->assertStringEndsWith( 'page=slotiva-schedule', $data['scheduleUrl'] );
		$this->assertArrayNotHasKey( 'scheduleUrl', Menu::pageData( 'settings' ) );
		$this->assertStringEndsWith( 'admin.php?page=slotiva-setup&step=3', Menu::setupUrl( 3 ) );
		$this->assertStringEndsWith( 'admin.php?page=slotiva-setup&step=0', Menu::setupUrl() );

		// Gövde sınıfı yalnız bu sayfada.
		$_GET['page'] = 'slotiva';
		$this->assertStringNotContainsString( 'slotiva-setup-screen', (string) Menu::bodyClass( 'wp-admin ' ) );
		$_GET['page'] = Menu::SETUP_SLUG;
		$this->assertStringContainsString( 'slotiva-setup-screen', (string) Menu::bodyClass( 'wp-admin ' ) );
	}

	public function test_appointments_page_data_has_setup_summary(): void {
		$data = Menu::pageData( 'appointments' );
		$this->assertArrayHasKey( 'setup', $data );
		foreach ( array( 'complete', 'dismissed', 'checks' ) as $key ) {
			$this->assertArrayHasKey( $key, $data['setup'] );
		}
		$this->assertArrayHasKey( 'hours', $data['setup']['checks'] );
		$this->assertArrayNotHasKey( 'license', $data['setup']['checks'] );
		$this->assertStringEndsWith( 'page=slotiva-setup&step=3', $data['setupLinks']['product'] );
		$this->assertArrayNotHasKey( 'setup', Menu::pageData( 'settings' ) );
	}

	/** Ayarlar "+ Yeni randevulu ürün" sihirbazı yeni ürün kipinde açar (mevcut ürün ön seçilmez). */
	public function test_settings_new_product_link_carries_new_flag(): void {
		$links = Menu::pageData( 'settings' )['setupLinks'];
		$this->assertStringEndsWith( 'page=slotiva-setup&step=3&new=1', $links['product'] );
		$this->assertStringEndsWith( 'page=slotiva-setup&step=0', $links['wizard'] );
	}
}
