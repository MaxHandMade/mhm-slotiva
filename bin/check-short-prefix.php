<?php
/**
 * Kısa önek kapısı (spec R4/D12): ZIP'e giren dosyalardaki betik/stil tutamaçları, CSS sınıfları ve DOM kimlikleri
 * eklentiye özgü, yeterince uzun bir önek taşımalı; `mhm` ya da kısa bir önek (`zz-`) kabul edilmez.
 *
 *   php bin/check-short-prefix.php          0 = temiz, 1 = ihlal, 2 = kapı kurulamadı
 *   php bin/check-short-prefix.php --names  bulunan TÜM mhm- adlarını dosya dosya yazar (ui-core listesi için)
 *
 * Kural: ad ilk `_` ya da `-`'de bölünür; ilk parça en az 4 karakter olmalı ve `mhm` olmamalı. Yabancı (WordPress /
 * WooCommerce) adlarının sık kullanılan kısa önekleri ile durum sınıfları AÇIKÇA tek tek listelenir ($foreign).
 *
 * Kapsam (neyi arar): wp_register_ve_wp_enqueue_ tutamacı (sabit ve `self::SABIT` çözülür; çözülemeyen değişken
 * atlanır), wp_add_inline_*, wp_set_script_translations, wp_localize_script tutamacı + nesne adı, ui-core yükleyicisine
 * verilen `handle_prefix`; class/className öznitelikleri, classList/clsx/classnames, querySelector/jQuery seçicileri,
 * CSS/SCSS sınıf seçicileri; id öznitelikleri, getElementById, `#id` seçicileri.
 * Metin alanı (`mhm-slotiva`) önek ihlali DEĞİLDİR ve bilerek aranmaz: gettext alan adı, `Text Domain:`,
 * load_plugin_textdomain, wp_set_script_translations'ın İKİNCİ bağımsız değişkeni, dil dosyaları bu kapının
 * konumlarında (tutamaç/sınıf/kimlik) bulunmaz; dosya atlanarak değil, konum seçilerek dışarıda kalır.
 *
 * Tarama kümesi bin/lib/shipped-tree.php (check-no-pro-refs ile aynı türetme; ui-core yolları build-zip.sh'tan).
 * Jest testleri (__tests__, *.test.*) ZIP'e girmediği için atlanır.
 *
 * @package MHM\Slotiva
 */

declare( strict_types=1 );

require __DIR__ . '/lib/shipped-tree.php';

$base = dirname( __DIR__ );

/**
 * Yabancı ad önekleri (ilk parça). Kısa olanlar burada açıkça sayılır; başka kısa önek (zz, mhm, ...) hata verir.
 * wp/wc: WordPress ve WooCommerce çekirdek tutamaç/sınıfları (wp-components, wc-blocks); is/has: durum sınıfları (is-active).
 */
$foreign = array( 'wp', 'wc', 'is', 'has' );

/**
 * ui-core'dan gelen, GEREKÇELİ KABUL EDİLMİŞ adlar: dosya => ad listesi. Tam eşleşme; önek muafiyeti DEĞİLDİR.
 * Gerekçe (ui-core yanıtı, pano 2026-11-17 14:20): ui-core bu adları silemez/yeniden adlandıramaz — yükleyici sitedeki en
 * yüksek ui-core sürümünü yükler ve yayınlanmış Rentiva bu sınıfları basıyor. WP.org önek şartı CSS sınıflarını kapsamaz
 * (developer.wordpress.org/plugins/wordpress-org/common-issues/); kural Slotiva'nın kendi R4/D12 kuralıdır. ui-core çıkış
 * koşulu gelince (sahada hiçbir tüketici basmıyor) panoyla haber verir; o gün satırlar silinir (bayat giriş kapıyı düşürür).
 * Slotiva'nın kendi dosyasındaki `mhm-` adı gerçek ihlaldir: buraya eklenmez, `slotiva-` olarak düzeltilir.
 *
 * @var array<string, list<string>>
 */
$pending_upstream = array(
	// Accepted exemption (see docblock); not a Task 22 blocker. Dosya Lite'ta kit stili olarak yuklenir (Admin\Menu: mhmuicore_enqueue_kit('admin')),
	// bu yuzden ZIP'ten cikarilamaz; sinif adlari eski tuketici sozlugudur ve Slotiva'nin hicbir kodu bu siniflari uretmez (denetlendi).
	'vendor/mhm/ui-core/assets/react/admin.css' => array(
		'mhm-dashboard',
		'mhm-empty',
		'mhm-error',
		'mhm-kpi-box',
		'mhm-kpi-box--amber',
		'mhm-kpi-box--blue',
		'mhm-kpi-box--green',
		'mhm-kpi-box--grey',
		'mhm-kpi-box__label',
		'mhm-kpi-box__value',
		'mhm-kpi-row',
		'mhm-overdue',
		'mhm-pagination',
		'mhm-pagination__btn',
		'mhm-pagination__info',
		'mhm-stat-card',
		'mhm-stat-card__body',
		'mhm-stat-card__label',
		'mhm-stat-card__sub',
		'mhm-stat-card__value',
		'mhm-stats-grid',
		'mhm-status',
		'mhm-status--cancelled',
		'mhm-status--completed',
		'mhm-status--confirmed',
		'mhm-status--in_progress',
		'mhm-status--overdue',
		'mhm-status--pending',
		'mhm-widget',
		'mhm-widget__subtitle',
	),
);

// Test için: bekleyen listeyi JSON dosyasından değiştir (yalnız bayat-giriş denetimini sınamak için).
foreach ( $argv as $a ) {
	if ( 0 === strpos( $a, '--pending-json=' ) ) {
		$pending_upstream = (array) json_decode( (string) file_get_contents( substr( $a, 15 ) ), true );
	}
}

$exts = array_fill_keys( array( 'php', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss' ), true );

/**
 * @param list<string> $foreign Yabancı önekler.
 * @return bool Ad kısa/mhm önekli mi.
 */
function slotiva_bad_prefix( string $name, array $foreign ): bool {
	$name = trim( $name );
	if ( '' === $name || 1 !== preg_match( '/^[A-Za-z_-][\w-]*$/', $name ) ) {
		return false; // Çözülemeyen/dinamik parça: ihlal sayılmaz.
	}
	$first = preg_split( '/[_-]/', $name, 2 )[0];
	if ( '' === $first ) {
		return false;
	}
	if ( in_array( strtolower( $first ), $foreign, true ) ) {
		return false;
	}
	// Bölünmeyen ad (ör. `jquery`, `button`, `slotivaAdmin`) tek parçadır: uzunluk ve `mhm` koşulu aynen uygulanır.
	return strlen( $first ) < 4 || 'mhm' === strtolower( $first );
}

/**
 * Kaynak metnindeki sabit tanımları (const X = '...'; define('X','...')).
 *
 * @param array<string, string> $into
 */
function slotiva_collect_consts( string $src, array &$into ): void {
	if ( preg_match_all( '/\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*([\'"])([^\'"]*)\2/', $src, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $c ) {
			$into[ $c[1] ] = $c[3];
		}
	}
	if ( preg_match_all( '/\bdefine\(\s*([\'"])([A-Z][A-Z0-9_]*)\1\s*,\s*([\'"])([^\'"]*)\3/', $src, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $c ) {
			$into[ $c[2] ] = $c[4];
		}
	}
}

/**
 * Bir PHP ifadesinin SABİT öncül metni: 'x', "x", self::SABIT, SABIT; ardındaki `. $degisken` yok sayılır.
 * Çözülemezse null.
 *
 * @param array<string, string> $consts
 */
function slotiva_resolve( string $expr, array $consts ): ?string {
	$expr = trim( $expr );
	if ( 1 === preg_match( '/^([\'"])([^\'"]*)\1/', $expr, $m ) ) {
		return $m[2];
	}
	if ( 1 === preg_match( '/^(?:(?:self|static|[A-Za-z_\\\\]+)::)?([A-Z][A-Z0-9_]*)\b/', $expr, $m ) && isset( $consts[ $m[1] ] ) ) {
		return $consts[ $m[1] ];
	}
	return null;
}

/**
 * Bir `class="..."` değerinden sabit sınıf adlarını çıkarır (PHP blokları ve ${...}/{...} ifadeleri atılır).
 *
 * @return list<string>
 */
function slotiva_class_tokens( string $value ): array {
	$value = preg_replace( '/<\?(?:php|=).*?\?>/s', ' ', $value ) ?? $value;
	$value = preg_replace( '/\$\{[^}]*\}|\{[^}]*\}/', ' ', $value ) ?? $value;
	$out   = array();
	foreach ( preg_split( '/\s+/', $value ) ?: array() as $t ) {
		$t = trim( $t, "\\'\"`," );
		if ( '' !== $t && 1 === preg_match( '/^[A-Za-z_-][\w-]*$/', $t ) ) {
			$out[] = $t;
		}
	}
	return $out;
}

/**
 * CSS seçicisi parçasından sınıf ve kimlik adlarını çıkarır.
 *
 * @return list<array{0: string, 1: string}> [tür, ad]
 */
function slotiva_selector_names( string $sel ): array {
	$out = array();
	if ( preg_match_all( '/(?<!\d)\.(-?[A-Za-z_][\w-]*)(?![\w-]*\()/', $sel, $m ) ) {
		foreach ( $m[1] as $n ) {
			$out[] = array( 'class', $n );
		}
	}
	if ( preg_match_all( '/(?<![\w&-])#([A-Za-z_][\w-]*[-_][\w-]*)/', $sel, $m ) ) {
		foreach ( $m[1] as $n ) {
			$out[] = array( 'id', $n );
		}
	}
	return $out;
}

/**
 * CSS/SCSS metnindeki sınıf ve kimlik seçicileri. Yorumlar, url(...) ve metinler (`@import "x.css"`, `url(a.png)`)
 * aynı uzunlukta boşlukla doldurulur; ondalıklar (`0.5em`, `.5s`) sınıf adı rakamla başlayamadığı ve noktadan önce
 * rakam olamayacağı için, `math.div(` gibi SCSS işlev çağrıları `(` bakışıyla elenir. Bileşik seçicinin (`a.x`,
 * `.a.x`, `:not(.a).x`) her sınıfı yakalanır.
 *
 * @return list<array{0: int, 1: string, 2: string}> [ofset, tür, ad]
 */
function slotiva_css_hits( string $css, bool $scss ): array {
	$blank = static fn( array $m ): string => preg_replace( '/[^\n]/', ' ', $m[0] ) ?? '';
	$clean = preg_replace_callback( '#/\*.*?\*/|url\([^)]*\)|"(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'#s', $blank, $css ) ?? $css;
	if ( $scss ) {
		$clean = preg_replace_callback( '#(?<!:)//[^\n]*#', $blank, $clean ) ?? $clean;
	}
	$out = array();
	if ( preg_match_all( '/(?<!\d)\.(-?[A-Za-z_][\w-]*)(?![\w-]*\()|(?<![\w&-])#([A-Za-z_][\w-]*[-_][\w-]*)/'.'', $clean, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m as $hit ) {
			if ( isset( $hit[1] ) && '' !== $hit[1][0] ) {
				$out[] = array( $hit[1][1], 'class', $hit[1][0] );
			} elseif ( isset( $hit[2] ) ) {
				$out[] = array( $hit[2][1], 'id', $hit[2][0] );
			}
		}
	}
	return $out;
}

$files = slotiva_shipped_files( $base, 'check-short-prefix' );

// Sabit çözümü için tüm taranan PHP dosyalarındaki sabitler (self::HANDLE_PREFIX başka sınıftan da gelebilir).
$consts = array();
foreach ( $files as $rel ) {
	if ( 'php' === strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) ) ) {
		slotiva_collect_consts( (string) file_get_contents( $base . '/' . $rel ), $consts );
	}
}

$found = array(); // [dosya, satır, tür, ad]
$scan  = 0;
foreach ( $files as $rel ) {
	$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
	if ( ! isset( $exts[ $ext ] ) || false !== strpos( $rel, '__tests__/' ) || 1 === preg_match( '/\.test\.[jt]sx?$/', $rel ) ) {
		continue;
	}
	$src = (string) file_get_contents( $base . '/' . $rel );
	if ( 'php' === $ext ) {
		// Yorum/docblock içindeki örnek kod (`wp_enqueue_style( 'x', ...)`) gerçek kayıt değildir: aynı uzunlukta boşlukla doldurulur.
		$plain = '';
		foreach ( token_get_all( $src ) as $tok ) {
			$plain .= ( is_array( $tok ) && in_array( $tok[0], array( T_COMMENT, T_DOC_COMMENT ), true ) )
				? preg_replace( '/[^\n]/', ' ', $tok[1] )
				: ( is_array( $tok ) ? $tok[1] : $tok );
		}
		$src = $plain;
	}
	++$scan;
	$add = static function ( int $offset, string $kind, string $name ) use ( &$found, $rel, $src ): void {
		$found[] = array( $rel, substr_count( substr( $src, 0, $offset ), "\n" ) + 1, $kind, $name );
	};

	if ( 'css' === $ext || 'scss' === $ext ) {
		foreach ( slotiva_css_hits( $src, 'scss' === $ext ) as $h ) {
			$add( $h[0], $h[1], $h[2] );
		}
		continue;
	}

	// ---- PHP / JS / TS ----
	if ( 'php' === $ext && preg_match_all( '/\bwp_(?:register|enqueue)_(?:script|style)\s*\(\s*([^,)]+)|\bwp_add_inline_(?:script|style)\s*\(\s*([^,)]+)|\bwp_set_script_translations\s*\(\s*([^,)]+)|\bwp_localize_script\s*\(\s*([^,)]+)\s*,\s*([\'"][^\'"]+[\'"])/', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m as $hit ) {
			for ( $g = 1; $g <= 4; $g++ ) {
				if ( isset( $hit[ $g ] ) && '' !== $hit[ $g ][0] ) {
					$val = slotiva_resolve( $hit[ $g ][0], $consts );
					if ( null !== $val ) {
						$add( $hit[ $g ][1], 'handle', $val );
					}
				}
			}
			if ( isset( $hit[5] ) && '' !== $hit[5][0] ) {
				$add( $hit[5][1], 'js-object', trim( $hit[5][0], '\'"' ) );
			}
		}
	}
	if ( 'php' === $ext && preg_match_all( '/[\'"]handle_prefix[\'"]\s*=>\s*([^,)]+)/', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m as $hit ) {
			$val = slotiva_resolve( $hit[1][0], $consts );
			if ( null !== $val ) {
				$add( $hit[1][1], 'handle', $val );
			}
		}
	}
	// class= / className= / className: (küçültülmüş JS) değerleri.
	if ( preg_match_all( '/\b(?:class|className)\s*[=:]\s*\\\\?(["\'`])((?:(?!\1)[^\n])*)/', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m as $hit ) {
			foreach ( slotiva_class_tokens( $hit[2][0] ) as $t ) {
				$add( $hit[2][1], 'class', $t );
			}
		}
	}
	// PHP dizi biçimi: 'class' => 'a b' | array( 'a', 'b' ); 'id' => 'x'. Tek metin değeri YA DA hemen `=>` ardındaki
	// array( ... ) / [ ... ] gövdesi alınır; kardeş anahtarlar (`'url' => ...`) hiçbir zaman değer sayılmaz.
	// Değer kuralı karar verir (veri anahtarı olan 'id' değeri de önek kuralına uyar).
	if ( 'php' === $ext && preg_match_all( '/[\'"](class|id)[\'"]\s*=>\s*(?:(?:array\s*\(|\[)([^)\]]*)[)\]]|([\'"])([^\'"\n]*)\3)/', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m as $hit ) {
			$strings = array();
			if ( isset( $hit[2] ) && '' !== $hit[2][0] && preg_match_all( '/([\'"])([^\'"\n]*)\1/', $hit[2][0], $s, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				foreach ( $s as $one ) {
					$strings[] = array( $one[2][0], $hit[2][1] + $one[2][1] );
				}
			} elseif ( isset( $hit[4] ) && '' !== $hit[4][0] ) {
				$strings[] = array( $hit[4][0], $hit[4][1] );
			}
			foreach ( $strings as $str ) {
				if ( 'class' === $hit[1][0] ) {
					foreach ( slotiva_class_tokens( $str[0] ) as $t ) {
						$add( $str[1], 'class', $t );
					}
				} elseif ( 1 === preg_match( '/^[A-Za-z_-][\w-]*$/', trim( $str[0] ) ) ) {
					$add( $str[1], 'id', trim( $str[0] ) );
				}
			}
		}
	}
	// DOM API: setAttribute('class'|'id', 'x'); el.className = 'x' (her alıcı: className yalnız DOM'da vardır).
	if ( preg_match_all( '/\bsetAttribute\(\s*[\'"](class|id)[\'"]\s*,\s*([\'"`])([^\'"`\n]*)\2|\.className\s*=\s*([\'"`])([^\'"`\n]*)\4/', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m as $hit ) {
			$is_id = isset( $hit[1] ) && 'id' === $hit[1][0];
			$val   = ( isset( $hit[3] ) && '' !== $hit[3][0] ) ? $hit[3] : ( $hit[5] ?? array( '', 0 ) );
			if ( $is_id ) {
				if ( 1 === preg_match( '/^[A-Za-z_-][\w-]*$/', trim( $val[0] ) ) ) {
					$add( $val[1], 'id', trim( $val[0] ) );
				}
				continue;
			}
			foreach ( slotiva_class_tokens( $val[0] ) as $t ) {
				$add( $val[1], 'class', $t );
			}
		}
	}
	// `.id = 'x'` yalnız DOM olduğu açık alıcılarda (el, node, element, ...El/...Node/...Element, document.*, this.$*) kimliktir;
	// `row.id = 'a1'` gibi veri modeli atamaları kimlik DEĞİLDİR. Shipped Slotiva kodu `.id =` ile DOM kimliği atamaz
	// (src-react taraması: yalnız `el.className`); bu dar kalıp ileriye dönük bir çit.
	if ( preg_match_all( '/(?:\b(?:el|node|element|\w*[A-Za-z0-9](?:El|Node|Element)|\w+_(?:el|node))|\bdocument\.[\w.]+|\bthis\.\$\w+)\.id\s*=\s*([\'"`])([^\'"`\n]*)\1/', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m as $hit ) {
			if ( 1 === preg_match( '/^[A-Za-z_-][\w-]*$/', trim( $hit[2][0] ) ) ) {
				$add( $hit[2][1], 'id', trim( $hit[2][0] ) );
			}
		}
	}
	// wp_add_inline_style( 'tutamac', '.sinif{...}' ): satır içi CSS metni seçici mantığıyla taranır.
	if ( 'php' === $ext && preg_match_all( '/\bwp_add_inline_style\s*\(\s*[^,]+,\s*([\'"])((?:(?!\1)[^\\\\\n]|\\\\.)*)\1/', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m as $hit ) {
			foreach ( slotiva_css_hits( $hit[2][0], false ) as $h ) {
				$add( $hit[2][1] + $h[0], $h[1], $h[2] );
			}
		}
	}
	// classList.x('a','b'), clsx/classnames/cx('a', ...).
	if ( preg_match_all( '/\b(?:classList\.(?:add|remove|toggle|contains|replace)|clsx|classnames|classNames|cx)\(([^)\n]*)\)/', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m as $hit ) {
			if ( preg_match_all( '/([\'"`])([^\'"`\n]*)\1/', $hit[1][0], $s ) ) {
				foreach ( $s[2] as $str ) {
					foreach ( slotiva_class_tokens( $str ) as $t ) {
						$add( $hit[1][1], 'class', $t );
					}
				}
			}
		}
	}
	// querySelector(All)/closest/matches/jQuery seçicileri.
	if ( preg_match_all( '/\b(?:querySelector(?:All)?|closest|matches|\$|jQuery)\(\s*([\'"`])([^\'"`\n]+)\1/', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m as $hit ) {
			foreach ( slotiva_selector_names( $hit[2][0] ) as $n ) {
				$add( $hit[2][1], $n[0], $n[1] );
			}
		}
	}
	// id="..." (HTML/JSX), getElementById('...').
	if ( preg_match_all( '/(?<![\w?&.-])id\s*=\s*\\\\?(["\'])([^"\'\\\\\n<{$]+)|\bgetElementById\(\s*([\'"])([^\'"]+)\3/', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m as $hit ) {
			if ( isset( $hit[2] ) && '' !== $hit[2][0] ) {
				$add( $hit[2][1], 'id', $hit[2][0] );
			} elseif ( isset( $hit[4] ) ) {
				$add( $hit[4][1], 'id', $hit[4][0] );
			}
		}
	}
}

if ( 0 === $scan || ! in_array( 'build/admin/appointments.css', $files, true ) ) {
	fwrite( STDERR, "check-short-prefix KURULAMADI -- taranacak dosya yok ya da build/ eksik (rm -rf build && npm run build).\n" );
	exit( 2 );
}

// Negatif/pozitif öz-sınama: kapı kendi kuralını bilmiyorsa sessizce geçmesin.
$probe_ok = slotiva_bad_prefix( 'zz-broken', $foreign ) && slotiva_bad_prefix( 'mhm-x', $foreign ) && slotiva_bad_prefix( 'mhm', $foreign )
	&& ! slotiva_bad_prefix( 'slotiva-ok', $foreign ) && ! slotiva_bad_prefix( 'wp-components', $foreign ) && ! slotiva_bad_prefix( 'mhmui-x', $foreign )
	&& ! slotiva_bad_prefix( 'jquery', $foreign );
if ( ! $probe_ok ) {
	fwrite( STDERR, "check-short-prefix KURULAMADI -- kuralin oz-sinamasi gecersiz.\n" );
	exit( 2 );
}

$found = array_map( 'unserialize', array_unique( array_map( 'serialize', $found ) ) );

if ( in_array( '--names', $argv, true ) ) {
	$by = array();
	foreach ( $found as $f ) {
		if ( 0 === strpos( $f[3], 'mhm-' ) || 'mhm' === $f[3] ) {
			$by[ $f[0] ][ $f[2] . ' ' . $f[3] ] = true;
		}
	}
	ksort( $by );
	foreach ( $by as $file => $names ) {
		ksort( $names );
		echo $file . ' (' . count( $names ) . ")\n  " . implode( "\n  ", array_keys( $names ) ) . "\n";
	}
	exit( 0 );
}

// Bekleyen listede olup artık dosyada bulunmayan ad = bayat giriş: ui-core yeniden adlandırınca liste küçülmeli.
$seen = array();
foreach ( $found as $f ) {
	$seen[ $f[0] . "\0" . $f[3] ] = true;
}
$stale = array();
foreach ( $pending_upstream as $pfile => $pnames ) {
	foreach ( $pnames as $pn ) {
		if ( ! isset( $seen[ $pfile . "\0" . $pn ] ) ) {
			$stale[] = "{$pfile}: {$pn}";
		}
	}
}
if ( array() !== $stale ) {
	fwrite( STDERR, "check-short-prefix FAILED -- bekleyen listede BAYAT giris (dosyada artik yok; listeden silin):\n  " . implode( "\n  ", $stale ) . "\n" );
	exit( 1 );
}

$hits    = array();
$pending = 0;
foreach ( $found as $f ) {
	list( $rel, $line, $kind, $name ) = $f;
	if ( ! slotiva_bad_prefix( $name, $foreign ) ) {
		continue;
	}
	if ( in_array( $name, $pending_upstream[ $rel ] ?? array(), true ) ) {
		++$pending;
		continue;
	}
	$hits[] = sprintf( '%s:%d [%s] %s', $rel, $line, $kind, $name );
}

if ( array() !== $hits ) {
	sort( $hits );
	fwrite( STDERR, "check-short-prefix FAILED -- kisa/mhm onekli ad (slotiva- kullanin):\n  " . implode( "\n  ", $hits ) . "\n" );
	exit( 1 );
}
echo sprintf( "check-short-prefix: temiz (%d dosya tarandi, %d ui-core adi gerekceli muafiyette)\n", $scan, $pending );
exit( 0 );
