#!/usr/bin/env bash
# Yönetim REST API'si (slotiva/v1/admin, lisans uçları dahil) çerez kimliği + nonce, GERÇEK HTTP ile (rest_do_request çerez denetimini sınamaz;
# Task 13 Codex eki, spec §15 "REST yetki/nonce hataları").
#
# Her uç için: çerezsiz → 401 · yönetici çerezi nonce'suz → 401 (çekirdek kullanıcıyı 0 yapar) · yönetici çerezi geçersiz
# nonce → 403 rest_cookie_invalid_nonce · abone çerezi geçerli nonce → 403 rest_forbidden · yönetici geçerli nonce → 2xx
# (yalnız okuyan uçlar; yazanların başarı yolu PHPUnit'te). export.csv: `_wpnonce` sorgu parametresi zorunlu (başlıkta
# nonce olsa bile), ham CSV + nosniff + no-cache. Sonda: reddedilen yazma isteklerinin hiçbir şeyi değiştirmediği doğrulanır.
#
# Oturum çerezi WP-CLI ile üretilir (WP_Session_Tokens + wp_generate_auth_cookie); parola yazılmaz. Betik kendi
# kullanıcılarını/ürününü/randevusunu kurar ve sonunda siler; çalışma düzenini geri yükler.
#
# Kullanım (depo kökünden): bash tests/e2e/admin-rest-auth.sh   — pakete girmez (tests/).
set -euo pipefail

BASE="${SLOTIVA_E2E_URL:-http://localhost:8110}"
DC=(docker compose -f docker/docker-compose.yml exec -T wpcli)
API="$BASE/wp-json/slotiva/v1/admin"

TMP="$(mktemp -d)"
FAIL=0
PASS=0

# --url: çerez adı (COOKIEHASH = md5(siteurl)) HTTP isteğindeki siteurl ile aynı olsun.
wp() { MSYS_NO_PATHCONV=1 "${DC[@]}" wp --url="$BASE" "$@" 2>/dev/null; }

OLD_SCHEDULE=$(wp eval 'echo wp_json_encode( get_option( "slotiva_schedule", null ) );')
OLD_RULES=$(wp eval 'echo md5( serialize( get_option( "slotiva_rules", null ) ) );')

ADMIN_ID=$(wp user create slotiva_e2e_admin slotiva_e2e_admin@example.test --role=administrator --porcelain)
SUB_ID=$(wp user create slotiva_e2e_sub slotiva_e2e_sub@example.test --role=subscriber --porcelain)
SHOP_ID=$(wp user create slotiva_e2e_shop slotiva_e2e_shop@example.test --role=shop_manager --porcelain)
OLD_LICENSE=$(wp eval 'echo md5( serialize( get_option( "slotiva_license", null ) ) );')

PID=$(wp eval '
$d = array( array( "from" => "09:00", "to" => "21:00" ) );
MHM\Slotiva\Schedule\WeeklySchedule::save( array( $d, $d, $d, $d, $d, $d, $d ) );
$p = new WC_Product_Simple();
$p->set_name( "Slotiva e2e admin" );
$p->set_regular_price( "100" );
$p->set_virtual( true );
$p->set_status( "publish" );
foreach ( array( "_slotiva_enabled" => "yes", "_slotiva_duration" => "30", "_slotiva_pricing" => "fixed" ) as $k => $v ) { $p->update_meta_data( $k, $v ); }
echo $p->save();
')

cleanup() {
	wp eval "
global \$wpdb;
\$t = MHM\Slotiva\Db\Schema::table( 'reservations' );
\$ids = \$wpdb->get_col( \$wpdb->prepare( \"SELECT id FROM {\$t} WHERE product_id = %d\", $PID ) );
foreach ( \$ids as \$id ) {
	\$wpdb->delete( MHM\Slotiva\Db\Schema::table( 'occupancy' ), array( 'reservation_id' => (int) \$id ) );
	\$wpdb->delete( MHM\Slotiva\Db\Schema::table( 'events' ), array( 'reservation_id' => (int) \$id ) );
}
\$wpdb->delete( \$t, array( 'product_id' => $PID ) );
wp_delete_post( $PID, true );
\$old = json_decode( wp_unslash( '$OLD_SCHEDULE' ), true );
if ( null === \$old ) { delete_option( 'slotiva_schedule' ); } else { update_option( 'slotiva_schedule', \$old ); }
" >/dev/null || true
	wp user delete "$ADMIN_ID" "$SUB_ID" "$SHOP_ID" --yes --reassign=1 >/dev/null || true
	rm -rf "$TMP"
}
trap cleanup EXIT

# Oturum: "<çerez adı>\t<çerez>\t<wp_rest nonce>" — nonce oturum belirtecine bağlı olduğundan çerezle aynı oturumdan.
session() {
	wp eval "
\$u   = get_user_by( 'login', '$1' );
\$exp = time() + HOUR_IN_SECONDS;
\$tok = WP_Session_Tokens::get_instance( \$u->ID )->create( \$exp );
\$c   = wp_generate_auth_cookie( \$u->ID, \$exp, 'logged_in', \$tok );
\$_COOKIE[ LOGGED_IN_COOKIE ] = \$c;
wp_set_current_user( \$u->ID );
echo LOGGED_IN_COOKIE . \"\t\" . \$c . \"\t\" . wp_create_nonce( 'wp_rest' );
"
}
IFS=$'\t' read -r CNAME ADMIN_COOKIE ADMIN_NONCE <<<"$(session slotiva_e2e_admin)"
IFS=$'\t' read -r _ SUB_COOKIE SUB_NONCE <<<"$(session slotiva_e2e_sub)"
IFS=$'\t' read -r _ SHOP_COOKIE SHOP_NONCE <<<"$(session slotiva_e2e_shop)"
[ -n "$ADMIN_NONCE" ] && [ -n "$SUB_NONCE" ] || { echo "Oturum üretilemedi" >&2; exit 1; }

# req <ad> <yöntem> <yol> <gövde|-> [curl ek argümanları...] → kod dosyaya, gövde dosyaya.
req() {
	local name="$1" method="$2" path="$3" body="$4"
	shift 4
	local args=(-s -o "$TMP/$name.body" -D "$TMP/$name.head" -w '%{http_code}' -X "$method")
	if [ "$body" != "-" ]; then
		args+=(-H 'Content-Type: application/json' --data "$body")
	fi
	curl "${args[@]}" "$@" "$API$path" > "$TMP/$name.code"
}

check() {
	local label="$1" want_code="$2" want_err="$3" name="$4"
	local got
	got=$(cat "$TMP/$name.code")
	if [ "$got" = "$want_code" ] && { [ -z "$want_err" ] || grep -q "\"code\":\"$want_err\"" "$TMP/$name.body"; }; then
		PASS=$((PASS + 1))
	else
		FAIL=$((FAIL + 1))
		echo "KALDI: $label → $got (beklenen $want_code $want_err): $(head -c 200 "$TMP/$name.body")" >&2
	fi
}

ADMIN_C=(-b "$CNAME=$ADMIN_COOKIE")
SUB_C=(-b "$CNAME=$SUB_COOKIE")
SHOP_C=(-b "$CNAME=$SHOP_COOKIE")

# Kurulum (gerçek HTTP, yönetici + geçerli nonce): elle randevu → 201.
SLOT=$(wp eval 'echo ( new DateTimeImmutable( "tomorrow 10:00", wp_timezone() ) )->setTimezone( new DateTimeZone( "UTC" ) )->format( "Y-m-d\TH:i:s\Z" );')
DAY=$(wp eval 'echo ( new DateTimeImmutable( "tomorrow", wp_timezone() ) )->format( "Y-m-d" );')
req create POST /appointments "{\"product_id\":$PID,\"slot\":\"$SLOT\",\"customer\":{\"name\":\"E2E Test\",\"email\":\"e2e@example.test\"},\"notify\":false}" "${ADMIN_C[@]}" -H "X-WP-Nonce: $ADMIN_NONCE"
check "kurulum POST /appointments (yönetici)" 201 "" create
RID=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo (int)($d["id"] ?? 0);' "$TMP/create.body")
[ "$RID" -gt 0 ] || { echo "Randevu kurulamadı" >&2; exit 1; }
EXC_ID=$(wp eval "echo (int) MHM\Slotiva\Schedule\ExceptionRepository::add( array( 'date' => '2030-01-02', 'type' => 'closed', 'note' => 'e2e' ) );")
SNAP_BEFORE=$(wp eval "\$r = MHM\Slotiva\Reservation\ReservationRepository::find( $RID ); echo \$r->status . '|' . \$r->sequence . '|' . \$r->startsAt->format( 'c' );")

# Uç tablosu: ad|yöntem|yol|gövde ("-" = yok). Gövdeler geçerli: şema doğrulaması (400) yetkiden önce koşar.
ROUTES=(
	"list|GET|/appointments?tab=upcoming|-"
	"detail|GET|/appointments/$RID|-"
	"create2|POST|/appointments|{\"product_id\":$PID,\"slot\":\"$SLOT\",\"customer\":{\"name\":\"X\"}}"
	"resched|POST|/appointments/$RID/reschedule|{\"start\":\"$SLOT\",\"expect_sequence\":0}"
	"status|POST|/appointments/$RID/status|{\"status\":\"cancelled\"}"
	"extend|POST|/appointments/$RID/extend-hold|{\"hours\":24}"
	"avail|GET|/availability?product=$PID&day=$DAY|-"
	"sched_get|GET|/schedule|-"
	"sched_put|PUT|/schedule|{\"days\":[[],[],[],[],[],[],[]]}"
	"rules_get|GET|/rules|-"
	"rules_put|PUT|/rules|{\"leadHours\":3}"
	"exc_get|GET|/exceptions|-"
	"exc_post|POST|/exceptions|{\"date\":\"2030-01-01\",\"type\":\"closed\"}"
	"exc_del|DELETE|/exceptions/$EXC_ID|-"
)

for row in "${ROUTES[@]}"; do
	IFS='|' read -r n m p b <<<"$row"
	req "$n.anon" "$m" "$p" "$b"
	check "$m $p çerezsiz" 401 rest_forbidden "$n.anon"
	req "$n.nononce" "$m" "$p" "$b" "${ADMIN_C[@]}"
	check "$m $p yönetici çerezi, nonce yok" 401 rest_forbidden "$n.nononce"
	req "$n.badnonce" "$m" "$p" "$b" "${ADMIN_C[@]}" -H 'X-WP-Nonce: 0123456789'
	check "$m $p yönetici çerezi, geçersiz nonce" 403 rest_cookie_invalid_nonce "$n.badnonce"
	req "$n.sub" "$m" "$p" "$b" "${SUB_C[@]}" -H "X-WP-Nonce: $SUB_NONCE"
	check "$m $p abone, geçerli nonce" 403 rest_forbidden "$n.sub"
	if [ "$m" = "GET" ]; then
		req "$n.ok" "$m" "$p" "$b" "${ADMIN_C[@]}" -H "X-WP-Nonce: $ADMIN_NONCE"
		check "$m $p yönetici, geçerli nonce" 200 "" "$n.ok"
	fi
done

# Lisans uçları (Task 17): yetki manage_options — mağaza yöneticisi (manage_woocommerce) de 403. Yazan uçların
# başarı yolu PHPUnit'te (sahte sunucu); burada hiçbir reddedilen istek lisans kaydını değiştirmez.
LICENSE_ROUTES=(
	"lic_get|GET|/license|-"
	"lic_act|POST|/license/activate|{\"key\":\"ABCD-EFGH-IJKL-MNOP\"}"
	"lic_deact|POST|/license/deactivate|{}"
	"lic_val|POST|/license/validate|{}"
)
for row in "${LICENSE_ROUTES[@]}"; do
	IFS='|' read -r n m p b <<<"$row"
	req "$n.anon" "$m" "$p" "$b"
	check "$m $p çerezsiz" 401 rest_forbidden "$n.anon"
	req "$n.nononce" "$m" "$p" "$b" "${ADMIN_C[@]}"
	check "$m $p yönetici çerezi, nonce yok" 401 rest_forbidden "$n.nononce"
	req "$n.badnonce" "$m" "$p" "$b" "${ADMIN_C[@]}" -H 'X-WP-Nonce: 0123456789'
	check "$m $p yönetici çerezi, geçersiz nonce" 403 rest_cookie_invalid_nonce "$n.badnonce"
	req "$n.sub" "$m" "$p" "$b" "${SUB_C[@]}" -H "X-WP-Nonce: $SUB_NONCE"
	check "$m $p abone, geçerli nonce" 403 rest_forbidden "$n.sub"
	req "$n.shop" "$m" "$p" "$b" "${SHOP_C[@]}" -H "X-WP-Nonce: $SHOP_NONCE"
	check "$m $p mağaza yöneticisi, geçerli nonce" 403 rest_forbidden "$n.shop"
done
req lic_get.ok GET /license - "${ADMIN_C[@]}" -H "X-WP-Nonce: $ADMIN_NONCE"
check "GET /license yönetici, geçerli nonce" 200 "" lic_get.ok
if grep -q '"state":' "$TMP/lic_get.ok.body" && ! grep -q 'feature_token' "$TMP/lic_get.ok.body" && ! grep -qE '"key":' "$TMP/lic_get.ok.body"; then
	PASS=$((PASS + 1))
else
	FAIL=$((FAIL + 1))
	echo "KALDI: GET /license gövdesi anahtar/token taşıyor ya da durum yok: $(head -c 200 "$TMP/lic_get.ok.body")" >&2
fi
LICENSE_AFTER=$(wp eval 'echo md5( serialize( get_option( "slotiva_license", null ) ) );')
if [ "$OLD_LICENSE" = "$LICENSE_AFTER" ]; then PASS=$((PASS + 1)); else FAIL=$((FAIL + 1)); echo "KALDI: reddedilen lisans istekleri kaydı değiştirdi" >&2; fi

# export.csv: indirme bağlantısı → `_wpnonce` sorgu parametresi.
req csv.anon GET /export.csv -
check "export.csv çerezsiz" 401 rest_forbidden csv.anon
req csv.nononce GET /export.csv - "${ADMIN_C[@]}"
check "export.csv yönetici çerezi, _wpnonce yok" 401 rest_forbidden csv.nononce
req csv.headeronly GET /export.csv - "${ADMIN_C[@]}" -H "X-WP-Nonce: $ADMIN_NONCE"
check "export.csv yönetici, nonce yalnız başlıkta" 403 slotiva_invalid_nonce csv.headeronly
req csv.badnonce GET "/export.csv?_wpnonce=0123456789" - "${ADMIN_C[@]}"
check "export.csv yönetici, geçersiz _wpnonce" 403 rest_cookie_invalid_nonce csv.badnonce
req csv.sub GET "/export.csv?_wpnonce=$SUB_NONCE" - "${SUB_C[@]}"
check "export.csv abone, geçerli _wpnonce" 403 rest_forbidden csv.sub
req csv.ok GET "/export.csv?_wpnonce=$ADMIN_NONCE" - "${ADMIN_C[@]}"
check "export.csv yönetici, geçerli _wpnonce" 200 "" csv.ok

H="$TMP/csv.ok.head"
hdr() { tr -d '\r' < "$H" | awk -F': ' -v k="$1" 'tolower($1)==tolower(k){print $2}'; }
csv_ok=1
[ "$(hdr Content-Type)" = "text/csv; charset=utf-8" ] || { echo "KALDI: Content-Type '$(hdr Content-Type)'" >&2; csv_ok=0; }
[ "$(hdr X-Content-Type-Options)" = "nosniff" ] || { echo "KALDI: nosniff yok" >&2; csv_ok=0; }
hdr Cache-Control | grep -q no-store || { echo "KALDI: no-cache başlığı yok" >&2; csv_ok=0; }
hdr Content-Disposition | grep -q '^attachment; filename="slotiva-' || { echo "KALDI: Content-Disposition" >&2; csv_ok=0; }
[ "$(head -c 3 "$TMP/csv.ok.body" | od -An -tx1 | tr -d ' \n')" = "efbbbf" ] || { echo "KALDI: BOM yok" >&2; csv_ok=0; }
grep -q 'E2E Test' "$TMP/csv.ok.body" || { echo "KALDI: CSV satırı yok" >&2; csv_ok=0; }
head -c 4 "$TMP/csv.ok.body" | grep -q '{' && { echo "KALDI: gövde JSON" >&2; csv_ok=0; }
if [ "$csv_ok" = 1 ]; then PASS=$((PASS + 1)); else FAIL=$((FAIL + 1)); fi
echo "CSV başlıkları: $(hdr Content-Type) | $(hdr X-Content-Type-Options) | $(hdr Content-Disposition)"

# Büyük harfli yol (çekirdek rotayı @i ile eşler) de ham CSV sunar (M8).
req csv.upper GET "/EXPORT.CSV?_wpnonce=$ADMIN_NONCE" - "${ADMIN_C[@]}"
check "EXPORT.CSV (büyük harf) yönetici" 200 "" csv.upper
if [ "$(head -c 3 "$TMP/csv.upper.body" | od -An -tx1 | tr -d ' \n')" = "efbbbf" ]; then PASS=$((PASS + 1)); else FAIL=$((FAIL + 1)); echo "KALDI: büyük harfli yol ham CSV değil: $(head -c 60 "$TMP/csv.upper.body")" >&2; fi

# Reddedilen yazma istekleri hiçbir şeyi değiştirmedi.
SNAP_AFTER=$(wp eval "\$r = MHM\Slotiva\Reservation\ReservationRepository::find( $RID ); echo \$r->status . '|' . \$r->sequence . '|' . \$r->startsAt->format( 'c' );")
RULES_AFTER=$(wp eval 'echo md5( serialize( get_option( "slotiva_rules", null ) ) );')
STATE=$(wp eval "
global \$wpdb;
\$n = (int) \$wpdb->get_var( \$wpdb->prepare( 'SELECT COUNT(*) FROM ' . MHM\Slotiva\Db\Schema::table( 'reservations' ) . ' WHERE product_id = %d', $PID ) );
\$e = count( MHM\Slotiva\Schedule\ExceptionRepository::between( '2030-01-01', '2030-01-02' ) );
\$s = count( array_filter( MHM\Slotiva\Schedule\WeeklySchedule::load() ) );
echo \"\$n|\$e|\$s\";
")
if [ "$SNAP_BEFORE" = "$SNAP_AFTER" ] && [ "$OLD_RULES" = "$RULES_AFTER" ] && [ "$STATE" = "1|1|7" ]; then
	PASS=$((PASS + 1))
else
	FAIL=$((FAIL + 1))
	echo "KALDI: durum değişti: [$SNAP_BEFORE] → [$SNAP_AFTER], kurallar $OLD_RULES → $RULES_AFTER, sayılar $STATE (beklenen 1|1|7)" >&2
fi

# Temizlik: istisna (betik ekledi).
wp eval "MHM\Slotiva\Schedule\ExceptionRepository::delete( $EXC_ID );" >/dev/null || true

echo "Denetim: $PASS geçti, $FAIL kaldı."
[ "$FAIL" = 0 ] && echo "GEÇTİ: yönetim uçları çerez + nonce + yetki ile korunuyor; CSV ham ve güvenli başlıklarla." && exit 0
exit 1
