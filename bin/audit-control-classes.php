<?php
/**
 * Rubric-driven control-class sweep over the shipped PHP (src/, templates/, mhm-slotiva.php, uninstall.php).
 *
 * Adapted from mhm-rentiva bin/audit-control-classes.php (Lite 1.1.0 Task 17). WordPress.org's review reports a
 * SAMPLE; this script enumerates the classes PHPCS cannot see because the defect is an ABSENCE, not a flagged line:
 *
 *   A. hooked handler that reads a request superglobal ($_POST/$_GET/$_REQUEST/$_FILES) and changes state, with no
 *      nonce verification in its body or in the same-class helpers it calls
 *   B. the same handler with no capability check (nopriv / public hooks excluded)
 *   C. REST route that changes state (CREATABLE/EDITABLE/DELETABLE) whose permission_callback is __return_true
 *   D. register_setting() without a sanitize_callback
 *   E. shortcode callback that echoes instead of returning
 *   F. in_array() without strict comparison
 *   G. URL escaped with esc_attr() where esc_url() is required
 *   H. inline <style>/<script> printed outside the e-mail templates
 *   I. gettext call whose text or domain is not a string literal (domain must be 'mhm-slotiva')
 *
 * Slotiva differences from Rentiva: handlers are wired as array( self::class, 'method' ) to WooCommerce/core hooks
 * (woocommerce_process_product_meta, woocommerce_before_pay_action, template_redirect, admin_init) rather than
 * wp_ajax_* / admin_post_*; so A/B consider EVERY hook registration and follow self::helper() calls one level at a
 * time (transitively, same class only).
 *
 * ---------------------------------------------------------------------------------------------------------------------
 * A REPORT, NOT A GATE. Section 0 lists what it cannot see; read that first. Exit code is always 0.
 * ---------------------------------------------------------------------------------------------------------------------
 *
 * Usage: php bin/audit-control-classes.php
 *
 * @package MHM\Slotiva
 */

declare( strict_types=1 );

$root  = dirname( __DIR__ );
$files = array();
foreach ( array( 'src', 'templates' ) as $dir ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "{$root}/{$dir}", FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( 'php' === $f->getExtension() ) {
			$files[] = str_replace( '\\', '/', $f->getPathname() );
		}
	}
}
foreach ( array( 'mhm-slotiva.php', 'uninstall.php' ) as $f ) {
	$files[] = str_replace( '\\', '/', "{$root}/{$f}" );
}
sort( $files );
$root_unix = str_replace( '\\', '/', $root );

$find = array_fill_keys( array( 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I' ), array() );
$seen = array(
	'handlers_reading_request' => 0,
	'mutating_rest_routes'     => 0,
	'gettext_calls'            => 0,
);

$nonce_re = '/\b(wp_verify_nonce|check_admin_referer|check_ajax_referer)\s*\(/';
$cap_re   = '/\b(current_user_can|user_can|is_user_logged_in)\s*\(/';
$read_re  = '/\$_(POST|GET|REQUEST|FILES)\b/';
$mut_re   = '/\b(update_option|add_option|delete_option|update_post_meta|add_post_meta|delete_post_meta|update_meta_data|delete_meta_data|->save\s*\(|->set_[a-z_]+\s*\(|update_user_meta|delete_user_meta|wp_insert_post|wp_update_post|wp_delete_post|wp_mail|\$wpdb->(insert|update|delete|replace|query)|ReservationRepository::|Rules::save|HoldScheduler::|wp_safe_redirect)/';

foreach ( $files as $path ) {
	$src   = (string) file_get_contents( $path );
	$short = substr( $path, strlen( $root_unix ) + 1 );
	$funcs = array();
	foreach ( split_functions( $src ) as [$name, $body, $line] ) {
		$funcs[ $name ] = array( $body, $line );
	}

	// ---- A/B: every hook registration that names a method of this class. ------------------------------------------
	preg_match_all(
		"/add_(?:action|filter)\(\s*(['\"])([^'\"]+)\\1\s*,\s*array\(\s*(?:self|static|__CLASS__|[A-Za-z_\\\\]+)::class\s*,\s*'([A-Za-z_][A-Za-z0-9_]*)'/",
		$src,
		$m,
		PREG_SET_ORDER
	);
	foreach ( $m as $hit ) {
		$hook   = $hit[2];
		$method = $hit[3];
		if ( ! isset( $funcs[ $method ] ) ) {
			continue;
		}
		$body = expand_body( $method, $funcs );
		if ( ! preg_match( $read_re, $body ) ) {
			continue;
		}
		++$seen['handlers_reading_request'];
		$public  = str_contains( $hook, '_nopriv_' ) || in_array( $hook, array( 'template_redirect', 'init', 'wp' ), true );
		$mutates = (bool) preg_match( $mut_re, $body );
		$where   = "{$short}:{$funcs[ $method ][1]}  {$method}()  [{$hook}]";
		if ( $mutates && ! preg_match( $nonce_re, $body ) ) {
			$find['A'][] = $where . ( $public ? '  (public hook: token/key auth expected instead)' : '' );
		}
		if ( $mutates && ! $public && ! preg_match( $cap_re, $body ) ) {
			$find['B'][] = $where;
		}
	}

	// ---- C: mutating REST routes with an open permission_callback. -------------------------------------------------
	if ( preg_match_all( "/'methods'\s*=>\s*([^,]+),/", $src, $mm, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $mm[1] as $hit ) {
			$method = $hit[0];
			if ( ! preg_match( '/CREATABLE|EDITABLE|DELETABLE|POST|PUT|PATCH|DELETE/', $method ) ) {
				continue;
			}
			++$seen['mutating_rest_routes'];
			$window = substr( $src, $hit[1], 400 );
			if ( preg_match( "/'permission_callback'\s*=>\s*'__return_true'/", $window ) ) {
				$find['C'][] = $short . ':' . ( substr_count( $src, "\n", 0, $hit[1] ) + 1 ) . '  ' . trim( $method );
			}
		}
	}

	// ---- D. register_setting without sanitize_callback. -------------------------------------------------------------
	if ( preg_match_all( '/\bregister_setting\s*\(/', $src, $mm, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $mm[0] as $hit ) {
			if ( ! str_contains( substr( $src, $hit[1], 900 ), 'sanitize_callback' ) ) {
				$find['D'][] = $short . ':' . ( substr_count( $src, "\n", 0, $hit[1] ) + 1 );
			}
		}
	}

	// ---- E. shortcode callback echoing. -----------------------------------------------------------------------------
	if ( preg_match_all( "/add_shortcode\(\s*[^,]+,\s*array\(\s*[^,]+,\s*'([A-Za-z_][A-Za-z0-9_]*)'/", $src, $mm ) ) {
		foreach ( $mm[1] as $cb ) {
			if ( isset( $funcs[ $cb ] ) && preg_match( '/\becho\b/', $funcs[ $cb ][0] ) && ! str_contains( $funcs[ $cb ][0], 'ob_start' ) ) {
				$find['E'][] = "{$short}:{$funcs[ $cb ][1]}  {$cb}()";
			}
		}
	}

	$is_email = str_contains( $short, 'templates/emails/' );
	foreach ( preg_split( "/\n/", $src ) as $i => $line ) {
		$ln = $i + 1;
		// ---- F. non-strict in_array. --------------------------------------------------------------------------------
		if ( preg_match( '/\bin_array\s*\(/', $line ) && ! str_contains( $line, 'true' ) ) {
			$find['F'][] = "{$short}:{$ln}  " . trim( substr( $line, 0, 100 ) );
		}
		// ---- G. URL through esc_attr. -------------------------------------------------------------------------------
		if ( preg_match( '/esc_attr\(\s*\$?[a-zA-Z_>\-\[\]\'"]*(url|link|href|permalink)/i', $line ) ) {
			$find['G'][] = "{$short}:{$ln}  " . trim( substr( $line, 0, 100 ) );
		}
		// ---- H. inline <style>/<script> outside e-mail templates. ---------------------------------------------------
		if ( ! $is_email && preg_match( '/<(style|script)\b/i', $line ) && ! preg_match( '#^\s*(\*|//)#', $line ) ) {
			$find['H'][] = "{$short}:{$ln}  " . trim( substr( $line, 0, 100 ) );
		}
	}

	// ---- I. gettext: literal text + literal 'mhm-slotiva' domain. ---------------------------------------------------
	$tokens = token_get_all( $src );
	$gettext = array(
		'__'                   => array( 0, 1 ),
		'_e'                   => array( 0, 1 ),
		'esc_html__'           => array( 0, 1 ),
		'esc_html_e'           => array( 0, 1 ),
		'esc_attr__'           => array( 0, 1 ),
		'esc_attr_e'           => array( 0, 1 ),
		'_x'                   => array( 0, 2 ),
		'_ex'                  => array( 0, 2 ),
		'esc_html_x'           => array( 0, 2 ),
		'esc_attr_x'           => array( 0, 2 ),
		'_n'                   => array( 0, 3 ),
		'_nx'                  => array( 0, 4 ),
		'_n_noop'              => array( 0, 2 ),
		'_nx_noop'             => array( 0, 3 ),
	);
	$count = count( $tokens );
	for ( $t = 0; $t < $count; $t++ ) {
		if ( ! is_array( $tokens[ $t ] ) || T_STRING !== $tokens[ $t ][0] || ! isset( $gettext[ $tokens[ $t ][1] ] ) ) {
			continue;
		}
		$prev = $t - 1;
		while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
			--$prev;
		}
		if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}
		$args = call_args( $tokens, $t );
		if ( null === $args ) {
			continue;
		}
		++$seen['gettext_calls'];
		[$text_i, $domain_i] = $gettext[ $tokens[ $t ][1] ];
		$text_ok   = isset( $args[ $text_i ] ) && preg_match( "/^'(?:[^'\\\\]|\\\\.)*'$|^\"(?:[^\"\\\\\$]|\\\\.)*\"$/s", $args[ $text_i ] );
		$domain_ok = isset( $args[ $domain_i ] ) && "'mhm-slotiva'" === $args[ $domain_i ];
		if ( ! $text_ok || ! $domain_ok ) {
			$find['I'][] = "{$short}:{$tokens[ $t ][2]}  {$tokens[ $t ][1]}(" . implode( ', ', array_map( static fn( $a ) => substr( $a, 0, 30 ), $args ) ) . ')';
		}
	}
}

$titles = array(
	'A' => 'Hooked handler reads the request, changes state, NO nonce verification',
	'B' => 'Hooked handler reads the request, changes state, NO capability check (public hooks excluded)',
	'C' => 'Mutating REST route with permission_callback __return_true',
	'D' => 'register_setting() without sanitize_callback',
	'E' => 'Shortcode callback that echoes instead of returning',
	'F' => 'in_array() without strict comparison',
	'G' => 'URL escaped with esc_attr() instead of esc_url()',
	'H' => 'Inline <style>/<script> outside templates/emails/',
	'I' => "gettext call with non-literal text or a domain other than literal 'mhm-slotiva'",
);

echo "0. What this cannot see -- check these by hand\n";
echo str_repeat( '-', 78 ) . "\n";
echo "   closures and string callables on hooks (only array( X::class, 'm' ) is resolved); helpers in OTHER\n";
echo "   classes (only self::/static:: calls are followed); nonce verified by the caller of the hook (e.g. WC\n";
echo "   before woocommerce_process_product_meta) -- this script still asks for an own check; REST routes'\n";
echo "   object-level checks (see audit-object-capabilities.php); escape/sanitize/prepared SQL -- run\n";
echo "   phpcs --ignore-annotations and read THAT number. Section F only sees single-line calls (WPCS\n";
echo "   WordPress.PHP.StrictInArray covers multi-line ones).\n";
printf(
	"   measured: %d files, %d request-reading hooked handlers, %d mutating REST routes, %d gettext calls\n\n",
	count( $files ),
	$seen['handlers_reading_request'],
	$seen['mutating_rest_routes'],
	$seen['gettext_calls']
);

foreach ( $titles as $k => $title ) {
	$rows = array_values( array_unique( $find[ $k ] ) );
	printf( "%s. %s: %d\n", $k, $title, count( $rows ) );
	echo str_repeat( '-', 78 ) . "\n";
	foreach ( $rows as $r ) {
		echo "   {$r}\n";
	}
	if ( ! $rows ) {
		echo "   (none)\n";
	}
	echo "\n";
}

/**
 * Body of $name plus, transitively, the bodies of self::/static:: helpers it calls.
 *
 * @param string                               $name  Method.
 * @param array<string, array{0:string,1:int}> $funcs Methods of the file.
 * @param array<string, true>                  $seen  Visited.
 */
function expand_body( string $name, array $funcs, array &$seen = array() ): string {
	if ( isset( $seen[ $name ] ) || ! isset( $funcs[ $name ] ) ) {
		return '';
	}
	$seen[ $name ] = true;
	$body          = $funcs[ $name ][0];
	if ( preg_match_all( '/\b(?:self|static)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $body, $m ) ) {
		foreach ( $m[1] as $callee ) {
			$body .= "\n" . expand_body( $callee, $funcs, $seen );
		}
	}
	return $body;
}

/**
 * Top-level argument source strings of the call whose name token is at $t, or null.
 *
 * @param array<int, mixed> $tokens Tokens.
 * @param int               $t      Name token index.
 * @return list<string>|null
 */
function call_args( array $tokens, int $t ): ?array {
	$count = count( $tokens );
	$j     = $t + 1;
	while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
		++$j;
	}
	if ( $j >= $count || '(' !== $tokens[ $j ] ) {
		return null;
	}
	$depth = 0;
	$args  = array();
	$cur   = '';
	for ( $k = $j; $k < $count; $k++ ) {
		$tok = $tokens[ $k ];
		$txt = is_array( $tok ) ? $tok[1] : $tok;
		if ( is_array( $tok ) && in_array( $tok[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		if ( '(' === $txt || '[' === $txt || '{' === $txt ) {
			++$depth;
			if ( 1 === $depth ) {
				continue;
			}
		} elseif ( ')' === $txt || ']' === $txt || '}' === $txt ) {
			--$depth;
			if ( 0 === $depth ) {
				$args[] = trim( $cur );
				break;
			}
		} elseif ( ',' === $txt && 1 === $depth ) {
			$args[] = trim( $cur );
			$cur    = '';
			continue;
		}
		$cur .= $txt;
	}
	return $args;
}

/**
 * Functions/methods of a source file: name, body, line.
 *
 * @return array<int, array{0:string,1:string,2:int}>
 */
function split_functions( string $src ): array {
	$out = array();
	if ( ! preg_match_all( '/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE ) ) {
		return $out;
	}
	foreach ( $m[1] as $i => $hit ) {
		$start = strpos( $src, '{', $m[0][ $i ][1] );
		$semi  = strpos( $src, ';', $m[0][ $i ][1] );
		if ( false === $start || ( false !== $semi && $semi < $start ) ) {
			continue;
		}
		$depth = 0;
		$end   = $start;
		$len   = strlen( $src );
		for ( $j = $start; $j < $len; $j++ ) {
			if ( '{' === $src[ $j ] ) {
				++$depth;
			} elseif ( '}' === $src[ $j ] ) {
				--$depth;
				if ( 0 === $depth ) {
					$end = $j;
					break;
				}
			}
		}
		$out[] = array( $hit[0], substr( $src, $start, $end - $start + 1 ), substr_count( $src, "\n", 0, $hit[1] ) + 1 );
	}
	return $out;
}
