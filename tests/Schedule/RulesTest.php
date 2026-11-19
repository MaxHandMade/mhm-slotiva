<?php
/**
 * Kurallar testleri.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Schedule;

use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- alan adları sözleşmeyle camelCase (spec §5).

class RulesTest extends SlotivaTestCase {

	public function test_rules_defaults(): void {
		$r = Rules::load();
		$this->assertSame( 30, $r->durationDefault );
		$this->assertSame( 24, $r->leadHours );
		$this->assertSame( 14, $r->horizonDays );
		$this->assertSame( 0, $r->bufferMin );
		$this->assertSame( 24, $r->bankHoldHours );
		$this->assertSame( 45, $r->cardHoldMin );
		$this->assertTrue( $r->reminderOn );
		$this->assertSame( 24, $r->reminderHours );
		$this->assertSame( '', $r->policyText );
		$this->assertSame( 24, $r->retentionMonths );
		$this->assertFalse( $r->deleteDataOnUninstall );
	}

	public function test_rules_reject_non_multiple_of_15(): void {
		$res = Rules::save( array( 'durationDefault' => 20 ) );
		$this->assertWPError( $res );
		$this->assertSame( 'slotiva_invalid_step', $res->get_error_code() );
		$this->assertSame( 30, Rules::load()->durationDefault );
	}

	public function test_rules_horizon_capped_62(): void {
		$this->assertWPError( Rules::save( array( 'horizonDays' => 63 ) ) );
		$this->assertWPError( Rules::save( array( 'horizonDays' => 0 ) ) );
		$ok = Rules::save( array( 'horizonDays' => 62 ) );
		$this->assertInstanceOf( Rules::class, $ok );
		$this->assertSame( 62, Rules::load()->horizonDays );
	}

	public function test_rules_card_hold_minimum_15(): void {
		$this->assertWPError( Rules::save( array( 'cardHoldMin' => 10 ) ) );
		$this->assertInstanceOf( Rules::class, Rules::save( array( 'cardHoldMin' => 15 ) ) );
	}

	public function test_rules_save_merges_and_keeps_uninstall_flag_in_same_option(): void {
		Rules::save(
			array(
				'deleteDataOnUninstall' => true,
				'policyText'            => 'Kural metni',
			)
		);
		$raw = get_option( 'slotiva_rules' );
		$this->assertTrue( $raw['deleteDataOnUninstall'] );
		$this->assertSame( 'Kural metni', Rules::load()->policyText );
		$this->assertSame( 30, Rules::load()->durationDefault );
	}

	public function test_saves_fire_schedule_changed(): void {
		$n = 0;
		add_action(
			'slotiva_schedule_changed',
			static function () use ( &$n ) {
				++$n;
			}
		);
		Rules::save( array( 'durationDefault' => 45 ) );
		$this->assertSame( 1, $n );
		Rules::save( array( 'durationDefault' => 20 ) );
		$this->assertSame( 1, $n, 'başarısız kayıt olay tetiklememeli' );
	}

	public function test_duration_default_above_max_rejected(): void {
		$res = Rules::save( array( 'durationDefault' => 495 ) );
		$this->assertWPError( $res );
		$this->assertSame( 30, Rules::load()->durationDefault );
		$this->assertNotWPError( Rules::save( array( 'durationDefault' => Rules::DURATION_MAX ) ) );
	}

	public function test_stored_over_max_does_not_block_other_saves(): void {
		update_option( Rules::OPTION, array( 'durationDefault' => 600 ), false );
		$this->assertNotWPError( Rules::save( array( 'bufferMin' => 15 ) ) );
		$this->assertSame( 600, Rules::load()->durationDefault );
	}

	public function test_resending_stored_over_max_duration_does_not_block_save(): void {
		update_option( Rules::OPTION, array( 'durationDefault' => 600 ), false );
		$this->assertNotWPError(
			Rules::save(
				array(
					'durationDefault' => 600,
					'bufferMin'       => 15,
				)
			)
		);
		$this->assertSame( 600, Rules::load()->durationDefault );
		$this->assertSame( 15, Rules::load()->bufferMin );
	}

	public function test_changed_duration_above_max_gets_invalid_value_code(): void {
		update_option( Rules::OPTION, array( 'durationDefault' => 600 ), false );
		$res = Rules::save( array( 'durationDefault' => 495 ) );
		$this->assertWPError( $res );
		$this->assertSame( 'slotiva_invalid_value', $res->get_error_code() );
		$this->assertSame( 600, Rules::load()->durationDefault );
	}
}
