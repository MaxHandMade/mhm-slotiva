#!/usr/bin/env bash
#
# slotiva-dev icin tekrarlanabilir demo veri (ekran goruntuleri, tarayici turlari). Tek kisilik isletme gorunumu:
# 4 hizmet, ogle arali haftalik saatler, kapali gun + ek acik gun, her durumdan 18 rezervasyon (gecmis/gelecek 14 gun).
#
# Kullanim: bash docker/demo-seed.sh [--lang=tr|en] [--reset] [--rebuild]
#   (varsayilan)  TR: tr_TR/TRY, "Ornek Musteri N". Demo zaten varsa ve dil ayniysa HICBIR sey degismez.
#   --lang=en     en_US/USD, site saat dilimi Europe/London, "Sample Customer N", (555) 010-00NN telefonlar, Ingilizce hizmet adlari
#                 (demo baska dildeyse yeniden kurulur). Her iki dilde genel iptal politikasi metni de yazilir.
#   --reset       site dilini/para birimini tr_TR/TRY'ye, onceki saat dilimini ve iptal politikasi metnini geri yukler; demo veriyi SILMEZ.
#   --rebuild     yalniz `_slotiva_demo` isaretli nesneleri silip yeniden kurar (gun kaydikca tarihleri tazelemek icin).
#
# R32: dokunulan nesneler isaretli demo nesnelerdir (urun/siparis meta `_slotiva_demo`, istisna notu `[demo]`);
# onceden var olan veriye (QA artiklari, #117 vb.) dokunulmaz. TEK ISTISNA: demo calisma saatleri isaretsiz
# `slotiva_schedule` seceneginin ustune yazilir; onceki deger `slotiva_demo_prev_weekly`te saklanir ve --reset
# tr_TR/TRY ile birlikte geri yukleyip saklama secenegini siler.
# Herhangi bir yazmadan once docker/.backups/ altina DB yedegi alinir (otomatik yedekler: son 5 tutulur).
# Rezervasyonlar eklentinin kendi servisleriyle olusur (bkz. demo/seed.php). Test DB'sine (slotiva_tests) dokunmaz.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

[ -f .env ] || { echo "docker/.env yok: once bash docker/seed.sh"; exit 1; }
# shellcheck disable=SC1091
. ./.env
export MYSQL_PASSWORD MYSQL_ROOT_PASSWORD
export MSYS_NO_PATHCONV=1
wp() { docker compose exec -T wpcli wp "$@"; }
PLUGIN=/var/www/html/wp-content/plugins/mhm-slotiva
SEED="$PLUGIN/docker/demo/seed.php"

LANG_SEL="tr"; RESET=0; REBUILD=0
for a in "$@"; do
	case "$a" in
		--lang=tr) LANG_SEL="tr" ;;
		--lang=en) LANG_SEL="en" ;;
		--reset) RESET=1 ;;
		--rebuild) REBUILD=1 ;;
		*) echo "bilinmeyen secenek: $a (kullanim: --lang=tr|en --reset --rebuild)"; exit 1 ;;
	esac
done

backup_db() { # her yazmadan ONCE; yalniz otomatik yedekler (slotiva-demo-seed-auto-*) budanir
	mkdir -p .backups
	local f=".backups/slotiva-demo-seed-auto-$(date +%Y%m%d-%H%M%S).sql"
	wp db export - 2>/dev/null > "$f"
	[ -s "$f" ] || { echo "DB yedegi alinamadi"; rm -f "$f"; exit 1; }
	echo "DB yedegi: docker/$f"
	# shellcheck disable=SC2012
	ls -1t .backups/slotiva-demo-seed-auto-*.sql | tail -n +6 | xargs -r rm -f
}

switch_site() { # switch_site tr|en  -> dil (core) + para birimi / odeme basliklari
	local loc="tr_TR"; [ "$1" = "en" ] && loc="en_US"
	wp site switch-language "$loc" >/dev/null
	wp eval-file "$SEED" locale "$1" >/dev/null
}

if [ "$RESET" -eq 1 ]; then
	backup_db
	switch_site tr
	wp eval-file "$SEED" restore tr | tr -d ''
	echo "Site tr_TR / TRY'ye dondu (onceki calisma saatleri geri yuklendi); demo veri silinmedi."
	exit 0
fi

mode="seed"; [ "$REBUILD" -eq 1 ] && mode="rebuild"
target_loc="tr_TR/TRY"; [ "$LANG_SEL" = "en" ] && target_loc="en_US/USD"
state="$(wp eval-file "$SEED" status "$LANG_SEL" | tr -d '')"
cur_loc="$(wp eval 'echo get_locale() . "/" . get_option( "woocommerce_currency" );' | tr -d '')"
if [ "$state" = "ok" ] && [ "$mode" = "seed" ] && [ "$cur_loc" = "$target_loc" ]; then
	echo "Demo veri zaten var ($LANG_SEL); degisiklik yok."
	exit 0
fi

backup_db # ilk yazmadan once
switch_site "$LANG_SEL"
wp eval-file "$SEED" "$mode" "$LANG_SEL"
wp cache flush >/dev/null
echo
echo "Site: ${SITE_URL:-http://slotiva-dev.localhost}  (yonetim: /wp-admin, WP_ADMIN_PASS docker/.env'de)"
