<?php

/**
 * Unit tests for the within-band compensation check (CompChecks).
 *
 * Drives the `comp-adjustment-within-band` predicate through the REAL
 * RuleEngine + RuleCatalogue corpus (not the raw closure) so the test also
 * proves the corpus rule exists, is machine-checkable, and is reachable —
 * i.e. NOT an orphaned capability (REQ-COMP-007).
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
 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\CompChecks;
use OCA\Humaniq\Standards\RuleCatalogue;
use OCA\Humaniq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CompChecks, driven through the real RuleEngine.
 *
 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
 */
class CompChecksTest extends TestCase {

	/**
	 * Reset every statically-memoised layer so each test loads the real
	 * catalogue/corpus fresh.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		RuleEngine::reset();
		RuleCatalogue::reset();

	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		RuleEngine::reset();
		RuleCatalogue::reset();

	}//end tearDown()

	/**
	 * Whether the evaluated violations contain a given rule id.
	 *
	 * @param array<int, \OCA\Humaniq\Standards\Violation> $violations The violations.
	 * @param string $ruleId The rule id to look for.
	 *
	 * @return bool
	 */
	private function hasViolation(array $violations, string $ruleId): bool {
		foreach ($violations as $violation) {
			if ($violation->ruleId === $ruleId) {
				return true;
			}
		}

		return false;
	}//end hasViolation()

	/**
	 * A comp context with one SalaryBand's [minSalary, maxSalary].
	 *
	 * @param string $bandId The band id.
	 * @param int $minSalary Minimum salary, in cents.
	 * @param int $maxSalary Maximum salary, in cents.
	 *
	 * @return array<string, mixed>
	 */
	private function context(string $bandId, int $minSalary, int $maxSalary): array {
		return [
			'comp' => [
				'salaryBandsById' => [
					$bandId => ['minSalary' => $minSalary, 'maxSalary' => $maxSalary],
				],
			],
		];

	}//end context()

	/**
	 * The rule is registered against CompAdjustment AND wired to the corpus —
	 * i.e. reachable, not an orphaned predicate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
	 */
	public function testWithinBandCheckIsReachableFromTheEngine(): void {
		$this->assertArrayHasKey('comp-adjustment-within-band', (CompChecks::checks()['CompAdjustment'] ?? []));
		$this->assertContains('comp-adjustment-within-band', RuleEngine::checkedRuleIds());

	}//end testWithinBandCheckIsReachableFromTheEngine()

	/**
	 * A proposal above the band maximum violates.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
	 */
	public function testAboveMaxRaisesMandatoryViolation(): void {
		$adjustment = ['status' => 'proposed', 'targetBandId' => 'band-a', 'proposedSalary' => 500000];
		$violations = RuleEngine::evaluate('CompAdjustment', $adjustment, $this->context('band-a', 300000, 420000));

		$this->assertTrue($this->hasViolation($violations, 'comp-adjustment-within-band'));

		$rule = null;
		foreach ($violations as $violation) {
			if ($violation->ruleId === 'comp-adjustment-within-band') {
				$rule = $violation;
			}
		}

		$this->assertNotNull($rule);
		$this->assertSame('mandatory', $rule->severity);

	}//end testAboveMaxRaisesMandatoryViolation()

	/**
	 * A proposal below the band minimum violates.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
	 */
	public function testBelowMinRaisesMandatoryViolation(): void {
		$adjustment = ['status' => 'approved', 'targetBandId' => 'band-a', 'proposedSalary' => 100000];
		$violations = RuleEngine::evaluate('CompAdjustment', $adjustment, $this->context('band-a', 300000, 420000));

		$this->assertTrue($this->hasViolation($violations, 'comp-adjustment-within-band'));

	}//end testBelowMinRaisesMandatoryViolation()

	/**
	 * A proposal within the band range passes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
	 */
	public function testWithinBandPasses(): void {
		$adjustment = ['status' => 'effective', 'targetBandId' => 'band-a', 'proposedSalary' => 360000];
		$violations = RuleEngine::evaluate('CompAdjustment', $adjustment, $this->context('band-a', 300000, 420000));

		$this->assertFalse($this->hasViolation($violations, 'comp-adjustment-within-band'));

	}//end testWithinBandPasses()

	/**
	 * The band boundaries themselves are inclusive.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
	 */
	public function testBoundaryValuesPass(): void {
		$context = $this->context('band-a', 300000, 420000);

		$atMin = RuleEngine::evaluate('CompAdjustment', ['status' => 'approved', 'targetBandId' => 'band-a', 'proposedSalary' => 300000], $context);
		$atMax = RuleEngine::evaluate('CompAdjustment', ['status' => 'approved', 'targetBandId' => 'band-a', 'proposedSalary' => 420000], $context);

		$this->assertFalse($this->hasViolation($atMin, 'comp-adjustment-within-band'));
		$this->assertFalse($this->hasViolation($atMax, 'comp-adjustment-within-band'));

	}//end testBoundaryValuesPass()

	/**
	 * A band-less proposal (targetBandId null) is out of scope — vacuous.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
	 */
	public function testNullTargetBandIsVacuous(): void {
		$adjustment = ['status' => 'approved', 'targetBandId' => null, 'proposedSalary' => 999999999];
		$violations = RuleEngine::evaluate('CompAdjustment', $adjustment, $this->context('band-a', 300000, 420000));

		$this->assertFalse($this->hasViolation($violations, 'comp-adjustment-within-band'));

	}//end testNullTargetBandIsVacuous()

	/**
	 * A still-draft proposal is out of scope — vacuous (nothing proposed
	 * yet).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
	 */
	public function testDraftStatusIsVacuous(): void {
		$adjustment = ['status' => 'draft', 'targetBandId' => 'band-a', 'proposedSalary' => 999999999];
		$violations = RuleEngine::evaluate('CompAdjustment', $adjustment, $this->context('band-a', 300000, 420000));

		$this->assertFalse($this->hasViolation($violations, 'comp-adjustment-within-band'));

	}//end testDraftStatusIsVacuous()

	/**
	 * An unresolvable band (not present in the audit context) is vacuous —
	 * nothing decidable from this object alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
	 */
	public function testUnresolvableBandIsVacuous(): void {
		$adjustment = ['status' => 'approved', 'targetBandId' => 'no-such-band', 'proposedSalary' => 999999999];
		$violations = RuleEngine::evaluate('CompAdjustment', $adjustment, $this->context('band-a', 300000, 420000));

		$this->assertFalse($this->hasViolation($violations, 'comp-adjustment-within-band'));

	}//end testUnresolvableBandIsVacuous()

}//end class
