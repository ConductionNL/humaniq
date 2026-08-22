<?php

/**
 * Unit tests for the DGA gebruikelijkloon check (NlDgaChecks).
 *
 * Pins the `nl-gebruikelijkloon-norm` predicate (design.md D5, spec.md
 * REQ-DGA-004): vacuous for a non-DGA employee regardless of salary,
 * vacuous when a non-empty `gebruikelijkloonJustification` is on file (MVP:
 * presence-only exemption), vacuous when `grossMonthlySalary` is
 * absent/non-numeric, else satisfied only when the annualised salary reaches
 * the REAL `lib/Standards/tables/nl-2026.json` gebruikelijkloon norm
 * (EUR 58.000,00/jaar) via `TaxTables::gebruikelijkloon()` — never a
 * hardcoded figure. The suite closes with a REAL `RuleEngine::evaluate()`
 * integration test (catalogue + auto-discovered CheckProviders + the
 * nl-2026 table) proving the rule is genuinely reachable, not an orphaned
 * capability.
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
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlDgaChecks;
use OCA\Humaniq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlDgaChecks (raw predicate + through the REAL RuleEngine).
 *
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-004
 */
class NlDgaChecksTest extends TestCase {

	/**
	 * The registered predicates.
	 *
	 * @var array<string, array<string, callable>>
	 */
	private array $checks;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		RuleEngine::reset();
		$this->checks = NlDgaChecks::checks();

	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		RuleEngine::reset();

	}//end tearDown()

	/**
	 * A minimal Employee fixture, overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function employee(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'emp-1',
				'isDga' => false,
				'grossMonthlySalary' => 1500.00,
			],
			$overrides
		);

	}//end employee()

	/**
	 * REQ-DGA-004 scenario: a non-DGA employee is out of scope regardless of
	 * salary — `isDga: false` (or absent) never triggers the check.
	 *
	 * @return void
	 */
	public function testNonDgaEmployeeIsVacuousRegardlessOfSalary(): void {
		$check = $this->checks['Employee']['nl-gebruikelijkloon-norm'];

		$this->assertTrue($check($this->employee(['isDga' => false, 'grossMonthlySalary' => 1500.00]), []));
		$this->assertTrue($check($this->employee(['isDga' => null, 'grossMonthlySalary' => 1500.00]), []));

	}//end testNonDgaEmployeeIsVacuousRegardlessOfSalary()

	/**
	 * REQ-DGA-004 scenario: a below-norm DGA with no justification is
	 * flagged — `isDga: true`, `grossMonthlySalary: 3000.00` (annualised
	 * €36.000, below the €58.000 norm), no `gebruikelijkloonJustification`.
	 *
	 * @return void
	 */
	public function testBelowNormDgaWithNoJustificationIsFlagged(): void {
		$check = $this->checks['Employee']['nl-gebruikelijkloon-norm'];

		$employee = $this->employee(['isDga' => true, 'grossMonthlySalary' => 3000.00]);

		$this->assertFalse($check($employee, []));

	}//end testBelowNormDgaWithNoJustificationIsFlagged()

	/**
	 * REQ-DGA-004 scenario: the SAME below-norm DGA with a non-empty
	 * `gebruikelijkloonJustification` on file passes (MVP: presence-only
	 * exemption).
	 *
	 * @return void
	 */
	public function testBelowNormDgaWithJustificationPasses(): void {
		$check = $this->checks['Employee']['nl-gebruikelijkloon-norm'];

		$employee = $this->employee(
			[
				'isDga' => true,
				'grossMonthlySalary' => 3000.00,
				'gebruikelijkloonJustification' => 'Vergelijkbare functie elders verdient minder; onderbouwing in personeelsdossier.',
			]
		);

		$this->assertTrue($check($employee, []));

	}//end testBelowNormDgaWithJustificationPasses()

	/**
	 * A whitespace-only justification does NOT count as present.
	 *
	 * @return void
	 */
	public function testWhitespaceOnlyJustificationDoesNotExempt(): void {
		$check = $this->checks['Employee']['nl-gebruikelijkloon-norm'];

		$employee = $this->employee(
			[
				'isDga' => true,
				'grossMonthlySalary' => 3000.00,
				'gebruikelijkloonJustification' => '   ',
			]
		);

		$this->assertFalse($check($employee, []));

	}//end testWhitespaceOnlyJustificationDoesNotExempt()

	/**
	 * An at/above-norm DGA passes without any justification —
	 * €58.000/12 = €4.833,34 monthly clears the 2026 norm.
	 *
	 * @return void
	 */
	public function testAtOrAboveNormDgaPasses(): void {
		$check = $this->checks['Employee']['nl-gebruikelijkloon-norm'];

		$atNorm = $this->employee(['isDga' => true, 'grossMonthlySalary' => (58000 / 12)]);
		$aboveIt = $this->employee(['isDga' => true, 'grossMonthlySalary' => 6000.00]);

		$this->assertTrue($check($atNorm, []));
		$this->assertTrue($check($aboveIt, []));

	}//end testAtOrAboveNormDgaPasses()

	/**
	 * A DGA with no `grossMonthlySalary` yet (hourly/no-salary path) is
	 * vacuous — never a false violation on incomplete data.
	 *
	 * @return void
	 */
	public function testDgaWithoutSalaryYetIsVacuous(): void {
		$check = $this->checks['Employee']['nl-gebruikelijkloon-norm'];

		$employee = $this->employee(['isDga' => true, 'grossMonthlySalary' => null]);

		$this->assertTrue($check($employee, []));

	}//end testDgaWithoutSalaryYetIsVacuous()

	/**
	 * REQ-DGA-004 — the below-norm/no-justification scenario, driven through
	 * the REAL `RuleEngine::evaluate()` (catalogue + auto-discovered
	 * CheckProviders + the nl-2026 table), proving
	 * `nl-gebruikelijkloon-norm` is genuinely reachable via
	 * `occ humaniq:rules:audit` and not an orphaned capability.
	 *
	 * @return void
	 */
	public function testRealRuleEngineFiresTheViolationForABelowNormDga(): void {
		$employee = $this->employee(['isDga' => true, 'grossMonthlySalary' => 3000.00]);

		$violations = RuleEngine::evaluate('Employee', $employee);

		$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);
		$this->assertContains('nl-gebruikelijkloon-norm', $ruleIds, 'The real RuleEngine must fire nl-gebruikelijkloon-norm for a below-norm DGA with no justification.');

		$violation = $violations[array_search('nl-gebruikelijkloon-norm', $ruleIds, true)];
		$this->assertSame('conditional', $violation->severity, 'nl-gebruikelijkloon-norm must be severity conditional (a below-norm salary can be lawful when justified).');

	}//end testRealRuleEngineFiresTheViolationForABelowNormDga()

	/**
	 * The mirror-image REAL RuleEngine check: a justified below-norm DGA
	 * produces NO `nl-gebruikelijkloon-norm` violation.
	 *
	 * @return void
	 */
	public function testRealRuleEngineIsSilentWhenJustified(): void {
		$employee = $this->employee(
			[
				'isDga' => true,
				'grossMonthlySalary' => 3000.00,
				'gebruikelijkloonJustification' => 'Start-up dispensatie, onderbouwd bij de Belastingdienst.',
			]
		);

		$violations = RuleEngine::evaluate('Employee', $employee);

		$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);
		$this->assertNotContains('nl-gebruikelijkloon-norm', $ruleIds);

	}//end testRealRuleEngineIsSilentWhenJustified()

	/**
	 * The mirror-image REAL RuleEngine check: a non-DGA employee never fires
	 * the rule, regardless of salary.
	 *
	 * @return void
	 */
	public function testRealRuleEngineIsSilentForANonDgaEmployee(): void {
		$employee = $this->employee(['isDga' => false, 'grossMonthlySalary' => 1500.00]);

		$violations = RuleEngine::evaluate('Employee', $employee);

		$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);
		$this->assertNotContains('nl-gebruikelijkloon-norm', $ruleIds);

	}//end testRealRuleEngineIsSilentForANonDgaEmployee()

}//end class
