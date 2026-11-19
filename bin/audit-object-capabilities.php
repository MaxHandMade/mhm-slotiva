<?php
/**
 * Audit helper: routes/handlers that take an object id from the request behind a capability that is not about
 * that object (IDOR class). Adapted from mhm-rentiva bin/audit-object-capabilities.php (Lite 1.1.0 Task 17).
 *
 * Slotiva's request surface is almost entirely REST: every register_rest_route() in src/ is parsed, and for each
 * endpoint the script resolves
 *   - the callback            ('callback' => array( self::class, 'm' ))
 *   - the permission_callback (literal '__return_true', array( self::class, 'm' ), a static fn/closure, or a $variable
 *                              assigned earlier in the same function -- the shape Admin\RestController uses)
 *   - the capability it checks (current_user_can( X ) found in the permission body, one self:: hop deep, constants
 *                              resolved from the file / Menu::CAPABILITY)
 *   - whether it takes an id  ((?P<id>...) in the route, or an arg named id / *_id / exclude_reservation)
 *   - whether the callback does an object-aware check (current_user_can( 'cap', $id ) with two arguments).
 * Hooked handlers (add_action/add_filter → array( self::class, 'm' )) that read *id* keys out of $_POST/$_GET are
 * listed too.
 *
 * ---------------------------------------------------------------------------------------------------------------------
 * THIS IS A REPORT, NOT A GATE. Section 3 lists what it could not resolve. Exit code is always 0.
 * ---------------------------------------------------------------------------------------------------------------------
 *
 * Blanket capabilities: say "may act on this KIND of object", never "on THIS object". manage_woocommerce and
 * manage_options are on the list for Slotiva: they are the store-wide gates the admin screens use.
 *
 * What it CANNOT see: routes registered with a computed path or callback; permission callables in another class
 * (printed as unresolved); object checks done by a helper in another class; ownership checks that are not a
 * current_user_can() call (e.g. order-key hash_equals) -- those show as "no object check" and must be read by hand.
 *
 * Usage: php bin/audit-object-capabilities.php
 *
 * @package MHM\Slotiva
 */

declare( strict_types=1 );

const SLOTIVA_BLANKET = '/^(manage_woocommerce|manage_options|edit_posts|edit_products|publish_posts|read|upload_files|edit_others_posts|delete_posts|delete_users|edit_users|list_users|promote_users|remove_users|view_woocommerce_reports|edit_shop_orders)$/';

$root  = dirname( __DIR__ );
$files = array();
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( 'php' === $f->getExtension() ) {
		$files[ str_replace( '\\', '/', substr( $f->getPathname(), strlen( $root ) + 1 ) ) ] = (string) file_get_contents( $f->getPathname() );
	}
}
ksort( $files );

// Constants: CLASS::NAME => 'value' (only string literals), for cap resolution across files.
$constants = array();
foreach ( $files as $path => $src ) {
	if ( ! preg_match( '/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $src, $cm ) ) {
		continue;
	}
	preg_match_all( "/const\s+([A-Z_][A-Z0-9_]*)\s*=\s*'([^']*)'/", $src, $km, PREG_SET_ORDER );
	foreach ( $km as $k ) {
		$constants[ $cm[1] . '::' . $k[1] ] = $k[2];
		$constants[ $path . '#self::' . $k[1] ] = $k[2];
	}
}

$endpoints  = array();
$suspect    = array();
$unresolved = array();
$handlers   = array();

foreach ( $files as $path => $src ) {
	$funcs = array();
	foreach ( split_functions( $src ) as [$name, $body, $line] ) {
		$funcs[ $name ] = array( $body, $line );
	}
	$cap_of = static function ( string $code ) use ( $path, $constants, $funcs ): array {
		$caps = array();
		$seen = array();
		$todo = array( $code );
		while ( $todo ) {
			$c = array_shift( $todo );
			preg_match_all( '/current_user_can\(\s*([^,)]+)\s*(,)?/', $c, $m, PREG_SET_ORDER );
			foreach ( $m as $hit ) {
				$expr = trim( $hit[1] );
				if ( preg_match( "/^'([a-z_]+)'$/", $expr, $lm ) ) {
					$cap = $lm[1];
				} elseif ( preg_match( '/^self::([A-Z_][A-Z0-9_]*)$/', $expr, $sm ) ) {
					$cap = $constants[ $path . '#self::' . $sm[1] ] ?? $expr;
				} elseif ( preg_match( '/^([A-Za-z_]+)::([A-Z_][A-Z0-9_]*)$/', $expr, $sm ) ) {
					$cap = $constants[ $sm[1] . '::' . $sm[2] ] ?? $expr;
				} else {
					$cap = $expr;
				}
				$caps[] = $cap . ( isset( $hit[2] ) && ',' === $hit[2] ? '(obj)' : '' );
			}
			if ( preg_match_all( '/\bself::([a-z_][A-Za-z0-9_]*)\s*\(/', $c, $sm ) ) {
				foreach ( $sm[1] as $callee ) {
					if ( isset( $funcs[ $callee ] ) && ! isset( $seen[ $callee ] ) ) {
						$seen[ $callee ] = true;
						$todo[]          = $funcs[ $callee ][0];
					}
				}
			}
		}
		return array_values( array_unique( $caps ) );
	};

	// ---- REST routes. --------------------------------------------------------------------------------------------
	if ( preg_match_all( '/register_rest_route\s*\(/', $src, $rm, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $rm[0] as $hit ) {
			$call  = balanced( $src, $hit[1] + strlen( $hit[0] ) - 1 );
			$line  = substr_count( $src, "\n", 0, $hit[1] ) + 1;
			$route = preg_match( "/^\s*[^,]+,\s*([^,]+),/s", $call, $pm ) ? trim( $pm[1] ) : '?';
			// The enclosing function, for $variable permission callbacks.
			$encl = '';
			foreach ( $funcs as $fn => [$body, $fline] ) {
				if ( str_contains( $body, $call ) ) {
					$encl = $body;
				}
			}
			preg_match_all( "/'callback'\s*=>\s*array\(\s*self::class\s*,\s*'([A-Za-z_][A-Za-z0-9_]*)'\s*\)/", $call, $cbm, PREG_OFFSET_CAPTURE );
			foreach ( $cbm[1] as $cb ) {
				$window = substr( $call, $cb[1], 700 );
				// The nearest 'methods' BEFORE this callback (multi-method routes list several).
				$method = preg_match_all( "/'methods'\s*=>\s*([^,]+),/", substr( $call, 0, $cb[1] ), $mm ) ? trim( (string) end( $mm[1] ) ) : '?';
				// Ids read in the callback body count too (get_param / $request['x_id']).
				$perm   = preg_match( "/'permission_callback'\s*=>\s*(static\s+fn\s*\([^)]*\)\s*:\s*bool\s*=>\s*[^\n]+|array\(\s*self::class\s*,\s*'[A-Za-z_]+'\s*\)|'[a-z_]+'|\\\$[a-z_]+)/", $window, $pm2 ) ? trim( $pm2[1] ) : '';
				$caps   = array();
				$res    = '';
				if ( "'__return_true'" === $perm ) {
					$res = 'public (__return_true)';
				} elseif ( preg_match( "/^array\(\s*self::class\s*,\s*'([A-Za-z_]+)'/", $perm, $am ) && isset( $funcs[ $am[1] ] ) ) {
					$caps = $cap_of( $funcs[ $am[1] ][0] );
					$res  = 'self::' . $am[1];
				} elseif ( preg_match( '/^\$([a-z_]+)$/', $perm, $vm ) && preg_match( '/\$' . $vm[1] . "\s*=\s*array\(\s*self::class\s*,\s*'([A-Za-z_]+)'\s*\)/", $encl, $vam ) && isset( $funcs[ $vam[1] ] ) ) {
					$caps = $cap_of( $funcs[ $vam[1] ][0] );
					$res  = '$' . $vm[1] . ' = self::' . $vam[1];
				} elseif ( str_starts_with( $perm, 'static fn' ) ) {
					$caps = $cap_of( $perm );
					$res  = 'closure';
				} else {
					$unresolved[] = "{$path}:{$line}  {$route} -> " . ( '' === $perm ? '(no permission_callback found)' : $perm );
					continue;
				}
				$takes_id = (bool) preg_match( '/\(\?P<[a-z_]*id>/', $route )
					|| (bool) preg_match( "/'(?:[a-z_]*_id|id|exclude_reservation|product|variation)'\s*=>/", $window );
				$cb_body  = isset( $funcs[ $cb[0] ] ) ? $funcs[ $cb[0] ][0] : '';
				$takes_id = $takes_id || (bool) preg_match( "/get_param\(\s*'(?:[a-z_]*_id|id)'|\\\$request\[\s*'(?:[a-z_]*_id|id)'|\['id'\]|self::body\(/", $cb_body );
				$obj      = (bool) preg_match( "/current_user_can\(\s*[^,)]+,/", $cb_body );
				$blanket  = array() !== $caps && array() === array_filter( $caps, static fn( $c ) => ! preg_match( SLOTIVA_BLANKET, $c ) );
				$row      = sprintf( '%s:%d  %-6s %-48s cb=%s perm=%s caps=[%s]%s%s', $path, $line, $method, trim( $route, "'" ), $cb[0], $res, implode( ',', $caps ), $takes_id ? ' id' : '', $obj ? ' obj-check' : '' );
				$endpoints[] = $row;
				if ( $takes_id && ( $blanket || 'public (__return_true)' === $res ) && ! $obj ) {
					$suspect[] = $row;
				}
			}
		}
	}

	// ---- Hooked handlers reading an id from the request. ---------------------------------------------------------
	preg_match_all( "/add_(?:action|filter)\(\s*'([^']+)'\s*,\s*array\(\s*self::class\s*,\s*'([A-Za-z_][A-Za-z0-9_]*)'/", $src, $hm, PREG_SET_ORDER );
	foreach ( $hm as $h ) {
		if ( ! isset( $funcs[ $h[2] ] ) ) {
			continue;
		}
		$body = $funcs[ $h[2] ][0];
		if ( preg_match( "/\\\$_(POST|GET|REQUEST)\s*\[\s*'[a-z_]*(id|ids|key)'/", $body ) || preg_match( '/\$post_id\b/', $body ) ) {
			$caps       = $cap_of( $body );
			$handlers[] = sprintf( '%s:%d  %s() [%s] caps=[%s]', $path, $funcs[ $h[2] ][1], $h[2], $h[1], implode( ',', $caps ) );
		}
	}
}

echo "1. Endpoints taking an object id behind a blanket/public gate with NO object-aware check in the callback\n";
echo str_repeat( '-', 100 ) . "\n";
foreach ( $suspect ? $suspect : array( '(none)' ) as $s ) {
	echo "   {$s}\n";
}
echo "\n2. Full REST endpoint inventory (" . count( $endpoints ) . ")\n";
echo str_repeat( '-', 100 ) . "\n";
foreach ( $endpoints as $e ) {
	echo "   {$e}\n";
}
echo "\n2b. Hooked handlers reading an id/key from the request (" . count( $handlers ) . ")\n";
echo str_repeat( '-', 100 ) . "\n";
foreach ( $handlers ? $handlers : array( '(none)' ) as $h ) {
	echo "   {$h}\n";
}
echo "\n3. Could not resolve -- review these by hand\n";
echo str_repeat( '-', 100 ) . "\n";
foreach ( $unresolved ? $unresolved : array( '(none)' ) as $u ) {
	echo "   {$u}\n";
}
echo "\nSection 1 being empty does not mean the codebase is clean; read section 3 and the header's blind spots.\n";

/**
 * Text between the parenthesis at $open and its match (exclusive).
 */
function balanced( string $src, int $open ): string {
	$depth = 0;
	$len   = strlen( $src );
	for ( $j = $open; $j < $len; $j++ ) {
		if ( '(' === $src[ $j ] ) {
			++$depth;
		} elseif ( ')' === $src[ $j ] ) {
			--$depth;
			if ( 0 === $depth ) {
				return substr( $src, $open + 1, $j - $open - 1 );
			}
		}
	}
	return substr( $src, $open + 1 );
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
