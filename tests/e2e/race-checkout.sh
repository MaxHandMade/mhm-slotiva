#!/usr/bin/env bash
# Kabul senaryosu 1 (spec §11, §15.12): iki ayrı müşteri oturumu AYNI dilimi AYNI ANDA Store API ile gönderir.
# Beklenen: tam bir başarılı talep (200) ve bir 409 slotiva_slot_taken; veritabanında o dilim için tek etkin rezervasyon.
#
# Gerçek HTTP: yerel Docker yığını (docker/docker-compose.yml, http://localhost:8110). Her oturum kendi çerez kavanozu +
# Store API Nonce'u ile; iki POST arka planda eşzamanlı başlatılır. Betik kendi ürününü kurar ve sonunda siler.
#
# Kullanım (depo kökünden): bash tests/e2e/race-checkout.sh   — pakete girmez (tests/).
set -euo pipefail

BASE="${SLOTIVA_E2E_URL:-http://localhost:8110}"
DC=(docker compose -f docker/docker-compose.yml exec -T wpcli)

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# Yalnız docker çağrısında yol çevirisi kapalı (Git Bash); curl yerel yolları (/tmp, /dev/null) çevrilmiş ister.
wp() { MSYS_NO_PATHCONV=1 "${DC[@]}" wp "$@" 2>/dev/null; }

# Geliştirme sitesinin düzeni ve havale ayarı betik sonunda geri yüklenir.
OLD_SCHEDULE=$(wp eval 'echo wp_json_encode( get_option( "slotiva_schedule", null ) );')
OLD_BACS=$(wp eval 'echo wp_json_encode( get_option( "woocommerce_bacs_settings", null ) );')

# Kurulum: her gün 09:00–21:00 açık, ön süre 0; randevulu sabit ürün; havale açık.
PID=$(wp eval '
update_option( "woocommerce_bacs_settings", array_merge( (array) get_option( "woocommerce_bacs_settings", array() ), array( "enabled" => "yes" ) ) );
$d = array( array( "from" => "09:00", "to" => "21:00" ) );
MHM\Slotiva\Schedule\WeeklySchedule::save( array( $d, $d, $d, $d, $d, $d, $d ) );
$p = new WC_Product_Simple();
$p->set_name( "Slotiva e2e yarış" );
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
SLOT=$(curl -s "$BASE/wp-json/slotiva/v1/availability?product=$PID&day=$DAY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["slots"][0]["start"] ?? "";')
[ -n "$SLOT" ] || { echo "Boş dilim bulunamadı ($DAY)" >&2; exit 1; }
echo "Ürün $PID, dilim $SLOT"

# Oturum hazırla: sepete ekle, son Nonce'u sakla.
prepare() {
	local n="$1" jar="$TMP/jar$1" nonce
	nonce=$(curl -s -D - -o /dev/null -c "$jar" -b "$jar" "$BASE/wp-json/wc/store/v1/cart" | tr -d '\r' | awk -F': ' 'tolower($1)=="nonce"{print $2}')
	nonce2=$(curl -s -D - -o /dev/null -c "$jar" -b "$jar" -H "Nonce: $nonce" -H 'Content-Type: application/json' \
		-d "{\"id\":$PID,\"quantity\":1}" "$BASE/wp-json/wc/store/v1/cart/add-item" | tr -d '\r' | awk -F': ' 'tolower($1)=="nonce"{print $2}')
	echo "${nonce2:-$nonce}" > "$TMP/nonce$n"
}
prepare 1
prepare 2

# Gövde ASCII: Windows curl komut satırındaki UTF-8 argümanı kod sayfasıyla bozar (rest_invalid_json).
BODY=$(printf '{"billing_address":{"first_name":"Yaris","last_name":"Testi","email":"yaris%%s@example.test","phone":"05550000000","address_1":"x","city":"Istanbul","postcode":"34000","country":"TR","state":"TR34"},"payment_method":"bacs","extensions":{"slotiva":{"slots":["%s"]}}}' "$SLOT")
fire() {
	local n="$1"
	# shellcheck disable=SC2059
	curl -s -o "$TMP/out$n" -w '%{http_code}' -c "$TMP/jar$n" -b "$TMP/jar$n" -H "Nonce: $(cat "$TMP/nonce$n")" \
		-H 'Content-Type: application/json' -d "$(printf "$BODY" "$n")" "$BASE/wp-json/wc/store/v1/checkout" > "$TMP/code$n"
}
fire 1 & fire 2 & wait

C1=$(cat "$TMP/code1"); C2=$(cat "$TMP/code2")
echo "Oturum 1: $C1 $(head -c 160 "$TMP/out1")"
echo "Oturum 2: $C2 $(head -c 160 "$TMP/out2")"
ACTIVE=$(wp eval "global \$wpdb; echo (int) \$wpdb->get_var( \$wpdb->prepare( 'SELECT COUNT(*) FROM ' . MHM\Slotiva\Db\Schema::table( 'reservations' ) . \" WHERE product_id = %d AND status IN ('held','confirmed')\", $PID ) );")
echo "Etkin rezervasyon: $ACTIVE"

codes=$(printf '%s\n%s\n' "$C1" "$C2" | sort | tr '\n' ' ')
if [ "$codes" = "200 409 " ] && [ "$ACTIVE" = "1" ] && grep -q slotiva_slot_taken "$TMP/out1" "$TMP/out2"; then
	echo "GEÇTİ: tek başarılı talep, diğeri 409 slotiva_slot_taken, tek etkin rezervasyon."
	exit 0
fi
echo "KALDI: kodlar [$codes], etkin $ACTIVE" >&2
exit 1
