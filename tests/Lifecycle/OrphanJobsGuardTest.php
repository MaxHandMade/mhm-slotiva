<?php
/**
 * Koruma testi: Slotiva'nın zamanladığı Action Scheduler kancaları ile `OrphanJobs::ownHooks()` aynı küme olmalı.
 * Yeni bir kanca eklenip listeye yazılmazsa, şema sürümü değişince `OrphanJobs::sweep()` onu "yetim" sanıp siler (final review M8).
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Lifecycle;

use MHM\Slotiva\Lifecycle\OrphanJobs;

class OrphanJobsGuardTest extends \MHM\Slotiva\Tests\Support\SlotivaTestCase {

	/**
	 * Kanca adı bağımsız değişkeninin sırası (0 tabanlı): single(ts, hook), recurring(ts, interval, hook), async(hook).
	 *
	 * @var array<string,int>
	 */
	private const HOOK_ARG = array(
		'as_schedule_single_action'    => 1,
		'as_schedule_recurring_action' => 2,
		'as_schedule_cron_action'      => 2,
		'as_enqueue_async_action'      => 0,
	);

	/**
	 * Kaynaktaki zamanlama çağrılarından kanca adlarını türetir (kaynak taraması; sabitler yansımayla çözülür).
	 *
	 * @return string[]
	 */
	private function scheduled_hooks(): array {
		$hooks = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( SLOTIVA_DIR . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$code = (string) file_get_contents( $file->getPathname() );
			$ns   = preg_match( '/^namespace\s+([^;]+);/m', $code, $m ) ? $m[1] : '';
			$cls  = preg_match( '/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $code, $m ) ? $m[1] : '';
			// Çağrı: ad, ardından `(`; tırnak içindeki adlar (function_exists( 'as_...' )) eşleşmez.
			if ( ! preg_match_all( '/(?<![\'\w])(' . implode( '|', array_keys( self::HOOK_ARG ) ) . ')\s*\(/', $code, $calls, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $calls[1] as $i => $call ) {
				$args  = $this->split_args( $code, $calls[0][ $i ][1] + strlen( $calls[0][ $i ][0] ) );
				$index = self::HOOK_ARG[ $call[0] ];
				$this->assertArrayHasKey( $index, $args, $file->getFilename() . ': ' . $call[0] . ' kanca bağımsız değişkeni bulunamadı' );
				$hooks[] = $this->resolve( trim( $args[ $index ] ), $ns, $cls, $file->getFilename() );
			}
		}
		$hooks = array_values( array_unique( $hooks ) );
		sort( $hooks );
		return $hooks;
	}

	/**
	 * Açılış parantezinden sonraki üst düzey bağımsız değişkenleri böler.
	 *
	 * @param string $code   Kaynak.
	 * @param int    $offset Parantezden hemen sonrası.
	 * @return string[]
	 */
	private function split_args( string $code, int $offset ): array {
		$args  = array();
		$depth = 0;
		$buf   = '';
		$quote = '';
		for ( $n = strlen( $code ); $offset < $n; $offset++ ) {
			$c = $code[ $offset ];
			if ( '' !== $quote ) {
				$buf .= $c;
				if ( '\\' === $c ) {
					$buf .= $code[ ++$offset ];
				} elseif ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( "'" === $c || '"' === $c ) {
				$quote = $c;
			} elseif ( '(' === $c || '[' === $c ) {
				++$depth;
			} elseif ( ')' === $c || ']' === $c ) {
				if ( 0 === $depth ) {
					$args[] = $buf;
					return $args;
				}
				--$depth;
			} elseif ( ',' === $c && 0 === $depth ) {
				$args[] = $buf;
				$buf    = '';
				continue;
			}
			$buf .= $c;
		}
		return $args;
	}

	/**
	 * `self::X`, `Sınıf::X` ya da düz dize ifadesini kanca adına çevirir.
	 *
	 * @param string $expr Bağımsız değişken metni.
	 * @param string $ns   Dosyanın ad alanı.
	 * @param string $cls  Dosyanın sınıfı.
	 * @param string $file Hata iletisi için dosya adı.
	 */
	private function resolve( string $expr, string $ns, string $cls, string $file ): string {
		if ( preg_match( '/^([\'"])([\w-]+)\1$/', $expr, $m ) ) {
			return $m[2];
		}
		if ( preg_match( '/^(self|static|\\\\?[\w\\\\]+)::([A-Z_]+)$/', $expr, $m ) ) {
			$class = in_array( $m[1], array( 'self', 'static' ), true ) ? $ns . '\\' . $cls : ( str_contains( $m[1], '\\' ) ? ltrim( $m[1], '\\' ) : $this->class_by_short_name( $m[1] ) );
			$this->assertTrue( class_exists( $class ), $file . ': ' . $class . ' sınıfı çözülemedi' );
			return (string) constant( $class . '::' . $m[2] );
		}
		$this->fail( $file . ': kanca ifadesi çözülemedi (sabit ya da dize olmalı): ' . $expr );
	}

	/**
	 * Kısa adı `src/` altındaki sınıfa eşler (use ifadelerine bakmadan; ad çakışması yok).
	 *
	 * @param string $short Kısa ad.
	 */
	private function class_by_short_name( string $short ): string {
		foreach ( get_declared_classes() as $c ) {
			if ( str_starts_with( $c, 'MHM\\Slotiva\\' ) && substr( $c, (int) strrpos( $c, '\\' ) + 1 ) === $short ) {
				return $c;
			}
		}
		return 'MHM\\Slotiva\\' . $short;
	}

	public function test_own_hooks_equals_hooks_scheduled_in_source(): void {
		$scheduled = $this->scheduled_hooks();
		$own       = OrphanJobs::ownHooks();
		sort( $own );
		$this->assertNotEmpty( $scheduled, 'tarama en az bir zamanlama bulmalı' );
		$this->assertSame(
			$scheduled,
			$own,
			'Yeni bir Action Scheduler kancası OrphanJobs::ownHooks() listesine eklenmeli; yoksa şema sürümü değişince sessizce silinir.'
		);
	}
}
