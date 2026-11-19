#!/usr/bin/env bash
#
# docker/demo-seed.sh dogrulamasi. CANLI slotiva-dev yiginina karsi kosar (CI'da degil; PHPUnit degil).
# Kontrol: (1) durum basina sayilar data.php'deki beklentiyle ayni, (2) ikinci kosu hicbir sey degistirmez,
# (3) --lang=en EN/USD + "Sample Customer", (4) --reset tr_TR/TRY dondurur ve demo veriyi silmez,
# (5) demo olmayan veri ve slotiva_tests degismez.
#
# Kullanim: bash tests/e2e/demo-seed-assert.sh      (cikis 0 = hepsi gecti)

set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../../docker"
# shellcheck disable=SC1091
. ./.env
export MSYS_NO_PATHCONV=1
wp() { docker compose exec -T wpcli wp "$@"; }
PLUGIN=/var/www/html/wp-content/plugins/mhm-slotiva
FAIL=0
ok() { echo "  ok   - $1"; }
bad() { echo "  FAIL - $1"; FAIL=1; }
eq() { # bos taraf (probe/beklenen) sahte gecis olmasin
	if [ -z "$2" ] || [ -z "$3" ]; then bad "$1: bos deger (gelen '$2', beklenen '$3')"
	elif [ "$2" = "$3" ]; then ok "$1 ($3)"; else bad "$1: beklenen '$3', gelen '$2'"; fi; }

# Tek wp eval: durum sayilari, parmak izleri ve site ayarlari. Cikti "anahtar=deger" satirlari.
probe() {
	wp eval '
	global $wpdb;
	$d   = require "'"$PLUGIN"'/docker/demo/data.php";
	$res = MHM\Slotiva\Db\Schema::table( "reservations" );
	$ids = get_posts( array( "post_type" => "product", "post_status" => "any", "numberposts" => -1, "fields" => "ids", "meta_key" => "_slotiva_demo", "meta_value" => "1" ) );
	$in  = $ids ? implode( ",", array_map( "intval", $ids ) ) : "0";
	echo "products=" . count( $ids ) . "\n";
	echo "total=" . (int) $wpdb->get_var( "SELECT COUNT(*) FROM $res WHERE product_id IN ($in)" ) . "\n";
	foreach ( array_keys( $d["expected"]["status"] ) as $s ) {
		echo "status_$s=" . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $res WHERE product_id IN ($in) AND status=%s", $s ) ) . "\n";
	}
	foreach ( array_keys( $d["expected"]["confirmed_pay"] ) as $p ) {
		echo "pay_$p=" . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $res WHERE product_id IN ($in) AND status=%s AND payment_state=%s", "confirmed", $p ) ) . "\n";
	}
	$orders = wc_get_orders( array( "limit" => -1, "return" => "ids", "status" => array_keys( wc_get_order_statuses() ), "meta_key" => "_slotiva_demo", "meta_value" => "1" ) );
	echo "orders=" . count( $orders ) . "\n";
	echo "fp_demo=" . md5( (string) $wpdb->get_var( "SELECT GROUP_CONCAT(CONCAT_WS(\"|\",id,order_id,product_id,starts_at,status,payment_state,updated_at,customer_name) ORDER BY id) FROM $res WHERE product_id IN ($in)" ) . implode( ",", $ids ) . implode( ",", $orders ) ) . "\n";
	echo "fp_other=" . md5( (string) $wpdb->get_var( "SELECT GROUP_CONCAT(CONCAT_WS(\"|\",id,order_id,product_id,starts_at,status,payment_state,updated_at) ORDER BY id) FROM $res WHERE product_id NOT IN ($in)" ) . implode( ",", array_diff( wc_get_orders( array( "limit" => -1, "return" => "ids", "status" => array_keys( wc_get_order_statuses() ) ) ), $orders ) ) ) . "\n";
	echo "other_orders=" . count( array_diff( wc_get_orders( array( "limit" => -1, "return" => "ids", "status" => array_keys( wc_get_order_statuses() ) ) ), $orders ) ) . "\n";
	$sched = get_option( MHM\Slotiva\Schedule\WeeklySchedule::OPTION, null );
	echo "weekly=" . md5( serialize( $sched ) ) . "
";
	$stash = get_option( "slotiva_demo_prev_weekly", null );
	echo "stash=" . ( null === $stash ? "none" : "present" ) . "
";
	echo "stash_weekly=" . ( is_array( $stash ) ? md5( serialize( $stash["exists"] ? $stash["value"] : null ) ) : "-" ) . "
";
	echo "seeded_opt=" . ( null === get_option( "slotiva_demo_seeded", null ) ? "none" : "present" ) . "
";
	echo "fp_exc_other=" . md5( (string) $wpdb->get_var( "SELECT GROUP_CONCAT(CONCAT_WS(\"|\",id,date,from_time,to_time,type,note) ORDER BY id) FROM " . MHM\Slotiva\Db\Schema::table( "exceptions" ) . " WHERE note NOT LIKE \"[demo]%\"" ) ) . "
";
	echo "locale=" . get_locale() . "\ncurrency=" . get_option( "woocommerce_currency" ) . "\n";
	echo "tz=" . get_option( "timezone_string" ) . "\n";
	echo "policy=" . MHM\Slotiva\Schedule\Rules::load()->policyText . "\n";
	$stz = get_option( "slotiva_demo_prev_tz", null );
	echo "stash_tz=" . ( null === $stz ? "none" : "present" ) . "\n";
	echo "stash_tz_val=" . ( is_array( $stz ) ? $stz["string"] : "-" ) . "\n";
	$spo = get_option( "slotiva_demo_prev_policy", null );
	echo "stash_policy=" . ( null === $spo ? "none" : "present" ) . "\n";
	echo "stash_policy_val=" . ( is_array( $spo ) ? "v:" . $spo["value"] : "-" ) . "\n";
	echo "phone1=" . (string) $wpdb->get_var( "SELECT customer_phone FROM $res WHERE product_id IN ($in) ORDER BY id LIMIT 1" ) . "\n";
	echo "customer1=" . (string) $wpdb->get_var( "SELECT customer_name FROM $res WHERE product_id IN ($in) ORDER BY id LIMIT 1" ) . "\n";
	echo "has_lunch=" . ( 2 === count( MHM\Slotiva\Schedule\WeeklySchedule::load()[0] ) ? 1 : 0 ) . "\n";
	echo "closed_weekday=" . ( array() === MHM\Slotiva\Schedule\WeeklySchedule::load()[6] ? 1 : 0 ) . "\n";
	echo "exc_open=" . (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . MHM\Slotiva\Db\Schema::table( "exceptions" ) . " WHERE note LIKE \"[demo]%\" AND type=\"open\"" ) . "\n";
	echo "exc_closed=" . (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . MHM\Slotiva\Db\Schema::table( "exceptions" ) . " WHERE note LIKE \"[demo]%\" AND type=\"closed\"" ) . "\n";
	echo "past=" . (int) $wpdb->get_var( "SELECT COUNT(*) FROM $res WHERE product_id IN ($in) AND starts_at < UTC_TIMESTAMP() AND starts_at >= UTC_TIMESTAMP() - INTERVAL 15 DAY" ) . "\n";
	echo "future=" . (int) $wpdb->get_var( "SELECT COUNT(*) FROM $res WHERE product_id IN ($in) AND starts_at >= UTC_TIMESTAMP() AND starts_at <= UTC_TIMESTAMP() + INTERVAL 15 DAY" ) . "\n";
	' 2>/dev/null
}
val() { printf '%s\n' "$1" | grep "^$2=" | head -1 | cut -d= -f2-; }
testdb_fp() {
	docker compose exec -T db mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" -N -e \
		"SELECT COUNT(*), IFNULL(MAX(UPDATE_TIME),'-'), IFNULL(SUM(TABLE_ROWS),0) FROM information_schema.tables WHERE table_schema='${WP_TESTS_DB_NAME}'" 2>/dev/null
}
expected() { # expected anahtar [alt-anahtar]
	wp eval '$d = require "'"$PLUGIN"'/docker/demo/data.php"; $v = $d["expected"]["'"$1"'"]; echo "'"${2:-}"'" === "" ? $v : $v["'"${2:-}"'"];' 2>/dev/null
}

echo "[0] Baslangic (demo olmayan veri + slotiva_tests parmak izi)"
BEFORE_TESTDB="$(testdb_fp)"
P0="$(probe)"
OTHER0="$(val "$P0" fp_other)"
EXC0="$(val "$P0" fp_exc_other)"
# Seed oncesi saatler: saklama varsa onun degeri (demo zaten kurulu), yoksa mevcut secenek.
if [ "$(val "$P0" stash)" = "present" ]; then W0="$(val "$P0" stash_weekly)"; else W0="$(val "$P0" weekly)"; fi
# Seed oncesi saat dilimi ve politika: saklama varsa onun degeri, yoksa mevcut.
if [ "$(val "$P0" stash_tz)" = "present" ]; then TZ0="$(val "$P0" stash_tz_val)"; else TZ0="$(val "$P0" tz)"; fi
if [ "$(val "$P0" stash_policy)" = "present" ]; then POL0="$(val "$P0" stash_policy_val)"; else POL0="v:$(val "$P0" policy)"; fi
[ -n "$OTHER0" ] || { echo "probe calismadi (yigin ayakta mi?)"; exit 2; }

echo "[1] demo-seed.sh (TR)"
bash ./demo-seed.sh >/tmp/slotiva-demo-seed.out 2>&1 || { bad "demo-seed.sh basarisiz"; tail -20 /tmp/slotiva-demo-seed.out; exit 1; }
P1="$(probe)"
case "$(val "$P1" products)$(val "$P1" total)" in ""|*[!0-9]*) bad "products/total sayisal degil";; esac
[ "$(val "$P1" products)" -gt 0 ] 2>/dev/null && [ "$(val "$P1" total)" -gt 0 ] 2>/dev/null && ok "products/total > 0" || bad "products/total sifir"
eq "urun sayisi" "$(val "$P1" products)" "$(expected products)"
eq "rezervasyon toplami" "$(val "$P1" total)" "$(expected total)"
for s in held confirmed completed no_show cancelled expired; do
	eq "durum $s" "$(val "$P1" "status_$s")" "$(expected status "$s")"
done
for p in paid on_site manual; do
	eq "confirmed + $p" "$(val "$P1" "pay_$p")" "$(expected confirmed_pay "$p")"
done
eq "demo siparis" "$(val "$P1" orders)" "$(expected orders)"
eq "dil" "$(val "$P1" locale)" "tr_TR"
eq "para birimi" "$(val "$P1" currency)" "TRY"
eq "ogle arasi (Pzt 2 aralik)" "$(val "$P1" has_lunch)" "1"
eq "kapali gun (Paz bos)" "$(val "$P1" closed_weekday)" "1"
eq "ek acik gun istisnasi" "$(val "$P1" exc_open)" "1"
eq "kapali gun istisnasi" "$(val "$P1" exc_closed)" "1"
case "$(val "$P1" customer1)" in "Örnek Müşteri "*) ok "musteri adi TR";; *) bad "musteri adi TR: $(val "$P1" customer1)";; esac
[ "$(val "$P1" past)" -ge 5 ] && [ "$(val "$P1" future)" -ge 8 ] && ok "gecmis/gelecek 14 gun dagilimi ($(val "$P1" past)/$(val "$P1" future))" || bad "gecmis/gelecek dagilimi $(val "$P1" past)/$(val "$P1" future)"

eq "TR modu saat dilimine dokunmadi" "$(val "$P1" tz)" "$TZ0"
eq "demo iptal politikasi (TR)" "$(val "$P1" policy)" "Randevudan 24 saat öncesine kadar ücretsiz iptal."
case "$(val "$P1" phone1)" in "0555 000 00 "*) ok "telefon TR";; *) bad "telefon TR: $(val "$P1" phone1)";; esac

echo "[2] Ikinci kosu: degisiklik yok"
bash ./demo-seed.sh >/tmp/slotiva-demo-seed2.out 2>&1 || bad "ikinci kosu basarisiz"
P2="$(probe)"
eq "demo parmak izi" "$(val "$P2" fp_demo)" "$(val "$P1" fp_demo)"
eq "(a) ikinci kosu calisma saati secenegini degistirmedi" "$(val "$P2" weekly)" "$(val "$P1" weekly)"
eq "saklanan onceki saatler ikinci kosuda ayni" "$(val "$P2" stash_weekly)" "$(val "$P1" stash_weekly)"
eq "olu secenek slotiva_demo_seeded yok" "$(val "$P2" seeded_opt)" "none"
grep -qi "zaten\|already" /tmp/slotiva-demo-seed2.out && ok "ikinci kosu 'zaten var' dedi" || bad "ikinci kosu no-op demedi"

echo "[3] --lang=en"
bash ./demo-seed.sh --lang=en >/tmp/slotiva-demo-seed3.out 2>&1 || bad "--lang=en basarisiz"
P3="$(probe)"
eq "dil" "$(val "$P3" locale)" "en_US"
eq "para birimi" "$(val "$P3" currency)" "USD"
eq "rezervasyon toplami (EN)" "$(val "$P3" total)" "$(expected total)"
eq "demo siparis (EN)" "$(val "$P3" orders)" "$(expected orders)"
case "$(val "$P3" customer1)" in "Sample Customer "*) ok "musteri adi EN";; *) bad "musteri adi EN: $(val "$P3" customer1)";; esac
for s in held confirmed completed no_show cancelled expired; do
	eq "durum $s (EN)" "$(val "$P3" "status_$s")" "$(expected status "$s")"
done

eq "(EN) site saat dilimi Europe/London" "$(val "$P3" tz)" "Europe/London"
eq "(EN) demo iptal politikasi" "$(val "$P3" policy)" "Free cancellation up to 24 hours before."
case "$(val "$P3" phone1)" in "(555) 010-00"[0-9][0-9]) ok "telefon EN ($(val "$P3" phone1))";; *) bad "telefon EN: $(val "$P3" phone1)";; esac

echo "[4] --reset"
bash ./demo-seed.sh --reset >/tmp/slotiva-demo-seed4.out 2>&1 || bad "--reset basarisiz"
P4="$(probe)"
eq "dil" "$(val "$P4" locale)" "tr_TR"
eq "para birimi" "$(val "$P4" currency)" "TRY"
eq "(b) reset saatleri seed oncesi degere dondurdu" "$(val "$P4" weekly)" "$W0"
eq "(c) reset saklama secenegini sildi" "$(val "$P4" stash)" "none"
eq "(d) reset saat dilimini seed oncesi degere dondurdu" "$(val "$P4" tz)" "$TZ0"
eq "(e) reset politikayi seed oncesi degere dondurdu" "v:$(val "$P4" policy)" "$POL0"
eq "(f) reset saat dilimi saklamasini sildi" "$(val "$P4" stash_tz)" "none"
eq "(g) reset politika saklamasini sildi" "$(val "$P4" stash_policy)" "none"
eq "reset demo veriyi silmedi" "$(val "$P4" total)" "$(expected total)"
eq "reset demo parmak izi" "$(val "$P4" fp_demo)" "$(val "$P3" fp_demo)"

echo "[5] TR'ye donus (demo dili degisir -> yeniden kurulur)"
bash ./demo-seed.sh >/tmp/slotiva-demo-seed5.out 2>&1 || bad "TR donus basarisiz"
P5="$(probe)"
eq "rezervasyon toplami" "$(val "$P5" total)" "$(expected total)"
eq "dil" "$(val "$P5" locale)" "tr_TR"
case "$(val "$P5" customer1)" in "Örnek Müşteri "*) ok "musteri adi TR";; *) bad "musteri adi TR: $(val "$P5" customer1)";; esac

echo "[6] Demo olmayan veri ve test DB degismedi"
eq "demo olmayan rezervasyon+siparis parmak izi" "$(val "$P5" fp_other)" "$OTHER0"
eq "demo olmayan istisnalar" "$(val "$P5" fp_exc_other)" "$EXC0"
eq "TR donusunde saat dilimi seed oncesi degerde" "$(val "$P5" tz)" "$TZ0"
eq "TR donusunde politika saklamasi yeniden olustu" "$(val "$P5" stash_policy)" "present"
eq "TR donusunde saklama yeniden olustu" "$(val "$P5" stash)" "present"
eq "slotiva_tests" "$(testdb_fp)" "$BEFORE_TESTDB"

echo
if [ "$FAIL" -eq 0 ]; then echo "HEPSI GECTI"; else echo "BASARISIZ"; fi
exit "$FAIL"
