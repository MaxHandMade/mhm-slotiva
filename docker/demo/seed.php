<?php
/**
 * Demo seed calisma govdesi (docker/demo-seed.sh cagirir): `wp eval-file seed.php <mod> <dil>`.
 *   mod: locale  site dili + para birimi + odeme yontemi basliklari
 *        status  "ok" (demo tamam ve dil ayni) ya da "needs-seed" yazar
 *        seed    demo veriyi kurar (gerekirse once yalniz ISARETLI nesneleri siler)
 *        rebuild isaretli nesneleri silip yeniden kurar
 *
 * R32: her demo nesne `_slotiva_demo` = 1 (urun + siparis; istisna satiri note `[demo]` ile baslar). Rezervasyon tablosunda
 * meta yoktur: demo rezervasyon = demo urune bagli satir. Silme/yeniden kurma YALNIZ bunlara dokunur.
 *
 * Rezervasyonlar eklentinin kendi yollarindan olusur: siparisliler CheckoutFlow::claim() (odeme akisiyla ayni) + WC siparis
 * gecisleri (PaymentListener kancalari), elle eklenen ReservationRepository::claim() (yonetici REST rotasiyla ayni), durumlar
 * ReservationRepository::setStatus()/release() ve HoldScheduler::expire(). Gecmis tarihli siparisler Clock::freeze() ile
 * "o gun" verilmis gibi alinir (dogrulayici ve ucret kurallari o ani gorur); REST'in gecmis-tarih yasagi (isAdminBookable)
 * yalniz gelecekteki elle eklemede uygulanir.
 *
 * TEK R32 ISTISNASI: demo calisma saatleri isaretsiz `slotiva_schedule` seceneginin ustune yazilir (zorunlu). Ilk uzerine
 * yazmadan once onceki deger `slotiva_demo_prev_weekly` seceneginde saklanir (yalniz yoksa yazilir); `restore` modu
 * (demo-seed.sh --reset) bunu geri yukler ve saklama secenegini siler.
 * Ayni sekilde (demo modu): genel iptal politikasi metni `slotiva_demo_prev_policy`de, EN demo'nun site saat dilimi
 * (Europe/London) `slotiva_demo_prev_tz`de saklanir; `restore`/TR modu geri yukler ve saklama seceneklerini siler.
 *
 * @package MHM\Slotiva
 */

use MHM\Slotiva\Availability\SlotCalculator;
use MHM\Slotiva\Checkout\CheckoutFlow;
use MHM\Slotiva\Checkout\SlotValidator;
use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Lifecycle\HoldScheduler;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Schedule\ExceptionRepository;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Schedule\WeeklySchedule;
use MHM\Slotiva\Support\Clock;

// phpcs:disable -- yerel gelistirme araci, eklenti kodu degil.

$mode = (string) ( $args[0] ?? 'status' );
$lang = 'en' === ( $args[1] ?? 'tr' ) ? 'en' : 'tr';
$data = require __DIR__ . '/data.php';

function slotiva_demo_product_ids(): array {
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => '_slotiva_demo',
				'meta_value'  => '1',
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		)
	);
}

function slotiva_demo_order_ids(): array {
	return wc_get_orders(
		array(
			'limit'      => -1,
			'return'     => 'ids',
			'status'     => array_keys( wc_get_order_statuses() ),
			'meta_key'   => '_slotiva_demo',
			'meta_value' => '1',
		)
	);
}

function slotiva_demo_count( array $ids ): int {
	global $wpdb;
	if ( ! $ids ) {
		return 0;
	}
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'reservations' ) . ' WHERE product_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' );
}

function slotiva_demo_locale( array $data, string $lang ): void {
	$g = $data['gateways'][ $lang ];
	update_option( 'woocommerce_currency', $data['site'][ $lang ]['currency'] );
	update_option(
		'woocommerce_bacs_settings',
		array_merge( (array) get_option( 'woocommerce_bacs_settings', array() ), array( 'enabled' => 'yes', 'title' => $g['bacs']['title'], 'description' => $g['bacs']['description'] ) )
	);
	update_option(
		'woocommerce_cod_settings',
		array_merge( (array) get_option( 'woocommerce_cod_settings', array() ), array( 'enabled' => 'yes', 'title' => $g['cod']['title'], 'description' => $g['cod']['description'] ) )
	);
}

/**
 * Yalniz EN demo site saat dilimini degistirir (data.php 'timezone'); onceki deger `slotiva_demo_prev_tz`te ilk seferde saklanir.
 * TR modu (ve --reset) saklanmis degeri geri yukler ve saklama secenegini siler.
 */
function slotiva_demo_timezone( array $data, string $lang ): void {
	$target = $data['timezone'][ $lang ] ?? null;
	if ( $target ) {
		add_option( 'slotiva_demo_prev_tz', array( 'string' => (string) get_option( 'timezone_string', '' ), 'offset' => get_option( 'gmt_offset', 0 ) ), '', false );
		update_option( 'timezone_string', $target );
		update_option( 'gmt_offset', '' );
		return;
	}
	$prev = get_option( 'slotiva_demo_prev_tz', null );
	if ( is_array( $prev ) ) {
		update_option( 'timezone_string', $prev['string'] );
		update_option( 'gmt_offset', $prev['offset'] );
		delete_option( 'slotiva_demo_prev_tz' );
	}
}

/** Yalniz isaretli demo nesneleri siler. */
function slotiva_demo_purge(): void {
	global $wpdb;
	$ids = slotiva_demo_product_ids();
	foreach ( slotiva_demo_order_ids() as $oid ) {
		$o = wc_get_order( $oid );
		if ( $o ) {
			$o->delete( true ); // PaymentListener::onOrderRemoved etkin satirlari birakir.
		}
	}
	if ( $ids ) {
		$in  = implode( ',', array_map( 'intval', $ids ) );
		$res = Schema::table( 'reservations' );
		$rid = $wpdb->get_col( "SELECT id FROM $res WHERE product_id IN ($in)" );
		if ( $rid ) {
			$rin = implode( ',', array_map( 'intval', $rid ) );
			$wpdb->query( 'DELETE FROM ' . Schema::table( 'occupancy' ) . " WHERE reservation_id IN ($rin)" );
			$wpdb->query( 'DELETE FROM ' . Schema::table( 'events' ) . " WHERE reservation_id IN ($rin)" );
			$wpdb->query( "DELETE FROM $res WHERE id IN ($rin)" );
		}
		foreach ( $ids as $pid ) {
			wp_delete_post( $pid, true );
		}
	}
	$wpdb->query( 'DELETE FROM ' . Schema::table( 'exceptions' ) . " WHERE note LIKE '[demo]%'" );
}

if ( 'restore' === $mode ) {
	$pp = get_option( 'slotiva_demo_prev_policy', null );
	if ( is_array( $pp ) ) {
		Rules::save( array( 'policyText' => $pp['value'] ) );
		delete_option( 'slotiva_demo_prev_policy' );
		echo "policy restored\n";
	}
	$prev = get_option( 'slotiva_demo_prev_weekly', null );
	if ( is_array( $prev ) ) {
		if ( $prev['exists'] ) {
			update_option( WeeklySchedule::OPTION, $prev['value'], false );
		} else {
			delete_option( WeeklySchedule::OPTION );
		}
		do_action( 'slotiva_schedule_changed' );
		delete_option( 'slotiva_demo_prev_weekly' );
		echo "weekly hours restored
";
	}
	return;
}

if ( 'locale' === $mode ) {
	slotiva_demo_locale( $data, $lang );
	slotiva_demo_timezone( $data, $lang );
	echo "locale ok\n";
	return;
}

$ids      = slotiva_demo_product_ids();
$cur_lang = $ids ? (string) get_post_meta( $ids[0], '_slotiva_demo_lang', true ) : '';
$complete = count( $ids ) === $data['expected']['products'] && slotiva_demo_count( $ids ) === $data['expected']['total'] && count( slotiva_demo_order_ids() ) === $data['expected']['orders'];

if ( 'status' === $mode ) {
	echo ( $complete && $cur_lang === $lang ) ? "ok\n" : "needs-seed\n";
	return;
}
if ( ! in_array( $mode, array( 'seed', 'rebuild' ), true ) ) {
	WP_CLI::error( 'bilinmeyen mod: ' . $mode );
}
if ( 'seed' === $mode && $complete && $cur_lang === $lang ) {
	echo "Demo veri zaten var ({$lang}); degisiklik yok.\n";
	return;
}

// --- Kurulum ------------------------------------------------------------------------------------------------------
add_filter( 'pre_wp_mail', '__return_true' ); // Demo kurulumu e-posta gondermez (Mailpit'e de dusmez).

slotiva_demo_purge();
slotiva_demo_locale( $data, $lang );

$tz    = wp_timezone();
$today = new DateTimeImmutable( 'today', $tz );
$now   = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
$notes = $data['notes'][ $lang ];

// Calisma saatleri + istisnalar (eklentinin kendi depolari).
// Tek R32 istisnasi: onceki saatler yalniz ilk seferde saklanir (add_option var olani ezmez).
add_option( 'slotiva_demo_prev_weekly', array( 'exists' => null !== get_option( WeeklySchedule::OPTION, null ), 'value' => get_option( WeeklySchedule::OPTION, null ) ), '', false );
$r = WeeklySchedule::save( $data['weekly'] );
if ( is_wp_error( $r ) ) {
	WP_CLI::error( 'haftalik duzen: ' . $r->get_error_message() );
}
$next_weekday = static function ( int $weekday, int $min_offset ) use ( $today ): DateTimeImmutable {
	$d = $today->modify( "+{$min_offset} days" );
	while ( (int) $d->format( 'N' ) !== $weekday ) {
		$d = $d->modify( '+1 day' );
	}
	return $d;
};
$extra  = $next_weekday( $data['extra_open']['weekday'], $data['extra_open']['min_offset'] );
$closed = $next_weekday( $data['closed_day']['weekday'], $data['closed_day']['min_offset'] );
foreach (
	array(
		array( 'date' => $extra->format( 'Y-m-d' ), 'type' => 'open', 'from_time' => $data['extra_open']['from'], 'to_time' => $data['extra_open']['to'], 'note' => $notes['open'] ),
		array( 'date' => $closed->format( 'Y-m-d' ), 'type' => 'closed', 'note' => $notes['closed'] ),
	) as $exc
) {
	$added = ExceptionRepository::add( $exc );
	if ( is_wp_error( $added ) ) {
		WP_CLI::error( 'istisna: ' . $added->get_error_message() );
	}
}

// Genel iptal politikasi (demo modu): onceki metin yalniz ilk seferde saklanir; --reset geri yukler.
add_option( 'slotiva_demo_prev_policy', array( 'value' => Rules::load()->policyText ), '', false );
$r = Rules::save( array( 'policyText' => $data['policy'][ $lang ] ) );
if ( is_wp_error( $r ) ) {
	WP_CLI::error( 'politika: ' . $r->get_error_message() );
}

// Urunler.
$products = array();
foreach ( $data['services'] as $key => $svc ) {
	$t = $svc[ $lang ];
	$p = new WC_Product_Simple();
	$p->set_name( $t['name'] );
	$p->set_status( 'publish' );
	$p->set_virtual( true );
	$p->set_regular_price( $t['price'] );
	$p->set_short_description( $t['desc'] );
	$p->update_meta_data( '_slotiva_enabled', 'yes' );
	$p->update_meta_data( '_slotiva_duration', $svc['duration'] );
	$p->update_meta_data( '_slotiva_min_slots', $svc['min'] );
	$p->update_meta_data( '_slotiva_max_slots', $svc['max'] );
	$p->update_meta_data( '_slotiva_pricing', $svc['pricing'] );
	$p->update_meta_data( '_slotiva_location', $t['location'] );
	$p->update_meta_data( '_slotiva_policy', $t['policy'] );
	$p->update_meta_data( '_slotiva_demo', '1' );
	$p->update_meta_data( '_slotiva_demo_lang', $lang );
	$p->save();
	$products[ $key ] = wc_get_product( $p->get_id() );
}

// Slot secici: hedef gunden yone dogru (gelecek +, gecmis -) ilk uygun, bos baslangic.
global $wpdb;
$res_t = Schema::table( 'reservations' );
$busy  = array();
foreach ( (array) $wpdb->get_results( "SELECT starts_at, ends_at FROM $res_t WHERE status IN ('held','confirmed')", ARRAY_A ) as $row ) {
	$busy[] = array( strtotime( $row['starts_at'] . ' UTC' ), strtotime( $row['ends_at'] . ' UTC' ) );
}
$weekly = WeeklySchedule::load();
$pick   = static function ( array $b, int $duration ) use ( &$busy, $today, $now, $tz, $weekly, $extra ): DateTimeImmutable {
	$past = $b['offset'] < 0;
	$dir  = $past ? -1 : 1;
	$lead = $now->getTimestamp() + ( ! empty( $b['manual'] ) ? 3600 : 25 * HOUR_IN_SECONDS );
	for ( $step = 0; $step < 14; $step++ ) {
		$day = ( isset( $b['on'] ) && 'extra' === $b['on'] ) ? $extra->modify( ( $step * $dir ) . ' days' ) : $today->modify( ( $b['offset'] + $step * $dir ) . ' days' );
		$off = (int) $today->diff( $day )->format( '%r%a' );
		if ( $off > 13 || $off < -14 ) {
			continue;
		}
		$grid = SlotCalculator::gridStarts( $duration, $day, $weekly, ExceptionRepository::between( $day->format( 'Y-m-d' ), $day->format( 'Y-m-d' ) ) );
		$n    = count( $grid );
		for ( $k = 0; $k < $n; $k++ ) {
			$s  = $grid[ ( $b['idx'] + $k ) % $n ];
			$ts = $s->getTimestamp();
			$te = $ts + $duration * 60;
			if ( $past ? $te > $now->getTimestamp() - 3600 : $ts < $lead ) {
				continue;
			}
			foreach ( $busy as $iv ) {
				if ( $ts < $iv[1] && $te > $iv[0] ) {
					continue 2;
				}
			}
			$busy[] = array( $ts, $te );
			return $s;
		}
	}
	WP_CLI::error( 'uygun saat bulunamadi: ' . wp_json_encode( $b ) );
};

// CLI'de gercek bir ziyaretci oturumu yok; paylasilan bos oturum kimligi oturum kotasina (HOLD_QUOTA) takilmasin.
WC()->session = null;
$gate = $data['gateways'][ $lang ];
$cust = $data['customer'][ $lang ];
$made = array();

foreach ( $data['bookings'] as $i => $b ) {
	$n    = $i + 1;
	$svc  = $data['services'][ $b['svc'] ];
	$prod = $products[ $b['svc'] ];
	$kind = $b['kind'];
	$name = array( $cust['first'], sprintf( $cust['last'], $n ) );
	$mail = sprintf( $cust['email'], $n );
	$tel  = sprintf( $data['phone'][ $lang ], $n );
	$slot = $pick( $b + array( 'manual' => 'manual' === $kind ), $svc['duration'] );
	$utc  = $slot->setTimezone( new DateTimeZone( 'UTC' ) );

	if ( 'manual' === $kind ) {
		// Yonetici REST rotasiyla (create_appointment) ayni dogrulama ve ayni depo cagrisi.
		if ( ! SlotCalculator::isAdminBookable( $svc['duration'], $utc ) ) {
			WP_CLI::error( 'elle ekleme icin saat uygun degil' );
		}
		$ids = ReservationRepository::claim(
			array(
				'product_id'    => $prod->get_id(),
				'product_name'  => $prod->get_name(),
				'variation_id'  => 0,
				'order_id'      => null,
				'slots'         => array( $utc ),
				'duration'      => $svc['duration'],
				'buffer'        => Rules::load()->bufferMin,
				'status'        => 'confirmed',
				'payment_state' => 'manual',
				'customer'      => array( 'name' => implode( ' ', $name ), 'phone' => $tel, 'email' => $mail ),
				'location'      => (string) $prod->get_meta( '_slotiva_location' ),
				'notify'        => false,
			)
		);
		$made[] = $ids[0];
		continue;
	}

	// Siparis akisi. Havale/iptal/sure dolumu: bacs; kart: 'card'; isletmede odeme: cod.
	$pay    = 'held' === $kind || 'cancel_cust' === $kind || 'expired' === $kind ? 'bacs' : ( 'cod' === $kind ? 'cod' : ( $b['pay'] ?? 'card' ) );
	$frozen = null;
	if ( 'expired' === $kind || $b['offset'] < 0 ) {
		$frozen = min( $now->modify( '-2 days' )->getTimestamp(), $utc->modify( '-2 days' )->getTimestamp() );
	}
	$t0 = new DateTimeImmutable( '@' . ( $frozen ?? $now->getTimestamp() ) );

	$order = wc_create_order( array( 'status' => 'pending' ) );
	$order->set_created_via( 'slotiva-demo' );
	$order->set_date_created( $t0->getTimestamp() );
	$order->set_billing_first_name( $name[0] );
	$order->set_billing_last_name( $name[1] );
	$order->set_billing_email( $mail );
	$order->set_billing_phone( $tel );
	$order->set_billing_country( 'tr' === $lang ? 'TR' : 'US' );
	$order->set_payment_method( 'card' === $pay ? 'stripe' : $pay );
	$order->set_payment_method_title( 'card' === $pay ? $gate['card'] : $gate[ $pay ]['title'] );
	$order->set_currency( get_woocommerce_currency() );
	$item = new WC_Order_Item_Product();
	$item->set_product( $prod );
	$item->set_quantity( 1 );
	$item->set_subtotal( $prod->get_regular_price() );
	$item->set_total( $prod->get_regular_price() );
	$order->add_item( $item );
	$order->update_meta_data( '_slotiva_demo', '1' );
	$order->update_meta_data( CheckoutFlow::META_REQUESTED, array( SlotValidator::iso( $utc ) ) );
	$order->calculate_totals( false );
	$order->save();

	// Her musteri ayri ziyaretci: IP basina havale kotasi (HOLD_QUOTA) tek kisilik demo'yu yanlis engellemesin (RFC 5737 belge adresleri).
	$_SERVER['REMOTE_ADDR'] = '192.0.2.' . $n;
	if ( $frozen ) {
		Clock::freeze( $t0 );
	}
	try {
		CheckoutFlow::claim( $order ); // Odeme akisiyla ayni alma (dogrulayici + depo).
		$order = wc_get_order( $order->get_id() );
		if ( 'bacs' === $pay ) {
			$order->update_status( 'on-hold' ); // PaymentListener::onTransfer: awaiting_transfer + havale suresi.
		} elseif ( 'cod' === $pay ) {
			$order->update_status( 'processing' ); // Satir zaten confirmed + on_site.
		} else {
			$order->payment_complete( 'DEMO-' . $n ); // PaymentListener::onPaid: held -> confirmed + paid.
		}
		if ( 'cancel_cust' === $kind ) {
			$order->update_status( 'cancelled' ); // PaymentListener::onCancelled birimleri birakir.
		}
	} catch ( Throwable $e ) {
		WP_CLI::error( sprintf( 'booking #%d (%s/%s) %s: %s', $n, $b['svc'], $kind, $slot->format( 'c' ), $e->getMessage() ) );
	} finally {
		Clock::freeze( null );
	}

	$rows = ReservationRepository::forOrder( $order->get_id() );
	$row  = $rows[0] ?? null;
	if ( ! $row ) {
		WP_CLI::error( "rezervasyon olusmadi (siparis #{$order->get_id()})" );
	}
	$ctx = array( 'notify' => false, 'reason' => 'admin' );
	switch ( $kind ) {
		case 'held':
			HoldScheduler::extend( $order->get_id(), $utc ); // Tutma randevu saatine kadar: demo "bekliyor" gunlerce kalir.
			break;
		case 'cancel_admin':
			ReservationRepository::release( $row->id, 'cancelled', $ctx + array( 'expect_status' => array( 'held', 'confirmed', 'conflict' ) ) ); // REST set_status ile ayni.
			break;
		case 'completed':
			ReservationRepository::setStatus( $row->id, 'completed', null, $ctx + array( 'expect_status' => array( 'confirmed' ) ) );
			wc_get_order( $order->get_id() )->update_status( 'completed' );
			break;
		case 'no_show':
			ReservationRepository::setStatus( $row->id, 'no_show', null, $ctx + array( 'expect_status' => array( 'confirmed' ) ) );
			break;
		case 'expired':
			// Eklentinin kendi sure dolumu isleyicisi (Action Scheduler isinin cagirdigi); tutma suresi gecmistir.
			HoldScheduler::expire( $row->id, $row->sequence, $row->holdExpiresAt->format( 'Y-m-d H:i:s' ) );
			break;
	}
	$made[] = $row->id;
}

wp_cache_flush();

global $wpdb;
$by = $wpdb->get_results( 'SELECT status, payment_state, COUNT(*) c FROM ' . $res_t . ' WHERE product_id IN (' . implode( ',', array_map( fn( $p ) => $p->get_id(), $products ) ) . ') GROUP BY status, payment_state ORDER BY status, payment_state', ARRAY_A );
echo "Demo veri kuruldu ({$lang}): " . count( $made ) . " rezervasyon.\n";
foreach ( $by as $row ) {
	echo "  {$row['status']} / {$row['payment_state']}: {$row['c']}\n";
}
echo "  ek acik gun: {$extra->format( 'Y-m-d' )}; izin: {$closed->format( 'Y-m-d' )}\n";
