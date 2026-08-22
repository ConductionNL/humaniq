<?php

/**
 * Unit tests for the NL statutory-leave (verlofsaldo) checks.
 *
 * Pins the four leave predicates on LeaveBalance: statutory minimum (4x
 * contractual weekly hours, vacuous pass when the contractHoursPerWeek
 * snapshot is null), non-negative balance (used hours never exceed the total
 * entitlement), the 1-July-following-year vervaltermijn (leave-verzuim-mvp),
 * and the leave-buy-sell audit-time backstop (bovenwettelijkHours never
 * negative) -- the last one driven through the REAL
 * `RuleEngine::evaluate('LeaveBalance', ...)` too (not just the raw
 * callable), so the auto-discovery + rule-index wiring is exercised
 * end-to-end.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leave-verzuim-mvp/specs/leave-management/spec.md
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlLeaveChecks;
use OCA\Humaniq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlLeaveChecks (raw predicates + the leave-buy-sell backstop
 * through RuleEngine::evaluate).
 *
 * @spec openspec/changes/leave-verzuim-mvp/specs/leave-management/spec.md
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-003
 */
class NlLeaveChecksTest extends TestCase {

	/**
	 * The registered LeaveBalance predicates, keyed by rule id.
	 *
	 * @var array<string, callable>
	 */
	private array $checks;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->checks = NlLeaveChecks::checks()['LeaveBalance'];

	}//end setUp()

	/**
	 * A minimal, fully-compliant LeaveBalance fixture; each test overrides
	 * the fields it exercises (matches the seeded employee-jansen balance).
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function balance(array $overrides = []): array {
		return array_merge(
			[
				'employeeId' => 'employee-jansen',
				'year' => 2026,
				'leaveType' => 'holiday',
				'entitledHours' => 160,
				'bovenwettelijkHours' => 40,
				'usedHours' => 56,
				'contractHoursPerWeek' => 40,
				'expiryDate' => '2027-07-01',
			],
			$overrides
		);

	}//end balance()

	/**
	 * @return void
	 */
	public function testCompliantBalancePassesAllFourChecks(): void {
		$balance = $this->balance();
		foreach ($this->checks as $ruleId => $predicate) {
			$this->assertTrue((bool)$predicate($balance), sprintf('compliant balance violates %s', $ruleId));
		}

	}//end testCompliantBalancePassesAllFourChecks()

	/**
	 * @return void
	 */
	public function testWettelijkMinimumViolatedWhenEntitledBelowFourTimesWeekly(): void {
		// Seeded scenario from REQ-LVM-005: contractHoursPerWeek 36, entitledHours
		// 120 — 4x36 = 144, so 120 is under-granted.
		$balance = $this->balance(['contractHoursPerWeek' => 36, 'entitledHours' => 120, 'usedHours' => 16, 'bovenwettelijkHours' => 0]);
		$this->assertFalse(($this->checks['nl-verlof-wettelijk-minimum'])($balance));

	}//end testWettelijkMinimumViolatedWhenEntitledBelowFourTimesWeekly()

	/**
	 * @return void
	 */
	public function testWettelijkMinimumSatisfiedAtExactlyFourTimesWeekly(): void {
		$balance = $this->balance(['contractHoursPerWeek' => 36, 'entitledHours' => 144]);
		$this->assertTrue(($this->checks['nl-verlof-wettelijk-minimum'])($balance));

	}//end testWettelijkMinimumSatisfiedAtExactlyFourTimesWeekly()

	/**
	 * @return void
	 */
	public function testWettelijkMinimumVacuouslyPassesWhenSnapshotNull(): void {
		$balance = $this->balance(['contractHoursPerWeek' => null, 'entitledHours' => 8]);
		$this->assertTrue(($this->checks['nl-verlof-wettelijk-minimum'])($balance));

	}//end testWettelijkMinimumVacuouslyPassesWhenSnapshotNull()

	/**
	 * @return void
	 */
	public function testSaldoNietNegatiefViolatedWhenUsedExceedsTotal(): void {
		// Seeded scenario from REQ-LVM-005: entitledHours 128, bovenwettelijk 0,
		// usedHours 140 — 140 > 128.
		$balance = $this->balance(['entitledHours' => 128, 'bovenwettelijkHours' => 0, 'usedHours' => 140]);
		$this->assertFalse(($this->checks['nl-verlof-saldo-niet-negatief'])($balance));

	}//end testSaldoNietNegatiefViolatedWhenUsedExceedsTotal()

	/**
	 * @return void
	 */
	public function testSaldoNietNegatiefSatisfiedWhenUsedEqualsTotal(): void {
		$balance = $this->balance(['entitledHours' => 100, 'bovenwettelijkHours' => 0, 'usedHours' => 100]);
		$this->assertTrue(($this->checks['nl-verlof-saldo-niet-negatief'])($balance));

	}//end testSaldoNietNegatiefSatisfiedWhenUsedEqualsTotal()

	/**
	 * @return void
	 */
	public function testVervaltermijnViolatedWhenExpiryDateWrong(): void {
		$balance = $this->balance(['year' => 2026, 'expiryDate' => '2026-07-01']);
		$this->assertFalse(($this->checks['nl-verlof-vervaltermijn'])($balance));

	}//end testVervaltermijnViolatedWhenExpiryDateWrong()

	/**
	 * @return void
	 */
	public function testVervaltermijnViolatedWhenExpiryDateMissing(): void {
		$balance = $this->balance(['year' => 2026, 'expiryDate' => null]);
		$this->assertFalse(($this->checks['nl-verlof-vervaltermijn'])($balance));

	}//end testVervaltermijnViolatedWhenExpiryDateMissing()

	/**
	 * @return void
	 */
	public function testVervaltermijnSatisfiedForCorrectFollowingYear(): void {
		$balance = $this->balance(['year' => 2026, 'expiryDate' => '2027-07-01']);
		$this->assertTrue(($this->checks['nl-verlof-vervaltermijn'])($balance));

	}//end testVervaltermijnSatisfiedForCorrectFollowingYear()

	/**
	 * @return void
	 */
	public function testVervaltermijnVacuouslyPassesWhenNoEntitlement(): void {
		$balance = $this->balance(['entitledHours' => 0, 'expiryDate' => null]);
		$this->assertTrue(($this->checks['nl-verlof-vervaltermijn'])($balance));

	}//end testVervaltermijnVacuouslyPassesWhenNoEntitlement()

	/**
	 * @return void
	 */
	public function testAllFourRuleIdsAreRegistered(): void {
		$this->assertArrayHasKey('nl-verlof-wettelijk-minimum', $this->checks);
		$this->assertArrayHasKey('nl-verlof-saldo-niet-negatief', $this->checks);
		$this->assertArrayHasKey('nl-verlof-vervaltermijn', $this->checks);
		$this->assertArrayHasKey('nl-verlof-bovenwettelijk-niet-negatief', $this->checks);

	}//end testAllFourRuleIdsAreRegistered()

	/**
	 * leave-buy-sell REQ-BUYSELL-003 (raw predicate): a negative
	 * bovenwettelijkHours violates the backstop.
	 *
	 * @return void
	 */
	public function testBovenwettelijkNietNegatiefViolatedWhenNegative(): void {
		$balance = $this->balance(['bovenwettelijkHours' => -4]);
		$this->assertFalse(($this->checks['nl-verlof-bovenwettelijk-niet-negatief'])($balance));

	}//end testBovenwettelijkNietNegatiefViolatedWhenNegative()

	/**
	 * leave-buy-sell REQ-BUYSELL-003 (raw predicate): zero or positive
	 * bovenwettelijkHours satisfies the backstop.
	 *
	 * @return void
	 */
	public function testBovenwettelijkNietNegatiefSatisfiedWhenZeroOrPositive(): void {
		$this->assertTrue(($this->checks['nl-verlof-bovenwettelijk-niet-negatief'])($this->balance(['bovenwettelijkHours' => 0])));
		$this->assertTrue(($this->checks['nl-verlof-bovenwettelijk-niet-negatief'])($this->balance(['bovenwettelijkHours' => 40])));

	}//end testBovenwettelijkNietNegatiefSatisfiedWhenZeroOrPositive()

	/**
	 * leave-buy-sell REQ-BUYSELL-003: a negative bovenwettelijkHours produces
	 * the `nl-verlof-bovenwettelijk-niet-negatief` Violation at `mandatory`
	 * severity, driven through the REAL `RuleEngine::evaluate()` -- exercising
	 * the auto-discovery + rule-index wiring, not just the raw callable.
	 *
	 * @return void
	 */
	public function testNegativeBovenwettelijkProducesViolationThroughRuleEngine(): void {
		$balance = $this->balance(['bovenwettelijkHours' => -4]);
		$violations = RuleEngine::evaluate('LeaveBalance', $balance, ['jurisdiction' => 'NL']);

		$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);
		$this->assertContains('nl-verlof-bovenwettelijk-niet-negatief', $ruleIds);

		foreach ($violations as $violation) {
			if ($violation->ruleId === 'nl-verlof-bovenwettelijk-niet-negatief') {
				$this->assertSame('mandatory', $violation->severity);
			}
		}

	}//end testNegativeBovenwettelijkProducesViolationThroughRuleEngine()

	/**
	 * leave-buy-sell REQ-BUYSELL-003: a compliant balance produces no
	 * `nl-verlof-bovenwettelijk-niet-negatief` violation through
	 * RuleEngine::evaluate().
	 *
	 * @return void
	 */
	public function testCompliantBalanceProducesNoBovenwettelijkViolationThroughRuleEngine(): void {
		$balance = $this->balance();
		$violations = RuleEngine::evaluate('LeaveBalance', $balance, ['jurisdiction' => 'NL']);

		$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);
		$this->assertNotContains('nl-verlof-bovenwettelijk-niet-negatief', $ruleIds);

	}//end testCompliantBalanceProducesNoBovenwettelijkViolationThroughRuleEngine()

}//end class
