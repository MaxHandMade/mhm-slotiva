#!/usr/bin/env bash
#
# slotiva-dev yiginini ayaga kaldirir ve tarayicida denenebilir hale getirir:
# WP kurulu (tr_TR) + WooCommerce 11.2.1 + TRY + blok odeme sayfasi + BACS/KOD + ornek urunler + test DB.
# Idempotent: tekrar kosmak urun cogaltmaz.
#
# Kullanim: bash docker/seed.sh [--demo]   -> http://localhost:8110
#   --demo: sonunda docker/demo-seed.sh'i (TR) da kosar (hizmetler, saatler, ornek rezervasyonlar).

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

[ -f .env ] || { cp .env.example .env; echo "docker/.env .env.example'dan olusturuldu"; }
# shellcheck disable=SC1091
. ./.env

WC_VERSION="11.2.1"
# 11c (2026-11-15): kanonik yerel ad Traefik üzerinden; localhost:${WP_PORT} yedek giriş olarak açık kalır.
SITE_URL="${SITE_URL:-http://slotiva-dev.localhost}"
ADMIN_USER="admin"
# Parolalar .env'de (gitignored) yasar; bos olanlar bu yigin icin uretilir. Depoda parola yok.
gen() { head -c 18 /dev/urandom | base64 | tr -d '/+=' | cut -c1-16; }
fill() { # fill ANAHTAR -> bossa uret, .env'e yaz, ortama al
	local key="$1" cur="${!1:-}"
	if [ -z "$cur" ]; then
		cur="$(gen)"
		if grep -q "^${key}=" .env; then sed -i "s/^${key}=.*/${key}=${cur}/" .env; else echo "${key}=${cur}" >> .env; fi
		echo "${key} uretildi, docker/.env'e yazildi"
	fi
	printf -v "$key" '%s' "$cur"
}
fill MYSQL_PASSWORD
fill MYSQL_ROOT_PASSWORD
fill WP_ADMIN_PASS
export MYSQL_PASSWORD MYSQL_ROOT_PASSWORD
ADMIN_PASS="$WP_ADMIN_PASS"

export MSYS_NO_PATHCONV=1
wp() { docker compose exec -T wpcli wp "$@"; }

echo "[1/8] Konteynerler (wpcli imaji ilk seferde test kutuphanesini indirir)..."
docker compose up -d --build

echo "[2/8] Veritabani bekleniyor..."
ready=0
for _ in $(seq 1 90); do
	if docker compose exec -T db mysqladmin ping -uroot -p"${MYSQL_ROOT_PASSWORD}" --silent >/dev/null 2>&1; then ready=1; break; fi
	sleep 2
done
[ "$ready" -eq 1 ] || { docker compose logs --tail 30 db; exit 1; }
for _ in $(seq 1 30); do wp db check >/dev/null 2>&1 && break; sleep 2; done

echo "[3/8] Test veritabani (${WP_TESTS_DB_NAME}) olusturuluyor, yetki veriliyor..."
docker compose exec -T db mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" -e \
	"CREATE DATABASE IF NOT EXISTS \`${WP_TESTS_DB_NAME}\`; GRANT ALL ON \`${WP_TESTS_DB_NAME}\`.* TO '${MYSQL_USER}'@'%'; FLUSH PRIVILEGES;" 2>/dev/null

echo "[4/8] WordPress kuruluyor..."
if ! wp core is-installed >/dev/null 2>&1; then
	wp core install --url="$SITE_URL" --title="MHM Slotiva Dev" --admin_user="$ADMIN_USER" \
		--admin_password="$ADMIN_PASS" --admin_email="admin@localhost.test" --skip-email
fi
wp language core install tr_TR --activate >/dev/null
wp option update timezone_string "Europe/Istanbul" >/dev/null

echo "[5/8] WooCommerce ${WC_VERSION}..."
wp plugin is-installed woocommerce >/dev/null 2>&1 || wp plugin install woocommerce --version="$WC_VERSION"
wp plugin activate woocommerce
wp option update woocommerce_coming_soon no >/dev/null
wp option update woocommerce_store_pages_only no >/dev/null
wp option update woocommerce_currency TRY >/dev/null
wp option update woocommerce_default_country "TR:34" >/dev/null
wp option update woocommerce_cheque_settings '{"enabled":"no"}' --format=json >/dev/null

echo "[6/8] Blok odeme sayfasi, BACS + KOD..."
wp eval '
$page_id = (int) get_option( "woocommerce_checkout_page_id" );
if ( $page_id ) {
	wp_update_post( array( "ID" => $page_id, "post_content" => "<!-- wp:woocommerce/checkout --><div class=\"wp-block-woocommerce-checkout alignwide wc-block-checkout is-loading\"><!-- wp:woocommerce/checkout-fields-block --><div class=\"wp-block-woocommerce-checkout-fields-block\"><!-- wp:woocommerce/checkout-express-payment-block --><div class=\"wp-block-woocommerce-checkout-express-payment-block\"></div><!-- /wp:woocommerce/checkout-express-payment-block --><!-- wp:woocommerce/checkout-contact-information-block --><div class=\"wp-block-woocommerce-checkout-contact-information-block\"></div><!-- /wp:woocommerce/checkout-contact-information-block --><!-- wp:woocommerce/checkout-billing-address-block --><div class=\"wp-block-woocommerce-checkout-billing-address-block\"></div><!-- /wp:woocommerce/checkout-billing-address-block --><!-- wp:woocommerce/checkout-payment-block --><div class=\"wp-block-woocommerce-checkout-payment-block\"></div><!-- /wp:woocommerce/checkout-payment-block --><!-- wp:woocommerce/checkout-actions-block --><div class=\"wp-block-woocommerce-checkout-actions-block\"></div><!-- /wp:woocommerce/checkout-actions-block --></div><!-- /wp:woocommerce/checkout-fields-block --><!-- wp:woocommerce/checkout-totals-block --><div class=\"wp-block-woocommerce-checkout-totals-block\"><!-- wp:woocommerce/checkout-order-summary-block --><div class=\"wp-block-woocommerce-checkout-order-summary-block\"></div><!-- /wp:woocommerce/checkout-order-summary-block --></div><!-- /wp:woocommerce/checkout-totals-block --></div><!-- /wp:woocommerce/checkout -->" ) );
}
update_option( "woocommerce_bacs_settings", array( "enabled" => "yes", "title" => "Havale/EFT", "description" => "Banka havalesi ile odeme." ) );
update_option( "woocommerce_cod_settings", array( "enabled" => "yes", "title" => "Kapida odeme", "description" => "Kapida nakit odeme." ) );
echo "checkout page " . $page_id . "\n";
'

echo "[7/8] Ornek urunler..."
wp eval '
$defs = array(
	"Danışmanlık" => array( "price" => "3500", "dur" => 30, "min" => 1, "max" => 1, "pricing" => "fixed", "enabled" => "yes" ),
	"Birebir ders" => array( "price" => "750", "dur" => 60, "min" => 1, "max" => 4, "pricing" => "per_slot", "enabled" => "yes" ),
);
foreach ( $defs as $name => $d ) {
	$exists = get_posts( array( "post_type" => "product", "title" => $name, "post_status" => "any", "numberposts" => 1 ) );
	if ( $exists ) { echo "var: $name\n"; continue; }
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_status( "publish" );
	$p->set_virtual( true );
	$p->set_regular_price( $d["price"] );
	$p->update_meta_data( "_slotiva_enabled", $d["enabled"] );
	$p->update_meta_data( "_slotiva_duration", $d["dur"] );
	$p->update_meta_data( "_slotiva_min_slots", $d["min"] );
	$p->update_meta_data( "_slotiva_max_slots", $d["max"] );
	$p->update_meta_data( "_slotiva_pricing", $d["pricing"] );
	echo "olustu: $name #" . $p->save() . "\n";
}
'

echo "[8/8] mhm-slotiva etkinlestiriliyor..."
wp plugin activate mhm-slotiva
wp rewrite structure "/%postname%/" --hard >/dev/null 2>&1 || true
wp cache flush >/dev/null

echo
echo "Hazir."
echo "  Site       : ${SITE_URL}  (yedek: http://localhost:${WP_PORT:-8110})"
echo "  Yonetici   : ${SITE_URL}/wp-admin  -  ${ADMIN_USER} / (docker/.env WP_ADMIN_PASS)"
echo "  phpMyAdmin : http://localhost:${PMA_PORT}   Mailpit: http://localhost:${MAILPIT_PORT}"
echo "  Test       : docker compose -f docker/docker-compose.yml exec -T wpcli vendor/bin/phpunit"

if [ "${1:-}" = "--demo" ]; then
	echo
	echo "Demo veri (docker/demo-seed.sh)..."
	bash ./demo-seed.sh
fi
