<?php
/**
 * Yönetim menüsü (spec §8): üst menü "Slotiva" → Randevular (+ ayrıntı aynı sayfada `&view=detail&id=`), sonraki
 * görevlerin alt sayfaları (`addSubpage`). Her sayfa bir React paketi (`build/admin/<paket>.js`), ui-core kiti ve
 * yükleyicisiyle (`mhmuicore_enqueue_kit` + `mhmuicore_enqueue_react_page`).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Admin;

use MHM\Slotiva\Availability\RestController as AvailabilityRest;
use MHM\Slotiva\Setup\SetupRest;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- yöntem adları sözleşmeyle camelCase (Menu::addSubpage, Task 15/17).

/**
 * Menü kaydı, sayfa iskeleti ve paket yükleme.
 */
final class Menu {

	/** Üst menü ve Randevular sayfasının slug'ı. */
	public const SLUG = 'slotiva';

	/** Tüm Slotiva yönetim sayfalarının yetkisi (REST ile aynı). */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Menü konumu: WooCommerce 55.5'in ardı. Çekirdek konumları doğal sıralar ("55.51" > "55.6"), WooCommerce de
	 * Ürünler'i kendi altına taşır; asıl yer `menu_order` süzgeciyle verilir (`order()`), bu yalnız ilk sıralamadır.
	 */
	public const POSITION = 55.51;

	/** React kök düğümünün kimliği. */
	public const ROOT_ID = 'slotiva-admin-root';

	/** Paket tanıtıcısı öneki (`slotiva-react-<paket>`). */
	public const HANDLE_PREFIX = 'slotiva-react-';

	/** Çalışma saatleri sayfası (kare 8): haftalık düzen, istisnalar, kurallar. */
	public const SCHEDULE_SLUG = 'slotiva-schedule';

	/** Ayarlar sayfası: politika metni, saklama süresi, kaldırınca silme, e-posta ayarları bağlantısı. */
	public const SETTINGS_SLUG = 'slotiva-settings';

	/** Kurulum sihirbazı (1.0.2): menüde görünmeyen tam ekran sayfa; etkinleştirmede bir kez buraya yönlenir. */
	public const SETUP_SLUG = 'slotiva-setup';

	/** Sihirbaz sayfasında gövdeye eklenen sınıf (yönetim menüsü ve altbilgi gizlenir, `setup.scss`). */
	public const SETUP_BODY_CLASS = 'slotiva-setup-screen';

	/** Ana sayfanın görünümleri: `&view=<ad>` → paket. Bilinmeyen görünüm listeye düşer. */
	private const VIEWS = array( 'detail' => 'detail' );

	/**
	 * Sayfalar, kayıt sırasıyla: slug → {title, bundle}. İlki ana sayfadır (Randevular).
	 *
	 * @var array<string,array{title:string,bundle:string,capability:string}>
	 */
	private static array $pages = array();

	/**
	 * Kaydedilmiş sayfaların ekran kancası → slug.
	 *
	 * @var array<string,string>
	 */
	private static array $hooks = array();

	/**
	 * Menü bu istekte kuruldu mu (sonradan gelen `addSubpage` hemen kaydedilir).
	 *
	 * @var bool
	 */
	private static bool $built = false;

	/**
	 * Kancalar. Randevular sayfası burada eklenir; başlığı `admin_menu` anında çevrilir (init öncesi çeviri yüklenmez).
	 */
	public static function register(): void {
		if ( ! isset( self::$pages[ self::SLUG ] ) ) {
			$main        = array(
				'title'      => '',
				'bundle'     => 'appointments',
				'capability' => self::CAPABILITY,
			);
			self::$pages = array( self::SLUG => $main ) + self::$pages;
		}
		// init/5: başlıklar çevrilebilir; sonraki görevlerin varsayılan öncelikli `addSubpage`'i ardına düşer.
		add_action( 'init', array( self::class, 'registerCorePages' ), 5 );
		add_action( 'admin_menu', array( self::class, 'build' ) );
		add_filter( 'custom_menu_order', '__return_true' );
		add_filter( 'menu_order', array( self::class, 'order' ), 20 );
		add_filter( 'admin_body_class', array( self::class, 'bodyClass' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Alt sayfa ekler (Task 15: Çalışma saatleri, Ayarlar). Çağrı sırası menü sırasıdır. Başlık
	 * çevrilmiş metindir; `init` ya da sonrasında çağrılmalı. Menü kurulduktan sonra çağrılırsa hemen kaydedilir.
	 *
	 * @param string $slug       Sayfa slug'ı (`admin.php?page=<slug>`).
	 * @param string $title      Sayfa ve menü başlığı.
	 * @param string $bundle     `build/admin/<bundle>.js` paket adı.
	 * @param string $capability Sayfa yetkisi (vars. `manage_woocommerce`).
	 */
	public static function addSubpage( string $slug, string $title, string $bundle, string $capability = self::CAPABILITY ): void {
		$slug   = sanitize_key( $slug );
		$bundle = sanitize_key( $bundle );
		if ( '' === $slug || '' === $bundle || self::SLUG === $slug ) {
			return;
		}
		self::$pages[ $slug ] = array(
			'title'      => $title,
			'bundle'     => $bundle,
			'capability' => '' === $capability ? self::CAPABILITY : $capability,
		);
		if ( self::$built ) {
			self::addPage( $slug );
		}
	}

	/** Task 15 sayfaları: Çalışma saatleri, Ayarlar (spec §8 menü sırası). */
	public static function registerCorePages(): void {
		self::addSubpage( self::SCHEDULE_SLUG, __( 'Working hours', 'mhm-slotiva' ), 'schedule' );
		self::addSubpage( self::SETTINGS_SLUG, __( 'Settings', 'mhm-slotiva' ), 'settings' );
	}

	/**
	 * Kayıtlı sayfalar (slug → {title, bundle}), menü sırasıyla.
	 *
	 * @return array<string,array{title:string,bundle:string,capability:string}>
	 */
	public static function pages(): array {
		return self::$pages;
	}

	/** `admin_menu`: üst menü + alt sayfalar. */
	public static function build(): void {
		// Üst menü; ilk alt satırı (aynı slug) "Randevular" olur.
		$hook = add_menu_page(
			__( 'Slotiva', 'mhm-slotiva' ),
			__( 'Slotiva', 'mhm-slotiva' ),
			self::CAPABILITY,
			self::SLUG,
			static function (): void {
				self::render( self::SLUG );
			},
			'dashicons-calendar-alt',
			self::POSITION
		);

		self::$hooks[ $hook ] = self::SLUG;
		self::$built          = true;

		foreach ( array_keys( self::$pages ) as $slug ) {
			self::addPage( $slug );
		}
		self::addHiddenPage();
	}

	/**
	 * Sihirbaz sayfası: boş üst slug ile kaydedilir, hiçbir menüde görünmez ama adresiyle açılır ve yetki denetimi çalışır.
	 */
	private static function addHiddenPage(): void {
		$title = __( 'Setup', 'mhm-slotiva' );
		$hook  = add_submenu_page(
			'',
			$title,
			$title,
			self::CAPABILITY,
			self::SETUP_SLUG,
			static function (): void {
				self::render( self::SETUP_SLUG );
			}
		);
		if ( is_string( $hook ) && '' !== $hook ) {
			self::$hooks[ $hook ] = self::SETUP_SLUG;
			// Boş üst slug'lı sayfada çekirdek `$title`'ı çözemez (null kalır) ve admin-header.php `strip_tags( null )` uyarır.
			// `load-{hook}` admin-header'dan önce çalışır; `get_admin_page_title()` dolu `$title`'ı olduğu gibi döner.
			add_action(
				'load-' . $hook,
				static function () use ( $title ): void {
					$GLOBALS['title'] = $title; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- çekirdeğin sayfa başlığı genel değişkeni.
				}
			);
		}
	}

	/**
	 * `admin_body_class`: yalnız sihirbaz sayfasında tam ekran sınıfı.
	 *
	 * @param mixed $classes Boşlukla ayrılmış sınıflar.
	 * @return mixed
	 */
	public static function bodyClass( $classes ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- salt okunan yönlendirme değeri (admin.php?page=): yalnız gövde sınıfını seçer, hiçbir şey yazmaz ya da yetkilendirmez.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SETUP_SLUG === $page && is_string( $classes ) ) {
			return $classes . ' ' . self::SETUP_BODY_CLASS . ' ';
		}
		return $classes;
	}

	/**
	 * Tek alt sayfa kaydı. Ana sayfa (aynı slug) menüdeki ilk satırı "Slotiva" yerine "Randevular" yapar.
	 *
	 * @param string $slug Sayfa.
	 */
	private static function addPage( string $slug ): void {
		// `addSubpage` herkese açık API'dir; yönetim API'si yüklenmemiş bir istekte (REST, cron) çağrılırsa ölümcül hata olmasın.
		if ( ! function_exists( 'add_submenu_page' ) ) {
			return;
		}
		$title = self::SLUG === $slug ? __( 'Appointments', 'mhm-slotiva' ) : self::$pages[ $slug ]['title'];
		$hook  = add_submenu_page(
			self::SLUG,
			$title,
			$title,
			self::$pages[ $slug ]['capability'],
			$slug,
			// Ana sayfanın geri çağrısı add_menu_page'de; aynı slug'a ikincisi sayfayı iki kez basar (aynı kanca).
			self::SLUG === $slug ? '' : static function () use ( $slug ): void {
				self::render( $slug );
			}
		);
		if ( is_string( $hook ) && '' !== $hook ) {
			self::$hooks[ $hook ] = $slug;
		}
	}

	/**
	 * `menu_order`: Slotiva WooCommerce bloğunun (WooCommerce + onun hemen altına taşıdığı Ürünler) hemen altına.
	 *
	 * @param mixed $order Menü slug'ları.
	 * @return mixed
	 */
	public static function order( $order ) {
		if ( ! is_array( $order ) ) {
			return $order;
		}
		$order = array_values( array_filter( $order, static fn( $item ): bool => self::SLUG !== $item ) );
		$at    = array_search( 'woocommerce', $order, true );
		if ( false === $at ) {
			return $order;
		}
		$insert = (int) $at + 1;
		if ( isset( $order[ $insert ] ) && 'edit.php?post_type=product' === $order[ $insert ] ) {
			++$insert;
		}
		array_splice( $order, $insert, 0, array( self::SLUG ) );
		return $order;
	}

	/**
	 * Ekran kancasının paketi; Slotiva sayfası değilse null. Ana sayfada `view` sorgusu paketi seçer.
	 *
	 * @param string $hook Ekran kancası (`admin_enqueue_scripts` argümanı).
	 */
	public static function bundleFor( string $hook ): ?string {
		$slug = self::$hooks[ $hook ] ?? null;
		if ( self::SETUP_SLUG === $slug ) {
			return 'setup';
		}
		if ( null === $slug || ! isset( self::$pages[ $slug ] ) ) {
			return null;
		}
		if ( self::SLUG === $slug ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- salt okunan görünüm seçimi (&view=): yalnız yüklenecek React paketini seçer; sayfa yetkisi menü kaydında, veri REST'te ayrıca yetkilenir.
			$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
			if ( isset( self::VIEWS[ $view ] ) ) {
				return self::VIEWS[ $view ];
			}
		}
		return self::$pages[ $slug ]['bundle'];
	}

	/**
	 * `admin_enqueue_scripts`: kit stili, React paketi (nonce ara katmanı + çeviriler yükleyicide) ve sayfa verisi.
	 *
	 * @param mixed $hook Ekran kancası.
	 */
	public static function enqueue( $hook ): void {
		$bundle = self::bundleFor( (string) $hook );
		if ( null === $bundle || ! function_exists( 'mhmuicore_enqueue_react_page' ) ) {
			return;
		}
		if ( function_exists( 'mhmuicore_enqueue_kit' ) ) {
			mhmuicore_enqueue_kit( 'admin', SLOTIVA_DIR . '/vendor/mhm/ui-core' );
		}
		mhmuicore_enqueue_react_page(
			array(
				'page'          => $bundle,
				'base_dir'      => trailingslashit( SLOTIVA_DIR ),
				'base_url'      => trailingslashit( SLOTIVA_URL ),
				'handle_prefix' => self::HANDLE_PREFIX,
				'version'       => SLOTIVA_VERSION,
				'text_domain'   => 'mhm-slotiva',
			)
		);
		// Sayfanın kendi stili (yükleyici yalnız betiği alır); sürüm üretilen asset.php'den.
		$css = 'build/admin/' . $bundle . '.css';
		if ( file_exists( trailingslashit( SLOTIVA_DIR ) . $css ) ) {
			$asset = trailingslashit( SLOTIVA_DIR ) . 'build/admin/' . $bundle . '.asset.php';
			$meta  = file_exists( $asset ) ? include $asset : array();
			wp_enqueue_style(
				self::HANDLE_PREFIX . $bundle,
				trailingslashit( SLOTIVA_URL ) . $css,
				array( 'wp-components' ),
				is_array( $meta ) && isset( $meta['version'] ) ? (string) $meta['version'] : SLOTIVA_VERSION
			);
		}
		wp_add_inline_script(
			self::HANDLE_PREFIX . $bundle,
			'window.slotivaAdmin = ' . wp_json_encode( self::pageData( $bundle ) ) . ';',
			'before'
		);
	}

	/**
	 * Docs site root for help links: Turkish pages under `tr/` when the admin user's locale is Turkish.
	 *
	 * @return string Ends with `docs/`.
	 */
	public static function docsBase(): string {
		$tr = str_starts_with( strtolower( (string) get_user_locale() ), 'tr' );
		return 'https://maxhandmade.github.io/mhm-slotiva-docs/' . ( $tr ? 'tr/' : '' ) . 'docs/';
	}

	/**
	 * Paketin başlangıç verisi. Sonraki görevler `slotiva_admin_page_data` süzgeciyle ekler.
	 *
	 * @param string $bundle Paket.
	 * @return array<string,mixed>
	 */
	public static function pageData( string $bundle ): array {
		$data = array(
			'restRoot'    => esc_url_raw( rest_url() ),
			'exportNonce' => wp_create_nonce( 'wp_rest' ),
			'listUrl'     => self::listUrl(),
			'detailUrl'   => admin_url( 'admin.php?page=' . self::SLUG . '&view=detail&id=' ),
			'today'       => wp_date( 'Y-m-d' ),
			'tzLabel'     => AvailabilityRest::tz_label(),
			'timezone'    => wp_timezone_string(),
			'docsBase'    => self::docsBase(),
		);
		if ( 'appointments' === $bundle ) {
			$data['products'] = self::bookableProducts();
			// Kurulum listesi (1.0.2): ilk boyamada hazır olsun diye istek atmadan sayfa verisine konur.
			$data['setup']      = SetupRest::setupSummary();
			$data['setupLinks'] = array(
				'wizard'   => self::setupUrl( 0 ),
				'product'  => self::setupUrl( 3 ),
				'schedule' => admin_url( 'admin.php?page=' . self::SCHEDULE_SLUG ),
				'products' => admin_url( 'edit.php?post_type=product' ),
			);
		}
		if ( 'settings' === $bundle ) {
			// Randevu e-postaları WC_Email alt sınıfları (spec §7): açma/kapama ve metin WooCommerce e-posta ayarlarında.
			$data['emailSettingsUrl'] = admin_url( 'admin.php?page=wc-settings&tab=email' );
			// Randevulu ürünler kartı (1.0.2): özet SetupRest::summary ile aynı (ikinci bir özet yazılmaz).
			$data['bookable'] = array();
			foreach ( self::bookableProducts() as $row ) {
				$product = wc_get_product( $row['id'] );
				if ( $product ) {
					$data['bookable'][] = SetupRest::summary( $product );
				}
			}
			// `new=1`: sihirbaz mevcut randevulu ürünü ön seçmez, yeni ürün kipinde açılır.
			$data['setupLinks'] = array(
				'wizard'  => self::setupUrl( 0 ),
				'product' => self::setupUrl( 3 ) . '&new=1',
			);
		}
		if ( 'setup' === $bundle ) {
			$data['scheduleUrl'] = admin_url( 'admin.php?page=' . self::SCHEDULE_SLUG );
			$data['settingsUrl'] = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );
		}
		/**
		 * Yönetim sayfası başlangıç verisi (`window.slotivaAdmin`).
		 *
		 * @param array  $data   Veri.
		 * @param string $bundle Paket adı.
		 */
		return (array) apply_filters( 'slotiva_admin_page_data', $data, $bundle );
	}

	/**
	 * Elle randevu için seçilebilir ürünler: `_slotiva_enabled=yes`, çöpte değil (REST ile aynı kural).
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	public static function bookableProducts(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- seçim listesi, sayfalanmaz.
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- yönetici ekranı, en çok 200 ürün.
					array(
						'key'   => '_slotiva_enabled',
						'value' => 'yes',
					),
				),
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$out[] = array(
				'id'   => (int) $id,
				'name' => html_entity_decode( get_the_title( (int) $id ), ENT_QUOTES, 'UTF-8' ),
			);
		}
		return $out;
	}

	/** Randevular listesi adresi. */
	public static function listUrl(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Kurulum sihirbazı adresi.
	 *
	 * @param int $step Adım (0 = karşılama, 1–5 adımlar).
	 */
	public static function setupUrl( int $step = 0 ): string {
		return admin_url( 'admin.php?page=' . self::SETUP_SLUG . '&step=' . max( 0, $step ) );
	}

	/**
	 * Randevu ayrıntısı adresi.
	 *
	 * @param int $id Rezervasyon.
	 */
	public static function detailUrl( int $id ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&view=detail&id=' . $id );
	}

	/**
	 * Sayfa iskeleti: kit kapsamı (`.mhmui-admin`), bildirim çapası, React kök düğümü. Başlığı React çizer (h1).
	 *
	 * @param string $slug Sayfa.
	 */
	public static function render( string $slug ): void {
		?>
		<div class="wrap mhmui-admin mhmui-admin-page slotiva-admin">
			<hr class="wp-header-end">
			<div id="<?php echo esc_attr( self::ROOT_ID ); ?>" data-page="<?php echo esc_attr( $slug ); ?>">
				<p><?php esc_html_e( 'Loading…', 'mhm-slotiva' ); ?></p>
			</div>
			<noscript><p><?php esc_html_e( 'This screen needs JavaScript.', 'mhm-slotiva' ); ?></p></noscript>
		</div>
		<?php
	}

	/** Test yardımcısı: kayıtları sıfırlar. */
	public static function reset(): void {
		self::$pages = array();
		self::$hooks = array();
		self::$built = false;
	}
}
