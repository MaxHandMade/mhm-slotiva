<?php
/**
 * Demo veri tanimi (docker/demo-seed.sh). Yalniz yerel gelistirme yigini icin; ZIP'e girmez.
 *
 * Hedef kitle: tek kisilik isletme (Lite). Calisan/kapasite/coklu kaynak gosterilmez.
 * Tarihler bugune gore GORECELIDIR (offset gun); seed.php en yakin uygun bos saati bulur.
 * Rezervasyonlar tablosunda meta yoktur: demo rezervasyon = demo urune bagli satir (urunler `_slotiva_demo` = 1 isaretli).
 */

// Sure 15 dk adimina uymali (Rules::STEP); 50 dk seans desteklenmez, psikolog seansi 45 dk.
$day = array(
	array( 'from' => '09:00', 'to' => '12:30' ),
	array( 'from' => '13:30', 'to' => '18:00' ),
);

return array(
	// Calisma saatleri: Pzt-Cum ogle arasi var, Cmt-Paz kapali (0 = Pazartesi).
	'weekly'      => array( 0 => $day, 1 => $day, 2 => $day, 3 => $day, 4 => $day, 5 => array(), 6 => array() ),

	// Istisnalar: ilk uygun Cumartesi ek acik gun, ilk uygun Pazartesi izin (kapali). note `[demo]` ile baslar (isaret).
	'extra_open'  => array(
		'weekday'    => 6, // ISO gun: 6 = Cumartesi.
		'min_offset' => 7,
		'from'       => '10:00',
		'to'         => '14:00',
	),
	'closed_day'  => array(
		'weekday'    => 1, // 1 = Pazartesi.
		'min_offset' => 16,
	),
	'notes'       => array(
		'tr' => array(
			'open'   => '[demo] Ek çalışma günü',
			'closed' => '[demo] Yıllık izin',
		),
		'en' => array(
			'open'   => '[demo] Extra working day',
			'closed' => '[demo] Annual leave',
		),
	),

	'customer'    => array(
		'tr' => array(
			'first' => 'Örnek',
			'last'  => 'Müşteri %d',
			'email' => 'ornek%d@example.test',
		),
		'en' => array(
			'first' => 'Sample',
			'last'  => 'Customer %d',
			'email' => 'sample%d@example.test',
		),
	),
	'phone'       => array(
		'tr' => '0555 000 00 %02d',
		'en' => '(555) 010-00%02d', // Kurgusal ABD numaralari (555-01xx ayrilmis araligi).
	),
	'policy'      => array(
		'tr' => 'Randevudan 24 saat öncesine kadar ücretsiz iptal.',
		'en' => 'Free cancellation up to 24 hours before.',
	),
	'timezone'    => array(
		'en' => 'Europe/London', // Yalniz EN demo: TR modu site saat dilimine dokunmaz.
	),

	'gateways'    => array(
		'tr' => array(
			'bacs' => array(
				'title'       => 'Havale/EFT',
				'description' => 'Banka havalesi ile ödeme.',
			),
			'cod'  => array(
				'title'       => 'İşletmede ödeme',
				'description' => 'Randevuda yerinde ödeme.',
			),
			'card' => 'Kredi kartı',
		),
		'en' => array(
			'bacs' => array(
				'title'       => 'Bank transfer',
				'description' => 'Pay by bank transfer.',
			),
			'cod'  => array(
				'title'       => 'Pay on site',
				'description' => 'Pay at your appointment.',
			),
			'card' => 'Credit card',
		),
	),
	'site'        => array(
		'tr' => array(
			'locale'   => 'tr_TR',
			'currency' => 'TRY',
		),
		'en' => array(
			'locale'   => 'en_US',
			'currency' => 'USD',
		),
	),

	'services'    => array(
		'psych'   => array(
			'duration' => 45,
			'min'      => 1,
			'max'      => 2,
			'pricing'  => 'per_slot',
			'tr'       => array(
				'name'     => 'Psikolog seansı (45 dk)',
				'price'    => '1500',
				'desc'     => 'Bireysel görüşme, seans başına ücret.',
				'location' => 'Örnek Muayenehane, Kadıköy / İstanbul',
				'policy'   => 'Randevudan 24 saat öncesine kadar ücretsiz iptal.',
			),
			'en'       => array(
				'name'     => 'Counselling session (45 min)',
				'price'    => '80',
				'desc'     => 'One-to-one session, priced per session.',
				'location' => 'Sample Practice, 1 Example Street',
				'policy'   => 'Free cancellation up to 24 hours before.',
			),
		),
		'consult' => array(
			'duration' => 60,
			'min'      => 1,
			'max'      => 1,
			'pricing'  => 'fixed',
			'tr'       => array(
				'name'     => 'Danışmanlık görüşmesi (60 dk)',
				'price'    => '2500',
				'desc'     => 'Sabit ücretli tek görüşme.',
				'location' => 'Örnek Ofis, Kadıköy / İstanbul',
				'policy'   => 'Randevudan 24 saat öncesine kadar ücretsiz iptal.',
			),
			'en'       => array(
				'name'     => 'Consultation (60 min)',
				'price'    => '140',
				'desc'     => 'Single meeting at a fixed price.',
				'location' => 'Sample Office, 1 Example Street',
				'policy'   => 'Free cancellation up to 24 hours before.',
			),
		),
		'lesson'  => array(
			'duration' => 45,
			'min'      => 1,
			'max'      => 3,
			'pricing'  => 'per_slot',
			'tr'       => array(
				'name'     => 'Özel ders (45 dk)',
				'price'    => '600',
				'desc'     => 'Ders başına ücret; aynı siparişte birden çok ders seçilebilir.',
				'location' => 'Örnek Çalışma Odası, Kadıköy / İstanbul',
				'policy'   => 'Dersten 12 saat öncesine kadar ücretsiz iptal.',
			),
			'en'       => array(
				'name'     => 'Private lesson (45 min)',
				'price'    => '35',
				'desc'     => 'Priced per lesson; choose several lessons in one order.',
				'location' => 'Sample Study Room, 1 Example Street',
				'policy'   => 'Free cancellation up to 12 hours before.',
			),
		),
		'dental'  => array(
			'duration' => 30,
			'min'      => 1,
			'max'      => 1,
			'pricing'  => 'fixed',
			'tr'       => array(
				'name'     => 'Diş kontrolü (30 dk)',
				'price'    => '900',
				'desc'     => 'Muayene ve kontrol; ödeme işletmede.',
				'location' => 'Örnek Diş Kliniği, Kadıköy / İstanbul',
				'policy'   => 'Randevuya gelemeyecekseniz lütfen önceden haber verin.',
			),
			'en'       => array(
				'name'     => 'Dental check-up (30 min)',
				'price'    => '50',
				'desc'     => 'Examination and check-up; pay at the practice.',
				'location' => 'Sample Dental Practice, 1 Example Street',
				'policy'   => 'Please let us know in advance if you cannot attend.',
			),
		),
	),

	/*
	 * Rezervasyonlar. kind:
	 *   held         havale, tutuluyor (held + awaiting_transfer; tutma randevu saatine kadar uzatilir)
	 *   card         kart, odendi (confirmed + paid)
	 *   cod          isletmede ode (confirmed + on_site)
	 *   manual       yonetici elle ekledi (confirmed + manual, siparis yok)
	 *   cancel_admin kart odendi, yonetici iptal etti (cancelled)
	 *   completed    gecmis, tamamlandi (pay: card|cod)
	 *   no_show      gecmis, gelmedi (pay: card|cod)
	 *   cancel_cust  havale siparisi iptal edildi (cancelled)
	 *   expired      havale suresi doldu (expired; HoldScheduler::expire ile)
	 * offset: bugune gore gun; idx: o gunun uygun saatleri arasinda baslangic sirasi; on=extra: ek acik gunde.
	 */
	'bookings'    => array(
		array( 'svc' => 'psych',   'kind' => 'held',         'offset' => 2,  'idx' => 0 ),
		array( 'svc' => 'consult', 'kind' => 'held',         'offset' => 3,  'idx' => 1 ),
		array( 'svc' => 'lesson',  'kind' => 'held',         'offset' => 4,  'idx' => 2 ),
		array( 'svc' => 'psych',   'kind' => 'card',         'offset' => 2,  'idx' => 3 ),
		array( 'svc' => 'consult', 'kind' => 'card',         'offset' => 5,  'idx' => 0 ),
		array( 'svc' => 'lesson',  'kind' => 'card',         'offset' => 6,  'idx' => 1, 'on' => 'extra' ),
		array( 'svc' => 'dental',  'kind' => 'cod',          'offset' => 3,  'idx' => 0 ),
		array( 'svc' => 'dental',  'kind' => 'cod',          'offset' => 4,  'idx' => 4 ),
		array( 'svc' => 'dental',  'kind' => 'cod',          'offset' => 7,  'idx' => 2 ),
		array( 'svc' => 'consult', 'kind' => 'manual',       'offset' => 8,  'idx' => 2 ),
		array( 'svc' => 'psych',   'kind' => 'cancel_admin', 'offset' => 9,  'idx' => 1 ),
		array( 'svc' => 'psych',   'kind' => 'completed',    'offset' => -3, 'idx' => 1, 'pay' => 'card' ),
		array( 'svc' => 'dental',  'kind' => 'completed',    'offset' => -5, 'idx' => 0, 'pay' => 'cod' ),
		array( 'svc' => 'consult', 'kind' => 'completed',    'offset' => -9, 'idx' => 2, 'pay' => 'card' ),
		array( 'svc' => 'dental',  'kind' => 'no_show',      'offset' => -6, 'idx' => 3, 'pay' => 'cod' ),
		array( 'svc' => 'lesson',  'kind' => 'cancel_cust',  'offset' => -4, 'idx' => 1 ),
		array( 'svc' => 'psych',   'kind' => 'expired',      'offset' => -7, 'idx' => 0 ),
		array( 'svc' => 'lesson',  'kind' => 'expired',      'offset' => 3,  'idx' => 3 ),
	),

	// Beklenen sayilar (bookings listesiyle tutarli olmali; assert betigi bunlari DB'ye karsi sayar).
	'expected'    => array(
		'total'         => 18,
		'status'        => array(
			'held'      => 3,
			'confirmed' => 7,
			'completed' => 3,
			'no_show'   => 1,
			'cancelled' => 2,
			'expired'   => 2,
		),
		'confirmed_pay' => array(
			'paid'    => 3,
			'on_site' => 3,
			'manual'  => 1,
		),
		'orders'        => 17, // manual siparissizdir.
		'products'      => 4,
	),
);
