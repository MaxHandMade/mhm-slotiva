<?php
/**
 * Deterministik müsaitlik tohumu: 1.0.3 kapasite-1 davranışının kayıt/karşılaştırma fikstürü (R3) için.
 *
 * Belirlilik: saat dondurulur (Clock), saat dilimi sabit, hedef gün ve tüm rezervasyonlar sabit; `time()`'a bakılmaz.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Support;

use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Schedule\ExceptionRepository;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Schedule\WeeklySchedule;
use MHM\Slotiva\Support\Clock;

final class AvailabilityFixtureSeed {

	/** Sabit "şimdi" (UTC): hedef günden bir hafta önce. */
	public const NOW_UTC = '2031-07-07T06:00:00Z';

	/** Hedef gün: Pazartesi, Europe/Berlin (UTC+02:00). */
	public const TARGET_DAY = '2031-07-14';

	/** Aralık sonu. */
	public const RANGE_TO = '2031-07-20';

	/** Sabit site saat dilimi. */
	public const TIMEZONE = 'Europe/Berlin';

	/**
	 * Saati dondurur, saat dilimini/kuralları/haftalık düzeni/istisnayı kurar, ürünü ve rezervasyonları oluşturur.
	 * Seçenekleri geri almak SlotivaTestCase'in işidir.
	 *
	 * @return int Ürün kimliği.
	 */
	public static function seed(): int {
		update_option( 'timezone_string', self::TIMEZONE );
		update_option( 'gmt_offset', 0 );
		Clock::freeze( new \DateTimeImmutable( self::NOW_UTC ) );

		// Pzt-Cum: 09:00-12:00 ve öğle arası sonrası 13:00-17:00.
		$day = array(
			array(
				'from' => '09:00',
				'to'   => '12:00',
			),
			array(
				'from' => '13:00',
				'to'   => '17:00',
			),
		);
		WeeklySchedule::save(
			array(
				0 => $day,
				1 => $day,
				2 => $day,
				3 => $day,
				4 => $day,
			)
		);
		Rules::save(
			array(
				'leadHours'   => 2,
				'horizonDays' => 62,
				'bufferMin'   => 15,
			)
		);
		// Çarşamba tam gün kapalı.
		ExceptionRepository::add(
			array(
				'date' => '2031-07-16',
				'type' => 'closed',
				'note' => 'fixture',
			)
		);

		$p = new \WC_Product_Simple();
		$p->set_name( 'Fixture Danışmanlık' );
		$p->set_regular_price( '100' );
		$p->set_status( 'publish' );
		$p->update_meta_data( '_slotiva_enabled', 'yes' );
		$p->update_meta_data( '_slotiva_duration', '45' );
		$p->update_meta_data( '_slotiva_horizon_days', '62' );
		$pid = $p->save();

		// Yerel (Berlin) 10:00 held, 14:00 confirmed, 11:00 iptal (engellememeli), ertesi gün 09:00 held.
		self::claim( $pid, 9001, '2031-07-14 08:00:00', 'held' );
		self::claim( $pid, 9002, '2031-07-14 12:00:00', 'confirmed' );
		$cancelled = self::claim( $pid, 9003, '2031-07-14 09:00:00', 'held' );
		self::claim( $pid, 9004, '2031-07-15 07:00:00', 'held' );
		ReservationRepository::release( $cancelled, 'cancelled' );

		return $pid;
	}

	/**
	 * Tek dilimlik rezervasyon alır.
	 *
	 * @param int    $pid      Ürün.
	 * @param int    $order    Sipariş kimliği.
	 * @param string $start_utc UTC başlangıç.
	 * @param string $status   held|confirmed.
	 * @return int Rezervasyon kimliği.
	 */
	private static function claim( int $pid, int $order, string $start_utc, string $status ): int {
		$utc = new \DateTimeZone( 'UTC' );
		$ids = ReservationRepository::claim(
			array(
				'product_id'      => $pid,
				'product_name'    => 'Fixture Danışmanlık',
				'order_id'        => $order,
				'order_item_id'   => $order,
				'slots'           => array( new \DateTimeImmutable( $start_utc, $utc ) ),
				'duration'        => 45,
				'buffer'          => 15,
				'status'          => $status,
				'payment_state'   => 'confirmed' === $status ? 'paid' : 'pending',
				'hold_expires_at' => 'held' === $status ? new \DateTimeImmutable( '2031-07-08 12:00:00', $utc ) : null,
				'customer'        => array(
					'name'  => 'Fixture Müşteri',
					'email' => 'fixture@example.test',
					'phone' => '5550000000',
				),
				'notify'          => false,
			)
		);
		return $ids[0];
	}
}
