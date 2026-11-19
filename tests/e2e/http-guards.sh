#!/usr/bin/env bash
# Kabul senaryoları 3 ve 4 (spec §11; §15 "yarış senaryoları bağımsız HTTP bağlantılarıyla"), gerçek HTTP:
#   3) Açık kalmış eski ödeme sayfası: oturum B boş saatleri okur, ardından oturum A aynı dilimi öder. B eski sayfadan
#      aynı dilimi gönderir → 409 slotiva_slot_taken (+ data.taken); B'nin yenilenen listesinde dilim yok.
#   4) Randevusuz sipariş: sepetinde randevulu ürün olan oturum C, uzantı verisi olmadan öder → 400 slotiva_no_slots;
#      C için sipariş ödemeye geçmez (on-hold/processing sipariş yok), rezervasyon yazılmaz.
#
# Yerel Docker yığını (http://localhost:8110). Betik kendi ürününü kurar ve sonunda siler; düzeni/havale ayarını geri yükler.
# Kullanım (depo kökünden): bash tests/e2e/http-guards.sh   — pakete girmez (tests/).
set -euo pipefail

BASE="${SLOTIVA_E2E_URL:-http://localhost:8110}"
DC=(docker compose -f docker/docker-compose.yml exec -T wpcli)

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

wp() { MSYS_NO_PATHCONV=1 "${DC[@]}" wp "$@" 2>/dev/null; }

OLD_SCHEDULE=$(wp eval 'echo wp_json_encode( get_option( "slotiva_schedule", null ) );')
OLD_BACS=$(wp eval 'echo wp_json_encode( get_option( "woocommerce_bacs_settings", null ) );')

PID=$(wp eval '
update_option( "woocommerce_bacs_settings", array_merge( (array) get_option( "woocommerce_bacs_settings", array() ), array( "enabled" => "yes" ) ) );
$d = array( array( "from" => "09:00", "to" => "21:00" ) );
MHM\Slotiva\Schedule\WeeklySchedule::save( array( $d, $d, $d, $d, $d, $d, $d ) );
$p = new WC_Product_Simple();
$p->set_name( "Slotiva e2e korumalar" );
$p->set_regular_price( "100" );
$p->set_virtual( true );
$p->set_status( "publish" );
foreach ( array( "_slotiva_enabled" => "yes", "_slotiva_duration" => "30", "_slotiva_pricing" => "fixed", "_slotiva_lead_hours" => "0", "_slotiva_horizon_days" => "14" ) as $k => $v ) { $p->update_meta_data( $k, $v ); }
echo $p->save();
')
cleanup() {
	wp eval "
global \$wpdb;
\$t = MHM\Slotiva\Db\Schema::table( 'reservations' );
\$ids = \$wpdb->get_col( \$wpdb->prepare( \"SELECT id FROM {\$t} WHERE product_id = %d\", $PID ) );
foreach ( \$ids as \$id ) { \$wpdb->delete( MHM\Slotiva\Db\Schema::table( 'occupancy' ), array( 'reservation_id' => (int) \$id ) ); \$wpdb->delete( MHM\Slotiva\Db\Schema::table( 'events' ), array( 'reservation_id' => (int) \$id ) ); }
\$wpdb->delete( \$t, array( 'product_id' => $PID ) );
foreach ( wc_get_orders( array( 'limit' => -1, 'status' => array_keys( wc_get_order_statuses() ) ) ) as \$ord ) { foreach ( \$ord->get_items() as \$it ) { if ( (int) \$it->get_product_id() === $PID ) { \$ord->delete( true ); break; } } }
wp_delete_post( $PID, true );
\$old = json_decode( wp_unslash( '$OLD_SCHEDULE' ), true );
if ( null === \$old ) { delete_option( 'slotiva_schedule' ); } else { update_option( 'slotiva_schedule', \$old ); }
\$bacs = json_decode( '$OLD_BACS', true );
if ( null === \$bacs ) { delete_option( 'woocommerce_bacs_settings' ); } else { update_option( 'woocommerce_bacs_settings', \$bacs ); }
" >/dev/null || true
	rm -rf "$TMP"
}
trap cleanup EXIT

DAY=$(wp eval 'echo ( new DateTimeImmutable( "now", wp_timezone() ) )->modify( "+2 days" )->format( "Y-m-d" );')
avail() { curl -s "$BASE/wp-json/slotiva/v1/availability?product=$PID&day=$DAY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach ( $d["slots"] ?? array() as $s ) { echo $s["start"], "\n"; }'; }

prepare() {
	local n="$1" jar="$TMP/jar$1" nonce nonce2
	nonce=$(curl -s -D - -o /dev/null -c "$jar" -b "$jar" "$BASE/wp-json/wc/store/v1/cart" | tr -d '\r' | awk -F': ' 'tolower($1)=="nonce"{print $2}')
	nonce2=$(curl -s -D - -o /dev/null -c "$jar" -b "$jar" -H "Nonce: $nonce" -H 'Content-Type: application/json' \
		-d "{\"id\":$PID,\"quantity\":1}" "$BASE/wp-json/wc/store/v1/cart/add-item" | tr -d '\r' | awk -F': ' 'tolower($1)=="nonce"{print $2}')
	echo "${nonce2:-$nonce}" > "$TMP/nonce$n"
}
# fire <oturum> <uzantı JSON'u ya da boş> <etiket>
fire() {
	local n="$1" ext="$2" tag="$3" body
	body=$(printf '{"billing_address":{"first_name":"Koruma","last_name":"Testi","email":"koruma%s@example.test","phone":"05550000000","address_1":"x","city":"Istanbul","postcode":"34000","country":"TR","state":"TR34"},"payment_method":"bacs"%s}' "$n" "$ext")
	curl -s -o "$TMP/out$tag" -w '%{http_code}' -c "$TMP/jar$n" -b "$TMP/jar$n" -H "Nonce: $(cat "$TMP/nonce$n")" \
		-H 'Content-Type: application/json' -d "$body" "$BASE/wp-json/wc/store/v1/checkout" > "$TMP/code$tag"
}

FAIL=0

# --- 3) Eski sayfa dolu dilimi gönderir ------------------------------------------------------------------------------
prepare 2
mapfile -t SEEN < <( avail )                                   # B'nin sayfası: boş saatler okundu
SLOT="${SEEN[0]:-}"
[ -n "$SLOT" ] || { echo "Boş dilim yok ($DAY)" >&2; exit 1; }
prepare 1
fire 1 ",\"extensions\":{\"slotiva\":{\"slots\":[\"$SLOT\"]}}" a
echo "3 · oturum A ($SLOT): $(cat "$TMP/codea")"
fire 2 ",\"extensions\":{\"slotiva\":{\"slots\":[\"$SLOT\"]}}" b
echo "3 · oturum B, eski sayfa: $(cat "$TMP/codeb") $(head -c 200 "$TMP/outb")"
mapfile -t AFTER < <( avail )
listed=0; for s in "${AFTER[@]}"; do [ "$s" = "$SLOT" ] && listed=1; done
if [ "$(cat "$TMP/codea")" = "200" ] && [ "$(cat "$TMP/codeb")" = "409" ] && grep -q '"slotiva_slot_taken"' "$TMP/outb" \
	&& grep -q "\"taken\":\[\"$SLOT\"\]" "$TMP/outb" && [ "$listed" = 0 ]; then
	echo "3 GEÇTİ: eski sayfa 409 slotiva_slot_taken (taken=[$SLOT]); yenilenen listede dilim yok."
else
	echo "3 KALDI: A $(cat "$TMP/codea"), B $(cat "$TMP/codeb"), listede $listed" >&2
	FAIL=1
fi

# --- 4) Randevusuz sipariş reddedilir --------------------------------------------------------------------------------
prepare 3
fire 3 "" c
echo "4 · uzantısız ödeme: $(cat "$TMP/codec") $(head -c 200 "$TMP/outc")"
PAID=$(wp eval "\$n = 0; foreach ( wc_get_orders( array( 'limit' => -1, 'billing_email' => 'koruma3@example.test', 'status' => array( 'on-hold', 'processing', 'completed' ) ) ) as \$o ) { ++\$n; } echo \$n;")
ROWS=$(wp eval "global \$wpdb; echo (int) \$wpdb->get_var( \$wpdb->prepare( 'SELECT COUNT(*) FROM ' . MHM\Slotiva\Db\Schema::table( 'reservations' ) . \" WHERE product_id = %d AND customer_email = %s\", $PID, 'koruma3@example.test' ) );")
echo "4 · C için ödemeye geçen sipariş: $PAID, rezervasyon: $ROWS"
if [ "$(cat "$TMP/codec")" = "400" ] && grep -q '"slotiva_no_slots"' "$TMP/outc" && [ "$PAID" = "0" ] && [ "$ROWS" = "0" ]; then
	echo "4 GEÇTİ: randevusuz ödeme 400 slotiva_no_slots; sipariş ödemeye geçmedi, rezervasyon yok."
else
	echo "4 KALDI: kod $(cat "$TMP/codec"), sipariş $PAID, rezervasyon $ROWS" >&2
	FAIL=1
fi

exit "$FAIL"
