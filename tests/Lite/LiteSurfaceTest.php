<?php
/**
 * Lite yüzeyi: lisans istemcisi ağaçta yok (sınıf, rota, menü, kurulum maddesi, başlık, zamanlanmış iş).
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Lite;

use MHM\Slotiva\Admin\Menu;
use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Lifecycle\HoldScheduler;
use MHM\Slotiva\Lifecycle\OrphanJobs;
use MHM\Slotiva\Setup\SetupStatus;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class LiteSurfaceTest extends SlotivaTestCase {

	public function test_no_license_classes_or_routes(): void {
		$this->assertFalse( class_exists( 'MHM\Slotiva\License\LicenseManager' ) );
		foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
			if ( false === strpos( $route, 'slotiva' ) ) {
				continue;
			}
			$this->assertStringNotContainsString( 'license', $route );
			$this->assertStringNotContainsString( 'verify', $route );
		}
	}

	public function test_no_license_admin_page_or_setup_item(): void {
		$this->assertSame( array( 'hours', 'checkout', 'product', 'timezone', 'payment' ), array_keys( SetupStatus::checks() ) );
		foreach ( array_keys( Menu::pages() ) as $slug ) {
			$this->assertStringNotContainsString( 'license', $slug );
		}
	}

	public function test_header_has_no_update_uri(): void {
		$this->assertSame( '', get_file_data( SLOTIVA_FILE, array( 'u' => 'Update URI' ) )['u'] );
	}

	public function test_no_license_crons(): void {
		as_schedule_recurring_action( time() + 3600, 3600, HoldScheduler::SWEEP_HOOK, array(), 'slotiva' );
		$actions = as_get_scheduled_actions(
			array(
				'group'    => 'slotiva',
				'per_page' => -1,
			)
		);
		$this->assertNotEmpty( $actions );
		foreach ( $actions as $action ) {
			$this->assertStringNotContainsString( 'license', $action->get_hook() );
			$this->assertStringNotContainsString( 'checkin', $action->get_hook() );
		}
	}

	/**
	 * Eski sürümden yerinde yükseltme: kancası kalmamış `slotiva` işleri süpürülür; Lite'ın kendi işi ve başka grup kalır.
	 */
	public function test_upgrade_sweeps_only_orphan_jobs_of_slotiva_group(): void {
		as_schedule_recurring_action( time() + 3600, 3600, 'slotiva_orphan_test', array(), 'slotiva' );
		as_schedule_recurring_action( time() + 3600, 3600, HoldScheduler::SWEEP_HOOK, array(), 'slotiva' );
		as_schedule_single_action( time() + 3600, 'slotivapro_orphan_test', array(), 'slotivapro' );

		update_option( 'slotiva_db_version', '2' );
		Schema::maybe_install();
		$this->assertNotFalse( has_action( 'init', array( OrphanJobs::class, 'sweep' ) ), 'yükseltme süpürmeyi kuyruğa alır' );
		OrphanJobs::sweep();

		$this->assertFalse( as_next_scheduled_action( 'slotiva_orphan_test', array(), 'slotiva' ) );
		$this->assertNotFalse( as_next_scheduled_action( HoldScheduler::SWEEP_HOOK, array(), 'slotiva' ) );
		$this->assertNotFalse( as_next_scheduled_action( 'slotivapro_orphan_test', array(), 'slotivapro' ) );
		as_unschedule_all_actions( '', array(), 'slotivapro' );
	}

	public function test_no_sweep_queued_when_schema_is_current(): void {
		remove_all_actions( 'init' );
		Schema::maybe_install();
		$this->assertFalse( has_action( 'init', array( OrphanJobs::class, 'sweep' ) ) );
	}
}
