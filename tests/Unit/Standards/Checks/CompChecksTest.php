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
 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-007
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
 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-007
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
	 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-007
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
	 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-007
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
	 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-007
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
	 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-007
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
	 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-007
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
	 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-007
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
	 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-007
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
	 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-007
	 */
	public function testUnresolvableBandIsVacuous(): void {
		$adjustment = ['status' => 'approved', 'targetBandId' => 'no-such-band', 'proposedSalary' => 999999999];
		$violations = RuleEngine::evaluate('CompAdjustment', $adjustment, $this->context('band-a', 300000, 420000));

		$this->assertFalse($this->hasViolation($violations, 'comp-adjustment-within-band'));

	}//end testUnresolvableBandIsVacuous()

	/**
	 * The within-band verdict for every shape of adjustment and band.
	 *
	 * @return void
	 */
	public function testTheWithinBandVerdictForEveryShape(): void {
		$check = CompChecks::checks()['CompAdjustment']['comp-adjustment-within-band'];
		$context = ['comp' => ['salaryBandsById' => ['b-1' => ['minSalary' => 3000, 'maxSalary' => '4200.5'], 'b-2' => ['minSalary' => 'x', 'maxSalary' => 5000], 'b-3' => 'not-a-band']]];
		$adjustment = ['status' => 'proposed', 'targetBandId' => 'b-1', 'proposedSalary' => 3500];
		$cases = [
			'inside' => [$adjustment, $context],
			'at-min' => [array_merge($adjustment, ['proposedSalary' => '3000']), $context],
			'at-max' => [array_merge($adjustment, ['proposedSalary' => 4200.5]), $context],
			'below' => [array_merge($adjustment, ['proposedSalary' => 2999.99]), $context],
			'above' => [array_merge($adjustment, ['status' => 'effective', 'proposedSalary' => 4201]), $context],
			'approved-above' => [array_merge($adjustment, ['status' => 'approved', 'proposedSalary' => 9000]), $context],
			'draft-above' => [array_merge($adjustment, ['status' => 'draft', 'proposedSalary' => 9000]), $context],
			'no-status' => [['targetBandId' => 'b-1', 'proposedSalary' => 9000], $context],
			'no-band' => [array_merge($adjustment, ['targetBandId' => ' ', 'proposedSalary' => 9000]), $context],
			'padded-band' => [array_merge($adjustment, ['targetBandId' => ' b-1 ', 'proposedSalary' => 9000]), $context],
			'unknown-band' => [array_merge($adjustment, ['targetBandId' => 'b-9', 'proposedSalary' => 9000]), $context],
			'odd-band' => [array_merge($adjustment, ['targetBandId' => 'b-3', 'proposedSalary' => 9000]), $context],
			'band-without-min' => [array_merge($adjustment, ['targetBandId' => 'b-2', 'proposedSalary' => 9000]), $context],
			'no-salary' => [array_merge($adjustment, ['proposedSalary' => null]), $context],
			'no-context' => [array_merge($adjustment, ['proposedSalary' => 9000]), []],
		];
		$got = [];
		foreach ($cases as $name => [$o, $c]) {
			$got[$name] = $check($o, $c);
		}

		$this->assertSame(
			[
				'inside' => true,
				'at-min' => true,
				'at-max' => true,
				'below' => false,
				'above' => false,
				'approved-above' => false,
				'draft-above' => true,
				'no-status' => true,
				'no-band' => true,
				'padded-band' => false,
				'unknown-band' => true,
				'odd-band' => true,
				'band-without-min' => true,
				'no-salary' => true,
				'no-context' => true,
			],
			$got
		);
	}//end testTheWithinBandVerdictForEveryShape()

}//end class
