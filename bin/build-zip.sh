#!/usr/bin/env bash
#
# Slotiva dağıtım ZIP'i: BEYAZ LİSTE ile, Docker içinde üretilir (.distignore YOKTUR).
#
#   bin/build-zip.sh            ZIP üretir: dist/mhm-slotiva-<sürüm>.zip, ardından doğrular
#   bin/build-zip.sh --verify   dist/ altındaki mevcut ZIP'i doğrular
#
# Beyaz liste (kök): mhm-slotiva.php uninstall.php readme.txt LICENSE composer.json src/ build/ templates/ languages/
# composer.json gönderilir (R41): vendor/ taşıyan pakette WP.org incelemesi onu ister (Plugin Check missing_composer_json_file).
# composer.lock gönderilmez.
# vendor/: composer install --no-dev; mhm/ui-core kendi beyaz listesiyle + Pro/geliştirme dosyaları açıkça dışlanarak budanır (pro-kit yok).
# Doğrulama: ZIP içeriği git dosya listesine (beyaz liste ile süzülmüş) karşı karşılaştırılır;
# yasak desenler köke bağlıdır (vendor/ içindeki src-react/, tests/ vb. ui-core beyaz listesiyle ayrıca denetlenir).
#
# Docker dışında çalıştırılmaz: yerel PHP/Composer sürümü ZIP'e sızmasın.

set -euo pipefail

SLUG="mhm-slotiva"
ROOT_ALLOW="mhm-slotiva.php uninstall.php readme.txt LICENSE composer.json src build templates languages"
UICORE_ALLOW="bootstrap.php register.php src assets build composer.json LICENSE"
# ui-core'dan açıkça dışlananlar (Rentiva Lite .distignore ile aynı): Pro yüzeyi + CI araçları, çalışma anında yolu yok.
UICORE_EXCLUDE="src/Cli src/Seam/PurityScanner.php src-react assets/react/pro.css src/Kit/IconConceptScanner.php"
IMAGE="${SLOTIVA_ZIP_IMAGE:-composer:2}"

MODE="build"
[ "${1-}" = "--verify" ] && MODE="verify"

if [ -z "${SLOTIVA_IN_DOCKER-}" ]; then
	command -v docker > /dev/null 2>&1 || { echo "docker bulunamadı: ZIP yalnız Docker içinde üretilir." >&2; exit 1; }
	REPO="$(cd "$(dirname "$0")/.." && pwd)"
	mkdir -p "$REPO/dist"
	# Git dosya listesi konteynere ana makineden verilir (konteynerde git yok).
	git -C "$REPO" ls-files > "$REPO/dist/.git-files.txt"
	MSYS_NO_PATHCONV=1 docker run --rm \
		-e SLOTIVA_IN_DOCKER=1 -e MODE="$MODE" \
		-v "$REPO":/src -w /src "$IMAGE" \
		sh -c "apk add --no-cache bash zip unzip > /dev/null && bash bin/build-zip.sh ${1-}"
	rm -f "$REPO/dist/.git-files.txt"
	exit 0
fi

# ---- Konteyner içi ----
cd /src
VERSION=$(sed -n "s/^define( 'SLOTIVA_VERSION', '\([^']*\)' );/\1/p" mhm-slotiva.php | head -n 1)
[ -n "$VERSION" ] || { echo "SLOTIVA_VERSION okunamadı." >&2; exit 1; }
ZIP="/src/dist/${SLUG}-${VERSION}.zip"

build() {
	local stage=/tmp/stage
	rm -rf "$stage" "$ZIP"
	mkdir -p "$stage/$SLUG" /src/dist
	for item in $ROOT_ALLOW; do
		[ -e "$item" ] && cp -R "$item" "$stage/$SLUG/$item"
	done
	cp composer.lock "$stage/$SLUG/" 2>/dev/null || true
	(cd "$stage/$SLUG" && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader)

	# ui-core: yalnız kendi beyaz listesi.
	local uic="$stage/$SLUG/vendor/mhm/ui-core"
	if [ -d "$uic" ]; then
		for entry in "$uic"/* "$uic"/.[!.]*; do
			[ -e "$entry" ] || continue
			case " $UICORE_ALLOW " in
				*" $(basename "$entry") "*) ;;
				*) rm -rf "$entry" ;;
			esac
		done
		for ex in $UICORE_EXCLUDE; do rm -rf "${uic:?}/$ex"; done
	fi
	# vendor/ içinde kalan geliştirme artıkları (bin/, .git*) paketlenmez.
	find "$stage/$SLUG/vendor" -name '.git*' -prune -exec rm -rf {} + 2>/dev/null || true
	rm -rf "$stage/$SLUG/vendor/bin"

	# Budamadan sonra autoloader yeniden üretilir: -o classmap'i diskten taradığı için dışlanan dosyalar
	# (PurityScanner vb.) classmap'te kalmaz; ZIP'te olmayan sınıf sessizce yayına çıkmaz (D5 ayrıca doğrular).
	(cd "$stage/$SLUG" && composer dump-autoload --no-dev --optimize --no-interaction)
	rm -f "$stage/$SLUG/composer.lock"

	(cd "$stage" && zip -r -q "$ZIP" "$SLUG")
	echo "ZIP: $ZIP"
}

verify() {
	[ -f "$ZIP" ] || { echo "ZIP yok: $ZIP (önce bin/build-zip.sh)" >&2; exit 1; }
	local list=/tmp/zip-list.txt expected=/tmp/expected.txt fail=0
	unzip -Z1 "$ZIP" | grep -v '/$' | sort > "$list"

	# 1) Beklenen: git dosya listesinin beyaz liste ile süzülmüşü.
	: > "$expected"
	while IFS= read -r f; do
		for item in $ROOT_ALLOW; do
			if [ "$f" = "$item" ] || [[ "$f" == "$item"/* ]]; then
				echo "$SLUG/$f" >> "$expected"
			fi
		done
	done < /src/dist/.git-files.txt
	sort -o "$expected" "$expected"

	# build/ git'te değildir (derlenmiş çıktı); vendor/ composer'dan gelir. Bunlar dışında iki liste eşit olmalı.
	local zip_tracked
	zip_tracked=$(grep -v -E "^$SLUG/(vendor|build)/" "$list" || true)
	local missing extra
	missing=$(comm -13 <(printf '%s\n' "$zip_tracked" | sort) "$expected" || true)
	extra=$(comm -23 <(printf '%s\n' "$zip_tracked" | sort) "$expected" || true)
	if [ -n "$missing" ]; then echo "ZIP'te EKSİK (git'te var, beyaz listede):"; echo "$missing"; fail=1; fi
	if [ -n "$extra" ]; then echo "ZIP'te FAZLA (git'te yok):"; echo "$extra"; fail=1; fi

	# 2) Yasak desenler, köke bağlı.
	local forbidden
	forbidden=$(grep -E "^$SLUG/(tests|docker|src-react|node_modules|bin|docs|\.github|\.superpowers)/|^$SLUG/[^/]+\.md$|^$SLUG/(composer\.lock|package(-lock)?\.json|phpunit\.xml\.dist|phpcs\.xml\.dist|phpstan\.neon\.dist|webpack\.config\.js)$" "$list" || true)
	if [ -n "$forbidden" ]; then echo "YASAK dosyalar:"; echo "$forbidden"; fail=1; fi

	# 3) ui-core yalnız kendi beyaz listesi.
	local uic_bad
	uic_bad=$(grep -E "^$SLUG/vendor/mhm/ui-core/" "$list" | sed "s#^$SLUG/vendor/mhm/ui-core/##" | awk -F/ '{print $1}' | sort -u | while read -r top; do
		case " $UICORE_ALLOW " in *" $top "*) ;; *) echo "$top" ;; esac
	done)
	if [ -n "$uic_bad" ]; then echo "ui-core beyaz liste dışı:"; echo "$uic_bad"; fail=1; fi

	# 3b) ui-core Pro yüzeyi ve geliştirme araçları ZIP'te olmamalı; pro-kit tümden çıktı.
	local pat
	local forbidden_paths=(
		"vendor/mhm/ui-core/src/Seam/PurityScanner.php"
		"vendor/mhm/ui-core/assets/react/pro.css"
		"vendor/mhm/ui-core/src/Cli/"
		"vendor/mhm/ui-core/src/Kit/IconConceptScanner"
		"vendor/mhm/ui-core/src-react/"
		"vendor/mhm/pro-kit/"
	)
	for pat in "${forbidden_paths[@]}"; do
		if grep -q "^$SLUG/$pat" "$list"; then echo "YASAK ZIP'te: $pat"; fail=1; fi
	done

	# 3c) D5: autoload_files.php ve autoload_classmap.php'nin gösterdiği her yol ZIP içinde var olmalı.
	# ZIP açılıp composer'ın kendi dosyaları PHP ile çözülür; eksik dosya sessizce yayına çıkmaz.
	local chk=/tmp/zip-check
	rm -rf "$chk" && mkdir -p "$chk" && unzip -q "$ZIP" -d "$chk"
	local d5
	d5=$(php -r '
		$c = $argv[1] . "/vendor/composer/";
		foreach ( array( "autoload_files.php", "autoload_classmap.php" ) as $f ) {
			if ( ! is_file( $c . $f ) ) {
				// Yalnız autoload_files.php atlanabilir: Composer, hiçbir paket "files" tanımlamıyorsa onu üretmez.
				// Optimize dump ile classmap her zaman vardır; yokluğu başarısızlıktır.
				if ( "autoload_files.php" === $f ) { continue; }
				echo $f . " YOK" . PHP_EOL;
				continue;
			}
			foreach ( require $c . $f as $path ) {
				if ( ! file_exists( $path ) ) { echo $f . " -> " . $path . PHP_EOL; }
			}
		}' "$chk/$SLUG" || echo "D5 PHP hatası")
	rm -rf "$chk"
	if [ -n "$d5" ]; then echo "D5: autoload yolu ZIP'te yok:"; echo "$d5"; fail=1; fi

	# 4) Zorunlu içerik.
	for must in "$SLUG/mhm-slotiva.php" "$SLUG/vendor/autoload.php" "$SLUG/LICENSE"; do
		grep -qx "$must" "$list" || { echo "ZORUNLU yok: $must"; fail=1; }
	done

	[ "$fail" -eq 0 ] && echo "VERIFY OK: $(wc -l < "$list") dosya" || { echo "VERIFY BAŞARISIZ" >&2; exit 1; }
}

if [ "${MODE-build}" = "verify" ]; then
	verify
else
	build
	verify
fi
