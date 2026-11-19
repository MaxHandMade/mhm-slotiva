<?php
/**
 * Ödeme sunucu akışı: yakalama → doğrulama → atomik alma (spec §6.3, §15.3, §15.4, §15.6, §15.8, §15.12).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Checkout;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use MHM\Slotiva\Product\BookableProduct;
use MHM\Slotiva\Reservation\HoldQuotaException;
use MHM\Slotiva\Reservation\LockBusyException;
use MHM\Slotiva\Reservation\OrderCancelledException;
use MHM\Slotiva\Reservation\OrderSupersededException;
use MHM\Slotiva\Reservation\Reservation;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Reservation\SlotTakenException;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Support\Fresh;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- imzalar sözleşmeyle camelCase.

/**
 * Store API akışı (WC 11.2.1):
 * 1. `woocommerce_store_api_checkout_update_order_from_request` → seçim doğrulanır, `_slotiva_requested_slots` metasına yazılır.
 *    Uzantı verisi yoksa ve istek order-pay yolundaysa (`/checkout/{id}`) siparişte saklı talep kullanılır (§15.4).
 * 2. `woocommerce_store_api_checkout_order_processed` (ödemeden önce) → idempotent alma (`claimForCheckoutDraft|claimForOrderPay`, karar ve yazma
 *    tek kilitli işlemde): aynı dilimler + aynı ödeme durumu → tutma yenilenir; aksi hâlde (farklı dilim, ödeme yöntemi
 *    değişimi, etkin satır yok) eskiler `superseded` olur ve yeniler alınır; oturumca supersede edilmiş sipariş reddedilir.
 *
 * Sözleşme: randevulu satır yoksa hiçbir şey yapılmaz. Klasik ödeme yolu (`woocommerce_checkout_process`) randevulu
 * sepeti sipariş oluşmadan reddeder (§15.6).
 */
final class CheckoutFlow {

	public const META_REQUESTED = '_slotiva_requested_slots';
	public const META_LABEL     = '_slotiva_label';
	public const META_DURATION  = '_slotiva_duration';
	public const META_LOCATION  = '_slotiva_location';

	/** Havale benzeri (son tarihli, `on-hold`) yöntemler; süzgeç: `slotiva_transfer_methods`. */
	private const TRANSFER_METHODS = array( 'bacs', 'cheque' );

	/** PayTR iFrame ağ geçidi ayarı (timeout_limit okunabiliyorsa kart tutma ≥ timeout_limit + 15). */
	private const PAYTR_OPTION = 'woocommerce_paytr_payment_gateway_settings';

	/**
	 * Bu istekte ANA ödeme yolundan (Store API `/checkout`, oturumun taslağı) yakalanan siparişler. Güvenli varsayılan:
	 * `claim()` yalnız bunlarda supersede edilmiş satırları yeniden alır; işaretsiz her çağrı (Store API order-pay,
	 * klasik order-pay, gelecekteki çağıranlar) order-pay sayılır ve supersede siparişi reddeder (§15.3). İstek
	 * kapsamlı; `claim()` okuyunca siler.
	 *
	 * @var array<int,true>
	 */
	private static array $checkout_draft_ids = array();

	/**
	 * Kancalar.
	 */
	public static function register(): void {
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'capture' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'claim' ) );
		add_action( 'woocommerce_store_api_cart_errors', array( self::class, 'cartErrors' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( self::class, 'validateAddToCart' ), 10, 4 );
		add_action( 'woocommerce_checkout_process', array( self::class, 'rejectClassicCheckout' ) );
	}

	/**
	 * Adım 2 (yakalama): seçimi doğrular ve siparişe yazar. Kaydetmeyi WC yapar (ara sipariş akışı).
	 *
	 * @param \WC_Order                              $order   Sipariş.
	 * @param \WP_REST_Request<array<string, mixed>> $request İstek.
	 * @throws RouteException 400 (doğrulama, ikinci randevulu satır).
	 */
	public static function capture( \WC_Order $order, \WP_REST_Request $request ): void { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- jenerik sözdizimi.
		// Seçim yalnız sipariş verilirken (POST) doğrulanır. Ödeme bloğu yöntem/not değişince taslağa PUT gönderir
		// (`Checkout::get_route_update_response`); uzantı taşımaz → saklı talep olduğu gibi kalır.
		if ( 'POST' !== $request->get_method() ) {
			return;
		}
		$line = self::bookableLine( $order );
		if ( ! $line ) {
			return;
		}
		[ $item, $bp ] = $line;
		$orderPay      = self::isOrderPayRequest( $request );
		if ( $orderPay ) {
			unset( self::$checkout_draft_ids[ $order->get_id() ] );
		} else {
			self::$checkout_draft_ids[ $order->get_id() ] = true;
		}

		$sent = self::extensionSlots( $request );
		if ( null === $sent ) {
			// Order-pay yolu uzantı göndermez (CheckoutOrder.php): doğruluk kaynağı sipariş kalemi + saklı talep (§15.4).
			// Ana ödeme yolunda seçimsiz istek her zaman reddedilir (§15.12).
			if ( $orderPay && self::storedSlots( $order ) ) {
				return;
			}
			$sent = array();
		}
		$slots = SlotValidator::validate( $bp, $sent, (int) $item->get_quantity() );
		$order->update_meta_data( self::META_REQUESTED, array_map( array( SlotValidator::class, 'iso' ), $slots ) );
	}

	/**
	 * Adım 3 (alma): idempotent. Hata → RouteException; WC siparişi `pending` olarak saklar, ödeme başlamaz.
	 *
	 * Yeniden kullanım kararı da (aynı dilim + aynı ödeme durumu → tutma yenilenir) deponun kilitli işleminde verilir
	 * (oturum → IP → sipariş). Her yolda (yeni alma ya da yeniden kullanım; ödeme ve order-pay) önce kayıtlı talebin dilim
	 * sayısı siparişin kalem adediyle karşılaştırılır (§15.4). Supersede edilmiş sipariş yalnız bu istekte ana ödeme
	 * yolundan yakalanmış (oturumun güncel taslağı) siparişte yeniden alınır (`claimForCheckoutDraft`); diğer her çağrı
	 * order-pay sayılır ve reddeder (`claimForOrderPay`).
	 *
	 * @param \WC_Order $order Sipariş (ödemeden önce, `pending`).
	 * @throws RouteException 400 seçim yok/geçersiz/adet uyuşmuyor; 409 slotiva_slot_taken | slotiva_busy |
	 *                        slotiva_superseded | slotiva_cancelled; 429 slotiva_hold_quota; 503 slotiva_busy (eşlenmemiş
	 *                        depo/veritabanı hatası, ayrıntı yalnız günlükte).
	 */
	public static function claim( \WC_Order $order ): void {
		$orderPay = ! isset( self::$checkout_draft_ids[ $order->get_id() ] );
		unset( self::$checkout_draft_ids[ $order->get_id() ] );
		$line = self::bookableLine( $order );
		if ( ! $line ) {
			return;
		}
		[ $item, $bp, $product ] = $line;

		$stored = self::storedSlots( $order );
		if ( ! $stored ) {
			throw new RouteException( 'slotiva_no_slots', esc_html__( 'Please choose a time for your appointment.', 'mhm-slotiva' ), 400 );
		}
		$qty = (int) $item->get_quantity();
		SlotValidator::assertQuantity( $bp, count( $stored ), $qty, $orderPay );
		$state = self::targetState( $order );

		try {
			$claimer = $orderPay ? array( ReservationRepository::class, 'claimForOrderPay' ) : array( ReservationRepository::class, 'claimForCheckoutDraft' );
			$claimer(
				$order->get_id(),
				array(
					'product_id'      => $bp->productId,
					'variation_id'    => $bp->variationId,
					'product_name'    => $product->get_name(),
					'order_item_id'   => $item->get_id(),
					'slots'           => $stored,
					'duration'        => $bp->durationMin,
					'buffer'          => Rules::load()->bufferMin,
					'status'          => $state['status'],
					'payment_state'   => $state['payment_state'],
					'hold_expires_at' => $state['hold_expires_at'],
					'customer'        => self::customer( $order ),
					'location'        => $bp->location,
					// Her yöntemde oturuma bağlanır: supersede + kota + alma deponun TEK işleminde (§15.3, §15.8). Depo
					// oturum supersede'ini kart taslaklarıyla (payment_state=pending) sınırlar; havale/onaylı satır korunur.
					'session_hash'    => self::sessionHash(),
					'ip_hash'         => self::ipHash(),
				),
				// Yalnız yeni almada (kilit altında): takvim kuralları alma anında yeniden doğrulanır (ufuk/ön süre kaymış olabilir).
				// Order-pay'de depo canlı satırların dilimlerini verebilir (yönetici saat değişimi, final review F1).
				static fn( array $slots ): array => SlotValidator::validate( $bp, array_map( array( SlotValidator::class, 'iso' ), $slots ), $qty )
			);
		} catch ( SlotTakenException $e ) {
			throw new RouteException(
				'slotiva_slot_taken',
				esc_html__( 'The time you chose was just taken. Please choose another time; your details are kept.', 'mhm-slotiva' ),
				409,
				array( 'taken' => array_map( 'esc_html', array_map( array( SlotValidator::class, 'iso' ), $e->taken ) ) )
			);
		} catch ( LockBusyException $e ) {
			throw new RouteException( 'slotiva_busy', esc_html__( 'Your order is being processed. Please try again in a few seconds.', 'mhm-slotiva' ), 409 );
		} catch ( HoldQuotaException $e ) {
			throw new RouteException( 'slotiva_hold_quota', esc_html__( 'You have too many bookings awaiting payment. Please complete them first or try again later.', 'mhm-slotiva' ), 429 );
		} catch ( OrderSupersededException $e ) {
			// §15.3: bu siparişin yerini daha yeni bir sipariş aldı; yeni tutma alınmaz, bu sipariş bir daha alınamaz.
			throw new RouteException( 'slotiva_superseded', esc_html__( 'This order has been replaced by a newer order and can no longer be paid. Please place a new order on the checkout page.', 'mhm-slotiva' ), 409 );
		} catch ( OrderCancelledException $e ) {
			// Final review F2: iptal edilen saat order-pay ile geri gelmez.
			throw new RouteException( 'slotiva_cancelled', esc_html__( 'This booking was cancelled by the shop and can no longer be paid. Please contact the shop or place a new order.', 'mhm-slotiva' ), 409 );
		} catch ( RouteException $e ) {
			throw $e; // Takvim doğrulaması (SlotValidator) kendi iletisiyle.
		} catch ( \RuntimeException | \LogicException | \Error $e ) {
			// Final review F4 (T8 M-2, Codex M1): eşlenmemiş depo/veritabanı hatası müşteriye ham metinle (500) gitmez.
			// Pre-ZIP gate G3: PHP `\Error` (ör. TypeError) da aynı yoldan. Hiçbir şey yazılmadı (işlem geri alındı);
			// ayrıntı yalnız günlükte.
			self::logError( $order->get_id(), $e );
			throw new RouteException( 'slotiva_busy', esc_html( self::unavailableMessage() ), 503 );
		}

		$slots = self::activeSlots( $order->get_id() );
		$slots = $slots ? $slots : $stored;
		$order->update_meta_data( self::META_REQUESTED, array_map( array( SlotValidator::class, 'iso' ), $slots ) );
		self::snapshot( $order, $item, $bp, $slots );
	}

	/**
	 * Siparişin kayıtlı talebini ve okunur kopyasını (etiket) etkin satırlardan yeniden yazar: yönetici saati değiştirince
	 * order-pay saklı talebi okur (§15.4, final review F1). Etkin satır yoksa dokunmaz.
	 *
	 * @param int $orderId Sipariş.
	 */
	public static function syncRequested( int $orderId ): void {
		$slots = self::activeSlots( $orderId );
		$order = $slots ? Fresh::order( $orderId ) : null;
		if ( ! $order ) {
			return;
		}
		$label = self::label( $slots );
		$order->update_meta_data( self::META_REQUESTED, array_map( array( SlotValidator::class, 'iso' ), $slots ) );
		$order->update_meta_data( self::META_LABEL, $label );
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && '' !== (string) $item->get_meta( self::META_LABEL ) ) {
				$item->update_meta_data( self::META_LABEL, $label );
				$item->save();
			}
		}
		$order->save();
	}

	/**
	 * Ödeme yolunun genel "şu an olmadı" iletisi (eşlenmemiş hata).
	 */
	public static function unavailableMessage(): string {
		return __( 'We could not reserve your appointment right now. Nothing was charged. Please try again in a few minutes.', 'mhm-slotiva' );
	}

	/**
	 * Siparişin etkin satırlarının dilimleri (artan).
	 *
	 * @param int $orderId Sipariş.
	 * @return list<\DateTimeImmutable>
	 */
	private static function activeSlots( int $orderId ): array {
		return array_map( static fn( Reservation $r ): \DateTimeImmutable => $r->startsAt, ReservationRepository::forOrder( $orderId, true ) );
	}

	/**
	 * Eşlenmemiş hata günlüğe (ayrıntı müşteriye gitmez).
	 *
	 * @param int        $orderId Sipariş.
	 * @param \Throwable $e       Hata.
	 */
	public static function logError( int $orderId, \Throwable $e ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( sprintf( 'slotiva checkout order #%d: %s: %s', $orderId, get_class( $e ), $e->getMessage() ), array( 'source' => 'mhm-slotiva' ) );
		}
	}

	/**
	 * Store API sepet doğrulaması: v1'de sepette tek randevulu satır (spec §6.2).
	 *
	 * @param \WP_Error $errors Hata toplayıcı.
	 * @param \WC_Cart  $cart   Sepet.
	 */
	public static function cartErrors( \WP_Error $errors, $cart ): void {
		if ( $cart instanceof \WC_Cart && count( self::cartBookableLines( $cart ) ) > 1 ) {
			$errors->add( 'slotiva_one_bookable_line', self::oneLineMessage() );
		}
	}

	/**
	 * Sepete ekleme (klasik + Store API; Store API uyarıyı RouteException'a çevirir): başka bir randevulu satır varken
	 * ikinci randevulu ürün eklenemez. Aynı ürün/varyasyon (adet artışı) serbesttir.
	 *
	 * @param bool|mixed $passed      Önceki sonuç.
	 * @param int|mixed  $productId   Ürün.
	 * @param int|mixed  $quantity    Adet.
	 * @param int|mixed  $variationId Varyasyon.
	 * @return bool|mixed
	 */
	public static function validateAddToCart( $passed, $productId, $quantity = 1, $variationId = 0 ) {
		unset( $quantity );
		if ( ! $passed || ! WC()->cart ) {
			return $passed;
		}
		$product = wc_get_product( (int) $variationId ? (int) $variationId : (int) $productId );
		$bp      = $product ? BookableProduct::from( $product ) : null;
		if ( ! $bp ) {
			return $passed;
		}
		foreach ( self::cartBookableLines( WC()->cart ) as $other ) {
			if ( $other->productId !== $bp->productId || $other->variationId !== $bp->variationId ) {
				wc_add_notice( self::oneLineMessage(), 'error' );
				return false;
			}
		}
		return $passed;
	}

	/**
	 * Klasik ödeme reddi (§15.6, senaryo 17): sipariş oluşmadan, ödeme sayfası ayarından bağımsız.
	 */
	public static function rejectClassicCheckout(): void {
		if ( WC()->cart && self::cartBookableLines( WC()->cart ) ) {
			wc_add_notice(
				__( 'Bookable services can only be bought on the current checkout page. Please contact the site administrator.', 'mhm-slotiva' ),
				'error'
			);
		}
	}

	/**
	 * Kart tutma süresi (dk): `max(Rules::cardHoldMin, PayTR timeout_limit + 15)` (PayTR ayarı okunabiliyorsa; spec §6.4).
	 */
	public static function cardHoldMinutes(): int {
		$minutes = Rules::load()->cardHoldMin;
		$paytr   = get_option( self::PAYTR_OPTION );
		if ( is_array( $paytr ) && isset( $paytr['timeout_limit'] ) && is_numeric( $paytr['timeout_limit'] ) ) {
			$minutes = max( $minutes, (int) $paytr['timeout_limit'] + 15 );
		}
		/**
		 * Kart tutma süresi (dakika, en az 15).
		 *
		 * @param int $minutes Dakika.
		 */
		return max( 15, (int) apply_filters( 'slotiva_card_hold_minutes', $minutes ) );
	}

	/**
	 * Havale gibi son tarihli (`on-hold`) ödeme yöntemleri: alma anında havale süresi alır ve son tarihte sipariş
	 * otomatik iptal edilebilir (yalnız bunlar; §15.2). Özel EFT ağ geçidi süzgeçle eklenir.
	 *
	 * @return list<string>
	 */
	public static function transferMethods(): array {
		/**
		 * Havale gibi son tarihli (`on-hold`) ödeme yöntemleri.
		 *
		 * @param list<string> $methods Yöntem kimlikleri.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'slotiva_transfer_methods', self::TRANSFER_METHODS ) ) );
	}

	/**
	 * Store API oturum özeti: md5(WC oturum müşteri kimliği). Oturum yoksa null.
	 */
	public static function sessionHash(): ?string {
		$session = function_exists( 'WC' ) ? WC()->session : null;
		if ( ! $session instanceof \WC_Session ) {
			return null;
		}
		$id = (string) $session->get_customer_id();
		return '' === $id ? null : md5( $id );
	}

	/**
	 * IP özeti: md5(REMOTE_ADDR) — vekil başlıklarına (X-Forwarded-For) asla güvenilmez.
	 */
	public static function ipHash(): ?string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return false === filter_var( $ip, FILTER_VALIDATE_IP ) ? null : md5( $ip );
	}

	/**
	 * Ödeme yöntemine göre hedef durum (spec §6.4, Codex plan eki).
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return array{status:string,payment_state:string,hold_expires_at:?\DateTimeImmutable}
	 */
	private static function targetState( \WC_Order $order ): array {
		if ( ! $order->needs_payment() ) {
			return array(
				'status'          => 'confirmed',
				'payment_state'   => 'paid',
				'hold_expires_at' => null,
			);
		}
		$method = (string) $order->get_payment_method();
		if ( 'cod' === $method ) {
			return array(
				'status'          => 'confirmed',
				'payment_state'   => 'on_site',
				'hold_expires_at' => null,
			);
		}
		if ( in_array( $method, self::transferMethods(), true ) ) {
			return array(
				'status'          => 'held',
				'payment_state'   => 'awaiting_transfer',
				'hold_expires_at' => Clock::now()->modify( '+' . Rules::load()->bankHoldHours . ' hours' ),
			);
		}
		return array(
			'status'          => 'held',
			'payment_state'   => 'pending',
			'hold_expires_at' => Clock::now()->modify( '+' . self::cardHoldMinutes() . ' minutes' ),
		);
	}

	/**
	 * Sipariş + kalem metasına okunur anlık kopya (tarih/saat etiketi, süre, yer).
	 *
	 * @param \WC_Order                $order Sipariş.
	 * @param \WC_Order_Item_Product   $item  Randevulu kalem.
	 * @param BookableProduct          $bp    Ürün.
	 * @param list<\DateTimeImmutable> $slots Dilimler.
	 */
	private static function snapshot( \WC_Order $order, \WC_Order_Item_Product $item, BookableProduct $bp, array $slots ): void { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		$label = self::label( $slots );
		foreach ( array( $item, $order ) as $target ) {
			$target->update_meta_data( self::META_LABEL, $label );
			$target->update_meta_data( self::META_DURATION, (string) $bp->durationMin );
			$target->update_meta_data( self::META_LOCATION, $bp->location );
		}
		$item->save();
		$order->save();
	}

	/**
	 * "17 Kasım Salı, 10:00" (yerel saat; birden çok dilim "; " ile).
	 *
	 * @param list<\DateTimeImmutable> $slots Dilimler.
	 */
	public static function label( array $slots ): string { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		/* translators: PHP date format for a booked slot label, e.g. "17 Kasım Salı, 10:00". */
		$format = _x( 'j F l, H:i', 'slot label date format', 'mhm-slotiva' );
		$out    = array();
		foreach ( $slots as $slot ) {
			$out[] = (string) wp_date( $format, $slot->getTimestamp() );
		}
		return implode( '; ', $out );
	}

	/**
	 * Siparişte saklı talep (yalnız biçim; takvim doğrulaması alma anında).
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return list<\DateTimeImmutable> Artan.
	 */
	private static function storedSlots( \WC_Order $order ): array {
		$raw = $order->get_meta( self::META_REQUESTED );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $iso ) {
			$dt = SlotValidator::parse( $iso );
			if ( ! $dt ) {
				return array();
			}
			$out[] = $dt;
		}
		usort( $out, static fn( \DateTimeImmutable $a, \DateTimeImmutable $b ): int => $a <=> $b );
		return $out;
	}

	/**
	 * İstekteki `extensions.slotiva.slots`; gönderilmemişse null.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request İstek.
	 * @return array<mixed>|null
	 */
	private static function extensionSlots( \WP_REST_Request $request ): ?array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- jenerik sözdizimi.
		$ext = $request->get_param( 'extensions' );
		$ns  = is_array( $ext ) && isset( $ext[ StoreApiExtension::NS ] ) && is_array( $ext[ StoreApiExtension::NS ] ) ? $ext[ StoreApiExtension::NS ] : null;
		if ( null === $ns || ! array_key_exists( 'slots', $ns ) || null === $ns['slots'] ) {
			return null;
		}
		return is_array( $ns['slots'] ) ? $ns['slots'] : array( $ns['slots'] );
	}

	/**
	 * Store API order-pay isteği mi? Rota dizgisine bakılmaz (WP rotaları büyük/küçük harf duyarsız eşler: `/CHECKOUT/{id}`
	 * de aynı işleyiciye gider); işleyicinin URL parametresine bakılır: CheckoutOrder `id` taşır, ana Checkout taşımaz.
	 * `id` varlığı yeter: herhangi bir `id` taşıyan istek order-pay sayılır (güvenli yön: supersede reddi).
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request İstek.
	 */
	private static function isOrderPayRequest( \WP_REST_Request $request ): bool { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- jenerik sözdizimi.
		return array_key_exists( 'id', $request->get_url_params() );
	}

	/**
	 * Siparişin randevulu satırı: [kalem, ürün görünümü, ürün]; yoksa null.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return array{0:\WC_Order_Item_Product,1:BookableProduct,2:\WC_Product}|null
	 * @throws RouteException 400 slotiva_one_bookable_line (v1: tek randevulu satır).
	 */
	private static function bookableLine( \WC_Order $order ): ?array {
		$found = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$bp      = $product instanceof \WC_Product ? BookableProduct::from( $product ) : null;
			if ( $product instanceof \WC_Product && $bp ) {
				$found[] = array( $item, $bp, $product );
			}
		}
		if ( count( $found ) > 1 ) {
			throw new RouteException( 'slotiva_one_bookable_line', esc_html( self::oneLineMessage() ), 400 );
		}
		return $found[0] ?? null;
	}

	/**
	 * Sepetteki randevulu satırlar (anahtar → görünüm).
	 *
	 * @param \WC_Cart $cart Sepet.
	 * @return array<string,BookableProduct>
	 */
	public static function cartBookableLines( \WC_Cart $cart ): array {
		$out = array();
		foreach ( $cart->get_cart() as $key => $line ) {
			$product = $line['data'] ?? null;
			$bp      = $product instanceof \WC_Product ? BookableProduct::from( $product ) : null;
			if ( $bp ) {
				$out[ (string) $key ] = $bp;
			}
		}
		return $out;
	}

	/**
	 * Fatura bilgisinden müşteri anlık kopyası (misafir dahil).
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return array{name:string,email:string,phone:string}
	 */
	private static function customer( \WC_Order $order ): array {
		return array(
			'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email' => (string) $order->get_billing_email(),
			'phone' => (string) $order->get_billing_phone(),
		);
	}

	/**
	 * Tek randevulu satır iletisi.
	 */
	private static function oneLineMessage(): string {
		return __( 'Your cart can hold only one bookable service at a time. Please remove the other one from your cart.', 'mhm-slotiva' );
	}
}
