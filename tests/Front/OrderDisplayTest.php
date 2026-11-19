<?php
/**
 * Sipariş sonrası randevu kartı (spec §6.5, kare 4), korumalı `.ics` ucu, gizli kalem metası ve yönetici "Randevu" kutusu.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Front;

use MHM\Slotiva\Checkout\CheckoutFlow;
use MHM\Slotiva\Front\OrderDisplay;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\LifecycleFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation alanları sözleşmeyle camelCase.

class OrderDisplayTest extends SlotivaTestCase {

	use CheckoutFixtures;
	use LifecycleFixtures;

	/**
	 * Saat biçimi yedeği (SlotivaTestCase yalnız slotiva_* seçeneklerini geri yükler).
	 *
	 * @var mixed
	 */
	private $time_format_backup = '';

	public function set_up(): void {
		parent::set_up();
		$this->set_up_checkout();
		$this->set_up_lifecycle();
		$this->time_format_backup = get_option( 'time_format' );
		update_option( 'time_format', 'H:i' ); // Türkiye sitesi biçimi.
	}

	public function tear_down(): void {
		update_option( 'time_format', $this->time_format_backup );
		$this->tear_down_lifecycle();
		$this->tear_down_checkout();
		parent::tear_down();
	}

	/**
	 * Kartın çıktısı.
	 *
	 * @param int $order_id Sipariş.
	 */
	private function card( int $order_id ): string {
		ob_start();
		OrderDisplay::render( $this->order( $order_id ) );
		return (string) ob_get_clean();
	}

	/**
	 * Konum anlık kopyasını yazar (booked_order konumsuz alır; gerçek akış `CheckoutFlow::claim` ile yazar).
	 *
	 * @param int    $id       Rezervasyon.
	 * @param string $location Konum.
	 */
	private function set_location( int $id, string $location ): void {
		global $wpdb;
		$wpdb->update( \MHM\Slotiva\Db\Schema::table( 'reservations' ), array( 'location_text' => $location ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test: anlık kopya.
	}

	public function test_card_renders_for_confirmed(): void {
		list( $oid, $ids, $pid ) = $this->booked_order( 'cod', 'confirmed', 'on_site', null );
		$this->set_location( $ids[0], 'Atölye, Kadıköy' );
		update_post_meta( $pid, '_slotiva_policy', 'Cancellations need 24 hours notice.' );
		$r = $this->row( $ids[0] );

		$html = $this->card( $oid );
		$this->assertStringContainsString( 'slotiva-appointment', $html );
		$this->assertStringContainsString( '10:00–10:30', $html, 'saat aralığı' );
		$this->assertStringContainsString( 'November 17, 2026', $html, 'tarih' );
		$this->assertStringContainsString( 'Europe/Istanbul', $html, 'saat dilimi adı' );
		$this->assertStringContainsString( 'GMT+3', $html );
		$this->assertStringContainsString( 'Atölye, Kadıköy', $html, 'yer' );
		$this->assertStringContainsString( 'slotiva_ics=' . $r->token, $html, '.ics bağlantısı' );
		$this->assertStringContainsString( 'Google Calendar<span class="screen-reader-text"> (opens in a new tab)</span></a>', $html, 'yeni sekme ekran okuyucuya söylenir' );
		$this->assertStringContainsString( 'key=' . $this->order( $oid )->get_order_key(), $html, 'sipariş anahtarı' );
		$this->assertStringContainsString( 'https://calendar.google.com/calendar/render?action=TEMPLATE', $html, 'Google Takvim' );
		$this->assertStringContainsString( '20261117T070000Z%2F20261117T073000Z', $html, 'Google Takvim UTC aralığı' );
		$this->assertStringNotContainsString( 'Awaiting payment', $html );
		$this->assertStringContainsString( 'Cancellations need', $html, 'ürün politikası' );
	}

	/**
	 * Task 17: ön yüz şablonu `<style>` basmaz; kart stili `slotiva-appointment` tutamacına satır içi eklenir ve kart
	 * basılınca kuyruğa alınır (WP.org: satır içi stil yalnız e-posta şablonlarında).
	 */
	public function test_card_style_is_enqueued_not_printed(): void {
		list( $oid ) = $this->booked_order( 'cod', 'confirmed', 'on_site', null );
		wp_dequeue_style( OrderDisplay::STYLE_HANDLE );
		wp_deregister_style( OrderDisplay::STYLE_HANDLE );

		$html = $this->card( $oid );
		$this->assertStringContainsString( 'slotiva-appointment__card', $html, 'kart basıldı' );
		$this->assertStringNotContainsString( '<style', $html, 'şablon stil basmaz' );
		$this->assertTrue( wp_style_is( OrderDisplay::STYLE_HANDLE, 'enqueued' ), 'stil kuyrukta' );
		$after = (array) wp_styles()->get_data( OrderDisplay::STYLE_HANDLE, 'after' );
		$this->assertStringContainsString( '.slotiva-appointment__card{', implode( "\n", $after ), 'kart CSS satır içi eklendi' );
		$this->assertFalse( wp_styles()->registered[ OrderDisplay::STYLE_HANDLE ]->src, 'dosyasız tutamaç' );

		// İkinci kart (aynı istek): CSS bir kez eklenir.
		$this->card( $oid );
		$this->assertCount( 1, (array) wp_styles()->get_data( OrderDisplay::STYLE_HANDLE, 'after' ) );
	}

	/**
	 * (Final review M7) Sipariş alındı / siparişi görüntüle sayfalarında stil `wp_enqueue_scripts`te (baş bölümde) kuyruğa girer;
	 * başka sayfada girmez; sonradan kart basılsa da tek kopya kalır.
	 */
	public function test_card_style_is_enqueued_in_head_on_order_pages_only(): void {
		list( $oid ) = $this->booked_order( 'cod', 'confirmed', 'on_site', null );
		// Küresel stil kaydı önceki testlerden kalıntı taşıyabilir: kendi durumumuzla çalış, sonunda geri koy.
		$saved_styles         = $GLOBALS['wp_styles'] ?? null;
		$GLOBALS['wp_styles'] = new \WP_Styles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test kendi küresel kaydını kurar ve geri koyar.
		$reset                = static function (): void {
			wp_dequeue_style( OrderDisplay::STYLE_HANDLE );
			wp_deregister_style( OrderDisplay::STYLE_HANDLE );
		};

		try {
			$reset();
			OrderDisplay::enqueueStyle();
			$this->assertTrue( wp_style_is( OrderDisplay::STYLE_HANDLE, 'registered' ) );
			$this->assertFalse( wp_style_is( OrderDisplay::STYLE_HANDLE, 'enqueued' ), 'sipariş sayfası değil: kuyrukta yok' );

			// Sipariş alındı sayfası.
			$reset();
			add_filter( 'woocommerce_is_order_received_page', '__return_true' );
			OrderDisplay::enqueueStyle();
			$this->assertTrue( wp_style_is( OrderDisplay::STYLE_HANDLE, 'enqueued' ), 'sipariş alındı: baş bölümde kuyrukta' );
			$this->card( $oid );
			$this->assertCount( 1, (array) wp_styles()->get_data( OrderDisplay::STYLE_HANDLE, 'after' ), 'kopya yok' );
			remove_filter( 'woocommerce_is_order_received_page', '__return_true' );

			// Siparişi görüntüle (Hesabım).
			$reset();
			$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
			$old  = get_option( 'woocommerce_myaccount_page_id' );
			update_option( 'woocommerce_myaccount_page_id', $page );
			$this->go_to( get_permalink( $page ) );
			$GLOBALS['wp']->query_vars['view-order'] = (string) $oid;
			OrderDisplay::enqueueStyle();
			$this->assertTrue( wp_style_is( OrderDisplay::STYLE_HANDLE, 'enqueued' ), 'siparişi görüntüle: baş bölümde kuyrukta' );
			unset( $GLOBALS['wp']->query_vars['view-order'] );
			update_option( 'woocommerce_myaccount_page_id', $old );
			$reset();
		} finally {
			remove_filter( 'woocommerce_is_order_received_page', '__return_true' );
			$GLOBALS['wp_styles'] = $saved_styles; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test kendi küresel kaydını kurar ve geri koyar.
		}
	}

	/**
	 * Task 17: hiçbir ön yüz şablonu `<style>`/`<script>` içermez (satır içi stil yalnız templates/emails/).
	 */
	public function test_front_templates_contain_no_inline_style(): void {
		$files = glob( SLOTIVA_DIR . '/templates/front/*.php' );
		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			$this->assertDoesNotMatchRegularExpression( '/<(style|script)\b/i', (string) file_get_contents( $file ), basename( $file ) );
		}
	}

	public function test_card_pending_message_for_held(): void {
		list( $oid, $ids ) = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours' );
		$r                 = $this->row( $ids[0] );

		$html = $this->card( $oid );
		$this->assertStringContainsString( 'Awaiting payment, your time slot is being held.', $html );
		$this->assertStringContainsString( '10:00–10:30', $html );
		$this->assertStringNotContainsString( 'slotiva_ics=' . $r->token, $html, 'tutulan randevuya takvim yok' );
		$this->assertStringNotContainsString( 'calendar.google.com', $html );
	}

	public function test_card_escapes_snapshots(): void {
		list( $oid, $ids ) = $this->booked_order( 'cod', 'confirmed', 'on_site', null );
		$this->set_location( $ids[0], '<script>alert(1)</script>' );

		$html = $this->card( $oid );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_no_card_for_order_without_reservations(): void {
		$order = wc_create_order();
		$order->save();
		$this->assertSame( '', $this->card( $order->get_id() ) );
	}

	public function test_template_is_overridable(): void {
		list( $oid ) = $this->booked_order( 'cod', 'confirmed', 'on_site', null );
		$theme       = trailingslashit( get_temp_dir() ) . 'slotiva-theme-card-' . wp_generate_password( 6, false ) . '.php';
		file_put_contents( $theme, '<?php echo "THEME-CARD:" . count( $bookings );' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test: sahte tema şablonu.
		$filter = static fn( $located, $name ) => 'front/appointment-card.php' === $name ? $theme : $located;
		add_filter( 'wc_get_template', $filter, 10, 2 );
		$html   = $this->card( $oid );
		remove_filter( 'wc_get_template', $filter, 10 );
		unlink( $theme ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test: geçici dosya.
		$this->assertSame( 'THEME-CARD:1', $html, 'tema şablonu kazanır' );
	}

	public function test_ics_link_requires_matching_token(): void {
		list( $oid, $ids ) = $this->booked_order( 'cod', 'confirmed', 'on_site', null );
		$r                 = $this->row( $ids[0] );
		$key               = $this->order( $oid )->get_order_key();

		// Siparişli: token + sipariş anahtarı.
		$ok = OrderDisplay::icsFor( $r->token, $key );
		$this->assertNotNull( $ok );
		$this->assertStringContainsString( "BEGIN:VCALENDAR\r\n", $ok['body'] );
		$this->assertStringContainsString( 'UID:' . $r->token . '@', $ok['body'] );
		$this->assertSame( 'booking-' . $r->id . '.ics', $ok['filename'] );

		// Yanlış / eksik anahtar, giriş yok → null (404).
		$this->assertNull( OrderDisplay::icsFor( $r->token, 'wc_order_wrong' ) );
		$this->assertNull( OrderDisplay::icsFor( $r->token, '' ) );
		// Yanlış token (var olmayan, biçimi geçerli) ve bozuk biçim.
		$this->assertNull( OrderDisplay::icsFor( str_repeat( 'a', 32 ), $key ) );
		$this->assertNull( OrderDisplay::icsFor( strtoupper( $r->token ), $key ), 'büyük harf = farklı dize' );
		$this->assertNull( OrderDisplay::icsFor( 'zz', $key ) );
		$this->assertNull( OrderDisplay::icsFor( $r->token . "' OR 1=1", $key ) );

		// Sipariş sahibi anahtarsız da alır; başkası alamaz.
		$owner = self::factory()->user->create();
		$other = self::factory()->user->create();
		$order = $this->order( $oid );
		$order->set_customer_id( $owner );
		$order->save();
		wp_set_current_user( $other );
		$this->assertNull( OrderDisplay::icsFor( $r->token, '' ) );
		wp_set_current_user( $owner );
		$this->assertNotNull( OrderDisplay::icsFor( $r->token, '' ) );
		wp_set_current_user( 0 );
	}

	public function test_manual_booking_ics_needs_only_the_token(): void {
		$id = $this->rival( $this->bookable() );
		$r  = $this->row( $id );
		$this->assertNotNull( OrderDisplay::icsFor( $r->token, '' ) );
		$this->assertNull( OrderDisplay::icsFor( str_repeat( 'b', 32 ), '' ) );
	}

	public function test_ics_only_for_confirmed(): void {
		list( $oid, $ids ) = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours' );
		$r                 = $this->row( $ids[0] );
		$key               = $this->order( $oid )->get_order_key();
		$this->assertNull( OrderDisplay::icsFor( $r->token, $key ), 'tutulan randevuya takvim dosyası yok' );
		$this->assertTrue( ReservationRepository::setStatus( $ids[0], 'confirmed', 'paid', array( 'expect_status' => array( 'held' ) ) ) );
		$this->assertNotNull( OrderDisplay::icsFor( $r->token, $key ) );
		$this->assertTrue( ReservationRepository::setStatus( $ids[0], 'completed' ) );
		$this->assertNull( OrderDisplay::icsFor( $r->token, $key ), 'tamamlanmış randevuya takvim dosyası yok' );
	}

	public function test_bad_ics_request_sets_404_and_returns(): void {
		global $wp_query; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- çekirdek küresel.
		$wp_query                        = new \WP_Query(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- test: yeni sorgu.
		$_GET[ OrderDisplay::QUERY_VAR ] = str_repeat( 'c', 32 );
		@OrderDisplay::serveIcs(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test: başlıklar zaten gönderilmiş olabilir. `exit` yok: 404'ü bırakıp döner, şablonu WP yükler.
		unset( $_GET[ OrderDisplay::QUERY_VAR ] );
		$this->assertTrue( $wp_query->is_404() );
	}

	public function test_google_link_encodes_special_characters(): void {
		list( $oid, $ids ) = $this->booked_order( 'cod', 'confirmed', 'on_site', null );
		global $wpdb;
		$wpdb->update( \MHM\Slotiva\Db\Schema::table( 'reservations' ), array( 'product_name' => 'Atölye & Tasarım #1' ), array( 'id' => $ids[0] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test: anlık kopya.
		$html = $this->card( $oid );
		$this->assertMatchesRegularExpression( '#calendar\.google\.com/calendar/render\?[^"]*text=At%C3%B6lye%20%26%20Tasar%C4%B1m%20%231#', $html );
	}

	public function test_label_follows_reschedule_in_item_meta_and_wc_email(): void {
		list( $oid, $ids ) = $this->booked_order( 'cod', 'confirmed', 'on_site', null );
		$order             = $this->order( $oid );
		$item              = current( $order->get_items() );
		$this->assertInstanceOf( \WC_Order_Item_Product::class, $item );
		$item->update_meta_data( CheckoutFlow::META_LABEL, 'STALE 17 Kasim 10:00' ); // Ödeme anında yazılan saklı etiket.
		$item->save();

		$this->assertTrue( ReservationRepository::reschedule( $ids[0], new \DateTimeImmutable( '2026-11-19T11:00:00Z' ), true, false ) );

		$order = $this->order( $oid );
		$item  = current( $order->get_items() );
		$this->assertInstanceOf( \WC_Order_Item_Product::class, $item );
		$front = array_values( array_filter( $item->get_formatted_meta_data(), static fn( $m ) => CheckoutFlow::META_LABEL === $m->key ) );
		$this->assertCount( 1, $front );
		$this->assertStringContainsString( 'Thursday, November 19, 2026, 14:00–14:30', $front[0]->display_value, 'yeni saat' );
		$this->assertStringNotContainsString( 'STALE', $front[0]->display_value );

		$admin = array_values( array_filter( $item->get_all_formatted_meta_data( '' ), static fn( $m ) => CheckoutFlow::META_LABEL === $m->key ) );
		$this->assertCount( 1, $admin );
		$this->assertStringContainsString( 'November 19, 2026, 14:00–14:30', $admin[0]->display_value, 'yönetici listesi de güncel' );

		$emails        = WC()->mailer()->get_emails();
		$email         = $emails['WC_Email_Customer_Processing_Order'];
		$email->object = $order;
		$html          = $email->get_content_html();
		$this->assertStringContainsString( 'November 19, 2026, 14:00–14:30', $html, 'WC e-postası yeni saati gösterir' );
		$this->assertStringNotContainsString( 'STALE', $html );
	}

	public function test_label_falls_back_to_stored_when_item_has_no_reservation(): void {
		$order = wc_create_order();
		$order->add_product( wc_get_product( $this->bookable() ), 1 );
		$order->save();
		$item = current( $order->get_items() );
		$item->update_meta_data( CheckoutFlow::META_LABEL, 'Stored label 17 Kasim' );
		$item->save();
		$item = current( $this->order( $order->get_id() )->get_items() );
		$vals = array_map( static fn( $m ) => $m->display_value, array_values( $item->get_formatted_meta_data() ) );
		$this->assertStringContainsString( 'Stored label 17 Kasim', implode( '', $vals ) );
	}

	public function test_ics_not_served_for_cancelled(): void {
		list( $oid, $ids ) = $this->booked_order( 'cod', 'confirmed', 'on_site', null );
		$r                 = $this->row( $ids[0] );
		$this->assertTrue( ReservationRepository::release( $ids[0], 'cancelled' ) );
		$this->assertNull( OrderDisplay::icsFor( $r->token, $this->order( $oid )->get_order_key() ) );
	}

	public function test_hidden_meta_not_listed(): void {
		$hidden = (array) apply_filters( 'woocommerce_hidden_order_itemmeta', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- çekirdek kancası.
		$this->assertContains( CheckoutFlow::META_DURATION, $hidden );
		$this->assertContains( CheckoutFlow::META_LOCATION, $hidden );
		$this->assertNotContains( CheckoutFlow::META_LABEL, $hidden, 'etiket görünür kalır' );

		list( $oid ) = $this->booked_order( 'cod', 'confirmed', 'on_site', null );
		$order       = $this->order( $oid );
		$item        = current( $order->get_items() );
		$this->assertInstanceOf( \WC_Order_Item_Product::class, $item );
		$item->update_meta_data( CheckoutFlow::META_LABEL, '17 Kasım Salı, 10:00' );
		$item->update_meta_data( CheckoutFlow::META_DURATION, '30' );
		$item->update_meta_data( CheckoutFlow::META_LOCATION, 'Atölye' );
		$item->save();

		$front = array_map( static fn( $m ) => $m->key, $item->get_formatted_meta_data() ); // Ön yüz: '_' önekli gizli.
		$this->assertContains( CheckoutFlow::META_LABEL, $front, 'müşteri kalemde etiketi görür' );
		$this->assertNotContains( CheckoutFlow::META_DURATION, $front );
		$this->assertNotContains( CheckoutFlow::META_LOCATION, $front );
		$this->assertSame( 1, count( array_keys( $front, CheckoutFlow::META_LABEL, true ) ) );

		$all  = array_values( $item->get_all_formatted_meta_data( '' ) ); // Yönetici: önek yok.
		$keys = array_map( static fn( $m ) => $m->key, $all );
		$this->assertSame( 1, count( array_keys( $keys, CheckoutFlow::META_LABEL, true ) ), 'yönetici listesinde etiket yinelenmez' );
		$label = $all[ array_search( CheckoutFlow::META_LABEL, $keys, true ) ];
		$this->assertSame( 'Appointment', $label->display_key );
	}

	public function test_admin_order_metabox_registered_for_hpos_screen(): void {
		list( $oid, $ids ) = $this->booked_order( 'cod', 'confirmed', 'on_site', null );
		$order             = $this->order( $oid );

		// HPOS AÇIK: ekran kimliği `*_page_wc-orders` (yönetici menüsü yüklüyse `woocommerce_page_wc-orders`).
		$hpos = static fn() => 'yes';
		add_filter( 'pre_option_woocommerce_custom_orders_table_enabled', $hpos ); // Seçenek yazılmaz (WC eşzamanlama kontrolü tetiklenmesin).
		try {
			$screen = wc_get_page_screen_id( 'shop-order' );
			$this->assertStringEndsWith( '_page_wc-orders', $screen, 'HPOS gerçekten açık (yönetici menüsü yokken önek admin_page, açıkken woocommerce_page)' );
			$this->assertNotSame( 'shop_order', $screen );

			global $wp_meta_boxes; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- çekirdek küresel.
			unset( $wp_meta_boxes[ $screen ] );
			do_action( 'add_meta_boxes', $screen, $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- çekirdek kancası.
			$box = $wp_meta_boxes[ $screen ]['side']['default'][ OrderDisplay::METABOX_ID ] ?? null;
			$this->assertIsArray( $box );
			$this->assertSame( 'Appointment', $box['title'] );
			$this->assertTrue( is_callable( $box['callback'] ) );

			// Randevusuz sipariş: kutu yok.
			unset( $wp_meta_boxes[ $screen ] );
			$empty = new \WC_Order(); // Kaydedilmez: HPOS tablolarına yazı sızıp sonraki testlerin eşzamanlama kontrolünü bozmasın.
			do_action( 'add_meta_boxes', $screen, $empty ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- çekirdek kancası.
			$this->assertArrayNotHasKey( OrderDisplay::METABOX_ID, $wp_meta_boxes[ $screen ]['side']['default'] ?? array() );
		} finally {
			remove_filter( 'pre_option_woocommerce_custom_orders_table_enabled', $hpos );
		}

		// Kutunun içeriği.
		ob_start();
		OrderDisplay::renderMetabox( $order );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '10:00–10:30', $html );
		$this->assertStringContainsString( 'Confirmed', $html, 'durum rozeti' );
		$this->assertStringContainsString( 'Pay on site', $html, 'ödeme rozeti' );
		$this->assertStringContainsString( 'admin.php?page=slotiva&#038;view=detail&#038;id=' . $ids[0], $html, 'ayrıntı bağlantısı' );
	}

	/**
	 * (Final review F10 / m-6) Ödenmiş ama saati alınamamış (`conflict`) randevu müşteriye boş görünmez: nötr bir not;
	 * takvim bağlantısı yok.
	 */
	public function test_card_shows_neutral_note_for_conflict(): void {
		list( $oid, $ids ) = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours' );
		$this->assertTrue( ReservationRepository::setStatus( $ids[0], 'conflict', 'paid', array( 'reason' => 'late_paid_taken' ) ) );
		$r = $this->row( $ids[0] );

		$html = $this->card( $oid );
		$this->assertStringContainsString( 'slotiva-appointment', $html, 'kart görünür' );
		$this->assertStringContainsString( 'We could not keep this time. The shop will contact you to arrange a new time or a refund.', $html );
		$this->assertStringContainsString( 'slotiva-appointment__card--muted', $html );
		$this->assertStringNotContainsString( 'slotiva_ics=' . $r->token, $html );
		$this->assertStringNotContainsString( 'calendar.google.com', $html );
	}
}
