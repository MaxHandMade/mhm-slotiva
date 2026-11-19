#!/usr/bin/env bash
# Kota yarışı (spec §15.8, Codex plan eki: "aynı oturum/IP iki sipariş"): tutma kotası gerçek HTTP altında aşılmaz.
#
# A) Aynı IP, farklı oturumlar: oturum 1 bir havale siparişi verir (IP'de 1 tutma). Ardından oturum 2 ve 3 AYNI ANDA
#    farklı dilimler için sipariş verir. Kota 2 (ReservationRepository::HOLD_QUOTA): tam biri 200, diğeri 429
#    slotiva_hold_quota; IP'de asla 2'den fazla tutulan sipariş olmaz.
# B) Aynı oturum: bir havale siparişi verir (oturumda 1 tutma), sepete yeniden ekler; sonra AYNI çerezle iki gönderim
#    AYNI ANDA → biri geçer, diğeri reddedilir (429 kota ya da 409 meşgul); oturumda asla 2'den fazla tutulan sipariş olmaz.
#
# Gerçek HTTP: yerel Docker yığını (http://localhost:8110). Bütün istekler aynı makineden → sunucuda aynı REMOTE_ADDR.
# Betik kendi ürününü kurar, sonunda ürünü, siparişleri, rezervasyonları siler ve düzen/havale ayarını geri yükler.
#
# Kullanım (depo kökünden): bash tests/e2e/race-quota.sh   — pakete girmez (tests/).
set -euo pipefail

BASE="${SLOTIVA_E2E_URL:-http://localhost:8110}"
DC=(docker compose -f docker/docker-compose.yml exec -T wpcli)

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

wp() { MSYS_NO_PATHCONV=1 "${DC[@]}" wp "$@" 2>/dev/null; }

OLD_SCHEDULE=$(wp eval 'echo wp_json_encode( get_option( "slotiva_schedule", null ) );')
OLD_BACS=$(wp eval 'echo wp_json_encode( get_option( "woocommerce_bacs_settings", null ) );')

# Önceden tutulan sipariş olmamalı: IP kotası sitedeki bütün tutmaları sayar.
PRE=$(wp eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . MHM\Slotiva\Db\Schema::table( "reservations" ) . " WHERE status = \"held\"" );')
[ "$PRE" = "0" ] || { echo "Sitede $PRE tutulan rezervasyon var; temiz sitede çalıştırın." >&2; exit 1; }

PID=$(wp eval '
update_option( "woocommerce_bacs_settings", array_merge( (array) get_option( "woocommerce_bacs_settings", array() ), array( "enabled" => "yes" ) ) );
$d = array( array( "from" => "09:00", "to" => "21:00" ) );
MHM\Slotiva\Schedule\WeeklySchedule::save( array( $d, $d, $d, $d, $d, $d, $d ) );
$p = new WC_Product_Simple();
$p->set_name( "Slotiva e2e kota" );
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
\$o = MHM\Slotiva\Db\Schema::table( 'occupancy' );
\$ids = \$wpdb->get_col( \$wpdb->prepare( \"SELECT id FROM {\$t} WHERE product_id = %d\", $PID ) );
foreach ( \$ids as \$id ) { \$wpdb->delete( \$o, array( 'reservation_id' => (int) \$id ) ); \$wpdb->delete( MHM\Slotiva\Db\Schema::table( 'events' ), array( 'reservation_id' => (int) \$id ) ); }
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
mapfile -t SLOTS < <( curl -s "$BASE/wp-json/slotiva/v1/availability?product=$PID&day=$DAY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach ( array_slice( $d["slots"] ?? array(), 0, 6 ) as $s ) { echo $s["start"], "\n"; }' )
[ "${#SLOTS[@]}" -ge 6 ] || { echo "Yeterli boş dilim yok ($DAY)" >&2; exit 1; }
echo "Ürün $PID, gün $DAY, dilimler ${SLOTS[*]}"

prepare() {
	local n="$1" jar="$TMP/jar$1" nonce nonce2
	nonce=$(curl -s -D - -o /dev/null -c "$jar" -b "$jar" "$BASE/wp-json/wc/store/v1/cart" | tr -d '\r' | awk -F': ' 'tolower($1)=="nonce"{print $2}')
	nonce2=$(curl -s -D - -o /dev/null -c "$jar" -b "$jar" -H "Nonce: $nonce" -H 'Content-Type: application/json' \
		-d "{\"id\":$PID,\"quantity\":1}" "$BASE/wp-json/wc/store/v1/cart/add-item" | tr -d '\r' | awk -F': ' 'tolower($1)=="nonce"{print $2}')
	echo "${nonce2:-$nonce}" > "$TMP/nonce$n"
}

# fire <oturum> <dilim> <çıktı etiketi>
fire() {
	local n="$1" slot="$2" tag="$3" body
	body=$(printf '{"billing_address":{"first_name":"Kota","last_name":"Testi","email":"kota%s@example.test","phone":"05550000000","address_1":"x","city":"Istanbul","postcode":"34000","country":"TR","state":"TR34"},"payment_method":"bacs","extensions":{"slotiva":{"slots":["%s"]}}}' "$n" "$slot")
	curl -s -o "$TMP/out$tag" -w '%{http_code}' -c "$TMP/jar$n" -b "$TMP/jar$n" -H "Nonce: $(cat "$TMP/nonce$n")" \
		-H 'Content-Type: application/json' -d "$body" "$BASE/wp-json/wc/store/v1/checkout" > "$TMP/code$tag"
}

held_orders() { # IP/oturum ayrımı olmadan ürünün tutulan sipariş sayısı (hepsi aynı IP'den).
	wp eval "global \$wpdb; echo (int) \$wpdb->get_var( \$wpdb->prepare( 'SELECT COUNT(DISTINCT order_id) FROM ' . MHM\Slotiva\Db\Schema::table( 'reservations' ) . \" WHERE product_id = %d AND status = 'held'\", $PID ) );"
}

FAIL=0

# --- A) Aynı IP, farklı oturumlar ---------------------------------------------------------------------------------
prepare 1
fire 1 "${SLOTS[0]}" a1
echo "A · oturum 1 (ön sipariş): $(cat "$TMP/codea1")"
[ "$(cat "$TMP/codea1")" = "200" ] || { echo "KALDI: ön sipariş verilemedi: $(head -c 300 "$TMP/outa1")" >&2; exit 1; }

prepare 2
prepare 3
fire 2 "${SLOTS[1]}" a2 & fire 3 "${SLOTS[2]}" a3 & wait
A2=$(cat "$TMP/codea2"); A3=$(cat "$TMP/codea3")
echo "A · oturum 2: $A2 $(head -c 140 "$TMP/outa2")"
echo "A · oturum 3: $A3 $(head -c 140 "$TMP/outa3")"
HELD_A=$(held_orders)
echo "A · tutulan sipariş (aynı IP): $HELD_A"
codes=$(printf '%s\n%s\n' "$A2" "$A3" | sort | tr '\n' ' ')
if [ "$codes" = "200 429 " ] && [ "$HELD_A" = "2" ] && grep -q slotiva_hold_quota "$TMP/outa2" "$TMP/outa3"; then
	echo "A GEÇTİ: biri 200, diğeri 429 slotiva_hold_quota; IP'de 2 tutulan sipariş (kota aşılmadı)."
else
	echo "A KALDI: kodlar [$codes], tutulan $HELD_A" >&2
	FAIL=1
fi

# B için kotayı boşalt: A'nın siparişleri ve rezervasyonları silinir (ürün kalır).
wp eval "
global \$wpdb;
\$t = MHM\Slotiva\Db\Schema::table( 'reservations' );
foreach ( \$wpdb->get_col( \$wpdb->prepare( \"SELECT id FROM {\$t} WHERE product_id = %d\", $PID ) ) as \$id ) { \$wpdb->delete( MHM\Slotiva\Db\Schema::table( 'occupancy' ), array( 'reservation_id' => (int) \$id ) ); \$wpdb->delete( MHM\Slotiva\Db\Schema::table( 'events' ), array( 'reservation_id' => (int) \$id ) ); }
\$wpdb->delete( \$t, array( 'product_id' => $PID ) );
foreach ( wc_get_orders( array( 'limit' => -1, 'status' => array_keys( wc_get_order_statuses() ) ) ) as \$ord ) { foreach ( \$ord->get_items() as \$it ) { if ( (int) \$it->get_product_id() === $PID ) { \$ord->delete( true ); break; } } }
" >/dev/null

# --- B) Aynı oturum, sınırda iki eşzamanlı gönderim ---------------------------------------------------------------
prepare 4
fire 4 "${SLOTS[3]}" b0
echo "B · oturum 4 (ön sipariş): $(cat "$TMP/codeb0")"
[ "$(cat "$TMP/codeb0")" = "200" ] || { echo "KALDI: B ön siparişi verilemedi: $(head -c 300 "$TMP/outb0")" >&2; exit 1; }
prepare 4                                                     # aynı oturum: sepete yeniden ekle
cp "$TMP/jar4" "$TMP/jar5"; cp "$TMP/nonce4" "$TMP/nonce5"   # aynı oturum çerezi, aynı Nonce
fire 4 "${SLOTS[4]}" b1 & fire 5 "${SLOTS[5]}" b2 & wait
B1=$(cat "$TMP/codeb1"); B2=$(cat "$TMP/codeb2")
echo "B · gönderim 1: $B1 $(head -c 140 "$TMP/outb1")"
echo "B · gönderim 2: $B2 $(head -c 140 "$TMP/outb2")"
HELD_B=$(held_orders)
echo "B · tutulan sipariş (aynı oturum): $HELD_B"
codes=$(printf '%s\n%s\n' "$B1" "$B2" | sort | tr '\n' ' ')
case "$codes" in "200 409 "|"200 429 ") okb=1 ;; *) okb=0 ;; esac
if [ "$okb" = 1 ] && [ "$HELD_B" -le 2 ]; then
	echo "B GEÇTİ: kodlar [$codes]; oturumda $HELD_B tutulan sipariş (kota aşılmadı)."
else
	echo "B KALDI: kodlar [$codes], tutulan $HELD_B" >&2
	FAIL=1
fi

exit "$FAIL"
