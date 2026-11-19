<?php
/**
 * Sipariş sonrası müşteri ve yönetici görünümü (spec §6.5, kare 4).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Front;

use MHM\Slotiva\Checkout\CheckoutFlow;
use MHM\Slotiva\Notify\Format;
use MHM\Slotiva\Notify\Ics;
use MHM\Slotiva\Product\BookableProduct;
use MHM\Slotiva\Reservation\Reservation;
use MHM\Slotiva\Reservation\ReservationRepository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Reservation alanları ve yöntemler sözleşmeyle camelCase.

/**
 * 1. Teşekkür sayfası + Hesabım → sipariş ayrıntısı: `woocommerce_order_details_after_order_table` kartı
 *    (`templates/front/appointment-card.php`, temada `woocommerce/front/appointment-card.php` ile ezilir).
 * 2. Korumalı takvim dosyası ucu `?slotiva_ics={token}[&key={sipariş anahtarı}]`: belirteç `hash_equals`; siparişli
 *    randevuda ayrıca sipariş sahibi ya da eşleşen sipariş anahtarı; elle eklenen (siparişsiz) randevuda yalnız 32 hex
 *    belirteç. Her uyuşmazlık aynı 404 (belirtecin var olup olmadığı sızmaz).
 * 3. Kalem metası: `_slotiva_label` görünür ("Appointment"), diğer `_slotiva_*` yönetici listesinden gizli.
 * 4. Yönetici sipariş ekranı: "Randevu" kutusu (klasik yazı ekranı ve HPOS ekranı).
 */
final class OrderDisplay {

	public const QUERY_VAR  = 'slotiva_ics';
	public const METABOX_ID = 'slotiva_appointment';

	/**
	 * Kartın stil tutamacı (yalnız satır içi CSS taşır; dosyası yok).
	 */
	public const STYLE_HANDLE = 'slotiva-appointment';

	/**
	 * Kart stili: `wp_add_inline_style` ile STYLE_HANDLE'a eklenir, şablon `<style>` basmaz. Renkler temanın
	 * `--slotiva-*` özel özelliklerinden (yoksa blok teması ön ayarlarından) gelir.
	 */
	private const CARD_CSS = ''
		. '.slotiva-appointment{box-sizing:border-box;margin:1.5em 0 2em}'
		. '.slotiva-appointment *,.slotiva-appointment *::before,.slotiva-appointment *::after{box-sizing:inherit}'
		. '.slotiva-appointment__title{margin:0 0 .75em}'
		. '.slotiva-appointment__card{display:flex;gap:18px;align-items:flex-start;margin:0 0 var(--slotiva-gap,12px);padding:24px;border:1px solid var(--slotiva-accent,var(--wp--preset--color--primary,var(--wp--preset--color--contrast,currentcolor)));border-radius:var(--slotiva-radius,10px);background:var(--slotiva-surface,transparent)}'
		. '.slotiva-appointment__card--muted{border-color:var(--slotiva-border,color-mix(in srgb,currentcolor 28%,transparent));opacity:.78}'
		. '.slotiva-appointment__date{flex:0 0 76px;padding:10px 0;border-radius:calc(var(--slotiva-radius,10px) - 2px);background:var(--slotiva-accent,var(--wp--preset--color--primary,var(--wp--preset--color--contrast,currentcolor)));color:var(--slotiva-on-accent,var(--wp--preset--color--base,#fff));text-align:center;line-height:1.2}'
		. '.slotiva-appointment__date-month{display:block;font-size:.75em;letter-spacing:.08em}'
		. '.slotiva-appointment__date-day{display:block;font-size:2.4em;font-weight:800;line-height:1}'
		. '.slotiva-appointment__date-weekday{display:block;font-size:.75em}'
		. '.slotiva-appointment__body{min-width:0;display:flex;flex-direction:column;gap:6px}'
		. '.slotiva-appointment__when{margin:0;font-size:1.35em;font-weight:600;line-height:1.3}'
		. '.slotiva-appointment__meta{margin:0;color:var(--slotiva-muted,color-mix(in srgb,currentcolor 72%,transparent));font-size:.9em;overflow-wrap:anywhere}'
		. '.slotiva-appointment__note{margin:.4em 0 0}'
		. '.slotiva-appointment__status{margin:.4em 0 0;font-weight:600}'
		. '.slotiva-appointment__actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:4px}'
		. '.slotiva-appointment__btn{display:inline-flex;align-items:center;min-height:44px;padding:0 16px;border:1px solid var(--slotiva-border,color-mix(in srgb,currentcolor 30%,transparent));border-radius:var(--slotiva-radius,6px);color:inherit;font-size:.9em;text-decoration:none}'
		. '.slotiva-appointment__btn:hover,.slotiva-appointment__btn:focus-visible{border-color:var(--slotiva-accent,var(--wp--preset--color--primary,currentcolor));text-decoration:underline}'
		. '.slotiva-appointment__policy{margin:0;color:var(--slotiva-muted,color-mix(in srgb,currentcolor 72%,transparent));font-size:.9em;line-height:1.55}'
		. '@media (max-width:480px){.slotiva-appointment__card{padding:16px;gap:14px}.slotiva-appointment__date{flex-basis:64px}}';

	/**
	 * Kartta gösterilen durumlar (superseded = sipariş yeniden kuruldu, müşteriye anlamsız). `conflict` (ödendi, saat
	 * alınamadı) nötr bir notla görünür: müşteri boş bir sipariş görmesin (final review F10).
	 */
	private const CARD_STATUSES = array( 'held', 'confirmed', 'completed', 'no_show', 'cancelled', 'expired', 'conflict' );

	/**
	 * Yönetici ayrıntı sayfası: `admin.php?page=slotiva&view=detail&id={id}` (Admin\Menu, Task 14).
	 */
	private const ADMIN_PAGE = 'slotiva';

	/**
	 * Bu istekte bir kart basıldı mı (şablonun `$first` değişkeni; eski tema kopyaları için).
	 *
	 * @var bool
	 */
	private static bool $styled = false;

	/**
	 * Kancaları bağlar.
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( self::class, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueueStyle' ) );
		add_action( 'template_redirect', array( self::class, 'serveIcs' ), 1 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( self::class, 'hiddenItemMeta' ) );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( self::class, 'showLabel' ), 10, 2 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( self::class, 'labelKey' ), 10, 2 );
		add_action( 'add_meta_boxes', array( self::class, 'addMetabox' ), 10, 2 );
	}

	// ---------------------------------------------------------------------------------------------------------
	// Müşteri kartı.
	// ---------------------------------------------------------------------------------------------------------

	/**
	 * Randevu kartını basar (randevusuz siparişte hiçbir şey).
	 *
	 * @param \WC_Order|int|mixed $order Sipariş.
	 */
	public static function render( $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( is_numeric( $order ) ? (int) $order : 0 );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$rows = array_values(
			array_filter(
				ReservationRepository::forOrder( $order->get_id() ),
				static fn( Reservation $r ): bool => in_array( $r->status, self::CARD_STATUSES, true )
			)
		);
		if ( ! $rows ) {
			return;
		}

		$bookings = array();
		$policies = array();
		foreach ( $rows as $r ) {
			$bookings[] = self::cardData( $r, $order );
			$product    = wc_get_product( $r->productId );
			$bp         = $product instanceof \WC_Product ? BookableProduct::from( $product ) : null;
			$text       = null !== $bp ? trim( $bp->policyText ) : '';
			if ( '' !== $text ) {
				$policies[ md5( $text ) ] = $text;
			}
		}

		$first        = ! self::$styled;
		self::$styled = true;
		self::registerStyle();
		// Yedek: sipariş sayfaları dışında (blok teması, kısa kod) gövdede kuyruğa alınır; baş bölümü geçmişse altbilgide basılır.
		wp_enqueue_style( self::STYLE_HANDLE );
		wc_get_template(
			'front/appointment-card.php',
			array(
				'bookings' => $bookings,
				'policies' => array_values( $policies ),
				'order'    => $order,
				'first'    => $first,
			),
			'',
			SLOTIVA_DIR . '/templates/'
		);
	}

	/**
	 * `wp_enqueue_scripts`: stili kaydeder; sipariş alındı ve siparişi görüntüle (Hesabım) sayfalarında baş bölümde kuyruğa alır
	 * (gövdede kuyruğa almak klasik temalarda stilsiz ilk çizim yaratıyordu). Başka yerde `render` gövdede kuyruğa alır
	 * (blok teması, kısa kod); aynı tutamaç olduğundan iki kez eklenmez.
	 */
	public static function enqueueStyle(): void {
		self::registerStyle();
		if ( ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) || ( function_exists( 'is_view_order_page' ) && is_view_order_page() ) ) {
			wp_enqueue_style( self::STYLE_HANDLE );
		}
	}

	/**
	 * Kart stilini (dosyasız tutamaç + satır içi CSS) bir kez kaydeder (kuyruğa alma: `enqueueStyle` ya da kart basılırken).
	 */
	public static function registerStyle(): void {
		if ( wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			return;
		}
		wp_register_style( self::STYLE_HANDLE, false, array(), SLOTIVA_VERSION );
		wp_add_inline_style( self::STYLE_HANDLE, self::CARD_CSS );
	}

	/**
	 * Şablon için önceden biçimlenmiş satır (şablon hesap yapmaz).
	 *
	 * @param Reservation $r     Rezervasyon.
	 * @param \WC_Order   $order Sipariş.
	 * @return array<string,mixed>
	 */
	private static function cardData( Reservation $r, \WC_Order $order ): array {
		$row   = Format::row( $r, $order );
		$start = $r->startsAt->getTimestamp();
		$live  = 'confirmed' === $r->status;
		$note  = '';
		if ( 'held' === $r->status ) {
			$note = __( 'Awaiting payment, your time slot is being held.', 'mhm-slotiva' );
		} elseif ( 'cancelled' === $r->status || 'expired' === $r->status ) {
			$note = self::statusLabel( $r->status );
		} elseif ( 'confirmed' === $r->status ) {
			$note = __( 'Your appointment is confirmed.', 'mhm-slotiva' );
		} elseif ( 'conflict' === $r->status ) {
			$note = __( 'We could not keep this time. The shop will contact you to arrange a new time or a refund.', 'mhm-slotiva' );
		}
		$held_until = '';
		if ( 'held' === $r->status && null !== $r->holdExpiresAt ) {
			$ts         = $r->holdExpiresAt->getTimestamp();
			$held_until = wp_date( (string) get_option( 'date_format', 'F j, Y' ), $ts ) . ' ' . wp_date( (string) get_option( 'time_format', 'H:i' ), $ts );
		}
		return array(
			'id'         => $r->id,
			'status'     => $r->status,
			'service'    => $row['service'],
			'date'       => $row['date'],
			'time'       => $row['time'],
			'timezone'   => $row['timezone'],
			'location'   => $row['location'],
			'month'      => mb_strtoupper( (string) wp_date( 'M', $start ) ),
			'day'        => (string) wp_date( 'j', $start ),
			'weekday'    => (string) wp_date( 'l', $start ),
			'note'       => $note,
			'held_until' => $held_until,
			'ics_url'    => $live ? self::icsUrl( $r, $order ) : '',
			'gcal_url'   => $live ? self::googleUrl( $r, $row ) : '',
		);
	}

	/**
	 * Korumalı takvim dosyası bağlantısı.
	 *
	 * @param Reservation    $r     Rezervasyon.
	 * @param \WC_Order|null $order Sipariş.
	 */
	public static function icsUrl( Reservation $r, ?\WC_Order $order = null ): string {
		$args = array( self::QUERY_VAR => $r->token );
		if ( null !== $order && null !== $r->orderId ) {
			$args['key'] = $order->get_order_key();
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Google Takvim "etkinlik ekle" bağlantısı (zamanlar UTC).
	 *
	 * @param Reservation         $r   Rezervasyon.
	 * @param array<string,mixed> $row Notify\Format::row çıktısı.
	 */
	private static function googleUrl( Reservation $r, array $row ): string {
		$utc    = new \DateTimeZone( 'UTC' );
		$format = 'Ymd\THis\Z';
		$query  = array(
			'action'  => 'TEMPLATE',
			'text'    => wp_specialchars_decode( (string) $row['service'], ENT_QUOTES ),
			'dates'   => $r->startsAt->setTimezone( $utc )->format( $format ) . '/' . $r->endsAt->setTimezone( $utc )->format( $format ),
			'details' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);
		if ( '' !== $r->locationText ) {
			$query['location'] = $r->locationText;
		}
		return 'https://calendar.google.com/calendar/render?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Durum etiketi.
	 *
	 * @param string $status Durum.
	 */
	public static function statusLabel( string $status ): string {
		$labels = array(
			'held'       => __( 'Held', 'mhm-slotiva' ),
			'confirmed'  => __( 'Confirmed', 'mhm-slotiva' ),
			'completed'  => __( 'Completed', 'mhm-slotiva' ),
			'no_show'    => __( 'No-show', 'mhm-slotiva' ),
			'cancelled'  => __( 'Cancelled', 'mhm-slotiva' ),
			'expired'    => __( 'Expired', 'mhm-slotiva' ),
			'conflict'   => __( 'Conflict', 'mhm-slotiva' ),
			'superseded' => __( 'Superseded', 'mhm-slotiva' ),
		);
		return $labels[ $status ] ?? $status;
	}

	// ---------------------------------------------------------------------------------------------------------
	// Korumalı .ics ucu.
	// ---------------------------------------------------------------------------------------------------------

	/**
	 * Belirteç (+ sipariş anahtarı / oturum) doğruysa dosya içeriği, aksi halde null (= 404).
	 *
	 * @param string $token Belirteç (32 hex).
	 * @param string $key   Sipariş anahtarı (`wc_order_…`) ya da ''.
	 * @return array{body:string,filename:string}|null
	 */
	public static function icsFor( string $token, string $key ): ?array {
		$r = ReservationRepository::findByToken( $token );
		if ( null === $r || ! hash_equals( $r->token, $token ) ) {
			return null;
		}
		if ( 'confirmed' !== $r->status ) {
			return null;
		}
		if ( null !== $r->orderId ) {
			$order = wc_get_order( $r->orderId );
			if ( ! $order instanceof \WC_Order || ! self::mayAccess( $order, $key ) ) {
				return null;
			}
		}
		return array(
			'body'     => Ics::build( $r, 'REQUEST' ),
			'filename' => Ics::filename( $r ),
		);
	}

	/**
	 * Sipariş sahibi ya da eşleşen sipariş anahtarı.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @param string    $key   Sunulan anahtar.
	 */
	private static function mayAccess( \WC_Order $order, string $key ): bool {
		if ( '' !== $key && hash_equals( (string) $order->get_order_key(), $key ) ) {
			return true;
		}
		$owner = (int) $order->get_customer_id();
		return $owner > 0 && is_user_logged_in() && get_current_user_id() === $owner;
	}

	/**
	 * `template_redirect`: uç noktayı sunar.
	 */
	public static function serveIcs(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- e-postadaki takvim bağlantısı: nonce bir e-postada yaşayamaz (süresi dolar); yetki 32 hex belirteç (hash_equals) + sipariş sahibi ya da sipariş anahtarıyla (mayAccess). Salt okuma: hiçbir şey yazılmaz.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		$token = is_string( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		$key   = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$file = self::icsFor( $token, $key );
		if ( null === $file ) {
			self::notFound();
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file['filename'] . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		// RFC 5545 metni (text/calendar + nosniff), HTML değil: çıktı akışına yazılır. Ics::build kendi kaçışını yapar.
		echo $file['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw text/calendar download (nosniff), not HTML; Ics::build escapes per RFC 5545.
		exit;
	}

	/**
	 * Tek tip 404 (belirteç var mı yok mu ayırt edilemez).
	 */
	private static function notFound(): void {
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-Content-Type-Options: nosniff' );
		}
		// Dönülür: WP şablon yükleyicisi 404 şablonunu kendi kapsamında yükler.
	}

	// ---------------------------------------------------------------------------------------------------------
	// Kalem metası.
	// ---------------------------------------------------------------------------------------------------------

	/**
	 * Yönetici kalem listesinde gizlenecek anahtarlar (`_slotiva_label` hariç: okunur etiket).
	 *
	 * @param mixed $hidden Mevcut liste.
	 * @return list<string>
	 */
	public static function hiddenItemMeta( $hidden ): array {
		return array_values(
			array_unique(
				array_merge(
					(array) $hidden,
					array( CheckoutFlow::META_DURATION, CheckoutFlow::META_LOCATION, CheckoutFlow::META_REQUESTED )
				)
			)
		);
	}

	/**
	 * Görünür "Randevu" satırı CANLI rezervasyondan üretilir (ödeme sonrası saat değişse de sipariş tablosu ve WC e-postaları
	 * güncel kalır). `_slotiva_label` yalnız kalemin hiç rezervasyonu yoksa yedek olarak gösterilir. Ön yüzde `_` önekli
	 * metalar gizlidir, bu yüzden satır eklenir; yönetici listesinde zaten vardır, değeri yerinde güncellenir.
	 *
	 * @param mixed $formatted Biçimli meta (kimlik → nesne).
	 * @param mixed $item      Kalem.
	 * @return array<int|string,object>
	 */
	public static function showLabel( $formatted, $item ): array {
		$formatted = (array) $formatted;
		if ( ! $item instanceof \WC_Order_Item ) {
			return $formatted;
		}
		$live = self::liveLabel( $item );
		foreach ( $formatted as $meta ) {
			if ( is_object( $meta ) && CheckoutFlow::META_LABEL === ( $meta->key ?? '' ) ) {
				if ( null !== $live ) {
					$meta->value         = $live;
					$meta->display_value = wpautop( esc_html( $live ) );
				}
				return $formatted;
			}
		}
		foreach ( $item->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			if ( CheckoutFlow::META_LABEL !== $data['key'] || empty( $data['id'] ) || ! is_scalar( $data['value'] ) || '' === $data['value'] ) {
				continue;
			}
			$text                     = $live ?? (string) $data['value'];
			$formatted[ $data['id'] ] = (object) array(
				'key'           => CheckoutFlow::META_LABEL,
				'value'         => $text,
				// WC anahtarı kendisi kaçışlar (wp_kses_post / REST'te wc_clean); display_value ise HTML beklediğinden aşağıda kaçışlı kalır.
				'display_key'   => __( 'Appointment', 'mhm-slotiva' ),
				'display_value' => wpautop( esc_html( $text ) ),
			);
		}
		return $formatted;
	}

	/**
	 * Kalemin canlı randevu etiketi: "Tuesday, November 17, 2026, 10:00–10:30" (birden çok dilim "; " ile).
	 * null = kalemin rezervasyonu yok (yedek: saklı etiket); '' ise rezervasyonlar var ama gösterilecek yok.
	 *
	 * @param \WC_Order_Item $item Kalem.
	 */
	private static function liveLabel( \WC_Order_Item $item ): ?string {
		$order_id = (int) $item->get_order_id();
		if ( $order_id < 1 ) {
			return null;
		}
		$rows = ReservationRepository::forOrder( $order_id );
		$mine = array_values( array_filter( $rows, static fn( Reservation $r ): bool => (int) $r->orderItemId === (int) $item->get_id() ) );
		$pid  = $item instanceof \WC_Order_Item_Product ? (int) $item->get_product_id() : 0;
		$mine = $mine ? $mine : array_values( array_filter( $rows, static fn( Reservation $r ): bool => null === $r->orderItemId && $r->productId === $pid ) );
		if ( ! $mine ) {
			return null;
		}
		$parts = array();
		foreach ( $mine as $r ) {
			if ( in_array( $r->status, array( 'held', 'confirmed', 'completed', 'no_show' ), true ) ) {
				$row     = Format::row( $r );
				$parts[] = $row['date'] . ', ' . $row['time'];
			}
		}
		return implode( '; ', $parts );
	}

	/**
	 * `_slotiva_label` anahtarının okunur adı.
	 *
	 * @param mixed $display_key Varsayılan ad.
	 * @param mixed $meta        Meta (nesne) ya da anahtar (dize).
	 * @return string
	 */
	public static function labelKey( $display_key, $meta ): string {
		$key = is_object( $meta ) ? (string) ( $meta->key ?? '' ) : (string) $meta;
		return CheckoutFlow::META_LABEL === $key ? __( 'Appointment', 'mhm-slotiva' ) : (string) $display_key; // WC her çıktıda kaçışlar (wp_kses_post); burada ikinci kez değil.
	}

	// ---------------------------------------------------------------------------------------------------------
	// Yönetici sipariş ekranı.
	// ---------------------------------------------------------------------------------------------------------

	/**
	 * `add_meta_boxes`: yalnız Slotiva randevusu olan siparişte "Randevu" kutusu.
	 * Klasik ekran (`shop_order`) ve HPOS ekranı (`wc_get_page_screen_id`) aynı çağrıyla kapsanır.
	 *
	 * @param mixed $screen_or_type Ekran / yazı türü.
	 * @param mixed $post_or_order  `WP_Post` ya da `WC_Order`.
	 */
	public static function addMetabox( $screen_or_type, $post_or_order = null ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : null;
		if ( null === $order && $post_or_order instanceof \WP_Post ) {
			$maybe = wc_get_order( $post_or_order->ID );
			$order = $maybe instanceof \WC_Order ? $maybe : null;
		}
		if ( null === $order || ! ReservationRepository::forOrder( $order->get_id() ) ) {
			return;
		}
		$screens = array_unique( array_filter( array( (string) wc_get_page_screen_id( 'shop-order' ), is_string( $screen_or_type ) && 'shop_order' === $screen_or_type ? 'shop_order' : '' ) ) );
		add_meta_box(
			self::METABOX_ID,
			__( 'Appointment', 'mhm-slotiva' ),
			array( self::class, 'renderMetaboxCallback' ),
			array_values( $screens ),
			'side',
			'default'
		);
	}

	/**
	 * Kutu geri çağrısı (HPOS'ta `WC_Order`, klasik ekranda `WP_Post`).
	 *
	 * @param mixed $post_or_order Sipariş ya da yazı.
	 */
	public static function renderMetaboxCallback( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order instanceof \WP_Post ? $post_or_order->ID : 0 );
		if ( $order instanceof \WC_Order ) {
			self::renderMetabox( $order );
		}
	}

	/**
	 * Kutu içeriği: tarih/saat, durum + ödeme rozeti, ayrıntı sayfası bağlantısı.
	 *
	 * @param \WC_Order $order Sipariş.
	 */
	public static function renderMetabox( \WC_Order $order ): void {
		foreach ( ReservationRepository::forOrder( $order->get_id() ) as $r ) {
			$row = Format::row( $r, $order );
			$url = admin_url( 'admin.php?page=' . self::ADMIN_PAGE . '&view=detail&id=' . $r->id );
			?>
			<div class="slotiva-metabox-row" style="margin:0 0 12px;">
				<p style="margin:0 0 4px;"><strong><?php echo esc_html( $row['date'] ); ?></strong><br><?php echo esc_html( $row['time'] ); ?> · <?php echo esc_html( $row['timezone'] ); ?></p>
				<p style="margin:0 0 6px;"><?php echo esc_html( $row['service'] ); ?></p>
				<p style="margin:0 0 6px;">
					<mark class="order-status status-<?php echo esc_attr( sanitize_html_class( $r->status ) ); ?>" style="display:inline-block;padding:2px 8px;border-radius:4px;"><span><?php echo esc_html( self::statusLabel( $r->status ) ); ?></span></mark>
					<mark class="order-status status-processing" style="display:inline-block;padding:2px 8px;border-radius:4px;"><span><?php echo esc_html( Format::paymentLabel( $r->paymentState ) ); ?></span></mark>
				</p>
				<p style="margin:0;"><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open appointment details', 'mhm-slotiva' ); ?></a></p>
			</div>
			<?php
		}
	}
}
