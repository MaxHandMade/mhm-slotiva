#!/usr/bin/env bash
# Çeviri hattı (Docker wp-cli): .pot üret → tr_TR .po güncelle → .mo + .l10n.php + JS JSON → denetim.
# Kaynak dizgeler İngilizce; Türkçe .po zorunlu (boş msgstr 0, fuzzy 0, yer tutucular eşleşir).
# JS dizgeleri derlenmiş build/ dosyalarından taranır: JSON adı, WP'nin kayıtlı betik yolundan (build/admin/x.js)
# ürettiği md5 ile eşleşsin diye. Önce `npm run build`.
#
# Kullanım: bash bin/i18n.sh            (depo kökünden)
set -euo pipefail
cd "$( dirname "$0" )/.."

WPCLI=( docker compose -f docker/docker-compose.yml exec -T -w /var/www/html/wp-content/plugins/mhm-slotiva wpcli )
export MSYS_NO_PATHCONV=1

[ -f build/checkout/index.js ] || { echo "build/ yok: önce npm run build" >&2; exit 1; }

"${WPCLI[@]}" wp i18n make-pot . languages/mhm-slotiva.pot --domain=mhm-slotiva \
	--exclude='node_modules,vendor,tests,docker,bin,dist,src-react/**/*.js,.superpowers' \
	--headers='{"Report-Msgid-Bugs-To":"https://wpalemi.com","Last-Translator":"MaxHandMade"}' --allow-root
"${WPCLI[@]}" wp i18n update-po languages/mhm-slotiva.pot languages/mhm-slotiva-tr_TR.po --allow-root
"${WPCLI[@]}" wp i18n make-mo languages/mhm-slotiva-tr_TR.po languages/ --allow-root
"${WPCLI[@]}" wp i18n make-php languages/mhm-slotiva-tr_TR.po languages/ --allow-root
# Eski JSON'lar silinir, sonra yeniden üretilir (--no-purge: .po'daki JS dizgeleri korunur).
rm -f languages/mhm-slotiva-tr_TR-*.json
"${WPCLI[@]}" wp i18n make-json languages/mhm-slotiva-tr_TR.po languages/ --no-purge --allow-root

# Denetim: her biçim kendi kaynağıyla (msgstr[0] ↔ msgid, msgstr[1..] ↔ msgid_plural) yer tutucu kümesince eşleşir.
python -X utf8 bin/i18n-check.py languages/mhm-slotiva-tr_TR.po
