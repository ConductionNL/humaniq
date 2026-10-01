<?php

/**
 * Unit tests for the 30%-ruling predicates of NlPayrollChecks.
 *
 * Pins the three 30-procent-regeling predicates (spec.md REQ-30P-004):
 * `nl-30-regeling-looptijd-5jaar` (Employee — term absent/beyond 60 months/
 * stale), `nl-30-regeling-aftoppingsgrens-bedrag` (Payslip — the WNT-capped
 * exemption re-derives cents-exact from the referenced Employee's rate), and
 * `nl-30-regeling-salarisnorm` (Employee — annualised salary at/above the
 * applicable norm). Each suite closes with a REAL `RuleEngine::evaluate()`
 * integration test proving the rule is genuinely reachable via
 * `occ humaniq:rules:audit`, not an orphaned capability (the `NlFleetChecksTest`
 * precedent). Also verifies the seeded Employee/Payslip audit clean on all
 * five 30%-ruling checks (task 12).
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
 * @spec openspec/specs/30-procent-regeling/spec.md#REQ-30P-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlPayrollChecks;
use OCA\Humaniq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the NlPayrollChecks 30%-ruling predicates (raw + through RuleEngine).
 *
 * @spec openspec/specs/30-procent-regeling/spec.md#REQ-30P-004
 */
class NlPayrollChecksTest extends TestCase {

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
		$this->checks = NlPayrollChecks::checks();

	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		RuleEngine::reset();

	}//end tearDown()

	/**
	 * A granted-ruling Employee fixture, overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function employee(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'emp-1',
				'thirtyPercentRulingGranted' => true,
				'thirtyPercentRulingRate' => 30.0,
				'thirtyPercentRulingStartDate' => '2024-03-01',
				'thirtyPercentRulingEndDate' => '2029-02-28',
				'thirtyPercentRulingReducedNormApplies' => false,
				'grossMonthlySalary' => 5000.00,
			],
			$overrides
		);

	}//end employee()

	/**
	 * A `context['payroll']['employeesById']` fixture.
	 *
	 * @param array<int, array<string, mixed>> $employees The Employee rows.
	 *
	 * @return array<string, mixed>
	 */
	private function context(array $employees): array {
		$employeesById = [];
		foreach ($employees as $employee) {
			$key = (string)($employee['id'] ?? $employee['employeeNumber'] ?? '');
			$employeesById[$key] = $employee;
		}

		return [
			'jurisdiction' => 'NL',
			'payroll' => ['employeesById' => $employeesById],
		];

	}//end context()

	// ------------------------------------------------------------------ looptijd

	/**
	 * REQ-30P-004 Scenario 1 — a ruling past its 5-year term (end date already
	 * passed while still granted) is flagged.
	 *
	 * @return void
	 */
	public function testRulingPastItsTermIsFlagged(): void {
		$check = $this->checks['Employee']['nl-30-regeling-looptijd-5jaar'];

		self::assertFalse(
			$check($this->employee(['thirtyPercentRulingStartDate' => '2019-01-01', 'thirtyPercentRulingEndDate' => '2024-12-31']))
		);

	}//end testRulingPastItsTermIsFlagged()

	/**
	 * REQ-30P-004 Scenario 2 — an end date more than 60 months after the start
	 * date is flagged.
	 *
	 * @return void
	 */
	public function testEndDateBeyond60MonthsIsFlagged(): void {
		$check = $this->checks['Employee']['nl-30-regeling-looptijd-5jaar'];

		self::assertFalse(
			$check($this->employee(['thirtyPercentRulingStartDate' => '2024-01-01', 'thirtyPercentRulingEndDate' => '2030-06-01']))
		);

	}//end testEndDateBeyond60MonthsIsFlagged()

	/**
	 * A ruling whose end date is exactly 60 months after the start date and
	 * still in the future passes.
	 *
	 * @return void
	 */
	public function testEndDateExactly60MonthsInTheFuturePasses(): void {
		$check = $this->checks['Employee']['nl-30-regeling-looptijd-5jaar'];

		self::assertTrue(
			$check($this->employee(['thirtyPercentRulingStartDate' => '2024-03-01', 'thirtyPercentRulingEndDate' => '2029-02-28']))
		);

	}//end testEndDateExactly60MonthsInTheFuturePasses()

	/**
	 * A granted ruling missing its start date is an incomplete-but-not-
	 * contradictory record — vacuous (design.md D5), not flagged here.
	 *
	 * @return void
	 */
	public function testGrantedRulingWithoutStartDateIsVacuous(): void {
		$check = $this->checks['Employee']['nl-30-regeling-looptijd-5jaar'];

		self::assertTrue(
			$check($this->employee(['thirtyPercentRulingStartDate' => null, 'thirtyPercentRulingEndDate' => null]))
		);

	}//end testGrantedRulingWithoutStartDateIsVacuous()

	// -------------------------------------------------------------- salarisnorm

	/**
	 * REQ-30P-004 Scenario 5 — a granted ruling below the general salary norm
	 * (annualised €42.000 < €48.013) is flagged.
	 *
	 * @return void
	 */
	public function testBelowGeneralSalaryNormIsFlagged(): void {
		$check = $this->checks['Employee']['nl-30-regeling-salarisnorm'];

		self::assertFalse(
			$check($this->employee(['grossMonthlySalary' => 3500.00, 'thirtyPercentRulingReducedNormApplies' => false]))
		);

	}//end testBelowGeneralSalaryNormIsFlagged()

	/**
	 * REQ-30P-004 Scenario 6 — the same €3.500,00/month employee passes when
	 * the reduced norm applies (annualised €42.000 > €36.497).
	 *
	 * @return void
	 */
	public function testBelowGeneralButAboveReducedNormPassesWhenReducedApplies(): void {
		$check = $this->checks['Employee']['nl-30-regeling-salarisnorm'];

		self::assertTrue(
			$check($this->employee(['grossMonthlySalary' => 3500.00, 'thirtyPercentRulingReducedNormApplies' => true]))
		);

	}//end testBelowGeneralButAboveReducedNormPassesWhenReducedApplies()

	// --------------------------------------------------- aftoppingsgrens-bedrag

	/**
	 * A correctly recorded exemption on the €3.800,00 anchor payslip (€1.140,00
	 * = min(3800, 21833.33) × 30%) passes the cap-amount check.
	 *
	 * @return void
	 */
	public function testCorrectlyRecordedExemptionIsCompliant(): void {
		$check = $this->checks['Payslip']['nl-30-regeling-aftoppingsgrens-bedrag'];
		$context = $this->context([$this->employee()]);

		$payslip = ['employeeId' => 'emp-1', 'period' => '2026-02', 'grossPay' => 3800.00, 'thirtyPercentRulingExemption' => 1140.00];
		self::assertTrue($check($payslip, $context));

	}//end testCorrectlyRecordedExemptionIsCompliant()

	/**
	 * REQ-30P-004 Scenario 3 — a tampered exemption (€1.300,00 recorded while
	 * the formula computes €1.140,00) is flagged.
	 *
	 * @return void
	 */
	public function testTamperedExemptionAmountViolates(): void {
		$check = $this->checks['Payslip']['nl-30-regeling-aftoppingsgrens-bedrag'];
		$context = $this->context([$this->employee()]);

		$payslip = ['employeeId' => 'emp-1', 'period' => '2026-02', 'grossPay' => 3800.00, 'thirtyPercentRulingExemption' => 1300.00];
		self::assertFalse($check($payslip, $context));

	}//end testTamperedExemptionAmountViolates()

	/**
	 * REQ-30P-004 Scenario 4 — a high earner (€25.000,00 gross) whose recorded
	 * exemption correctly reflects the WNT cap (€6.550,00 = min(25.000,00,
	 * 21.833,33) × 30%, NOT the uncapped €7.500,00) passes.
	 *
	 * @return void
	 */
	public function testCorrectlyCappedHighEarnerExemptionIsCompliant(): void {
		$check = $this->checks['Payslip']['nl-30-regeling-aftoppingsgrens-bedrag'];
		$context = $this->context([$this->employee(['grossMonthlySalary' => 25000.00])]);

		$payslip = ['employeeId' => 'emp-1', 'period' => '2026-02', 'grossPay' => 25000.00, 'thirtyPercentRulingExemption' => 6550.00];
		self::assertTrue($check($payslip, $context));

		// The uncapped €7.500,00 (25.000 × 30%) is a violation — it ignores the cap.
		self::assertFalse($check(array_merge($payslip, ['thirtyPercentRulingExemption' => 7500.00]), $context));

	}//end testCorrectlyCappedHighEarnerExemptionIsCompliant()

	/**
	 * A payslip with no recorded exemption (null) is out of scope — vacuous.
	 *
	 * @return void
	 */
	public function testNullExemptionIsVacuous(): void {
		$check = $this->checks['Payslip']['nl-30-regeling-aftoppingsgrens-bedrag'];
		$context = $this->context([$this->employee()]);

		$payslip = ['employeeId' => 'emp-1', 'period' => '2026-02', 'grossPay' => 3800.00, 'thirtyPercentRulingExemption' => null];
		self::assertTrue($check($payslip, $context));

	}//end testNullExemptionIsVacuous()

	// ------------------------------------------------------------ non-granted

	/**
	 * REQ-30P-004 Scenario 7 — a non-granted employee is out of scope for all
	 * three checks regardless of salary or dates.
	 *
	 * @return void
	 */
	public function testNonGrantedEmployeeIsVacuousForAllThreeChecks(): void {
		$nonGranted = [
			'id' => 'emp-2',
			'thirtyPercentRulingGranted' => false,
			'grossMonthlySalary' => 1500.00,
		];

		self::assertTrue($this->checks['Employee']['nl-30-regeling-looptijd-5jaar']($nonGranted));
		self::assertTrue($this->checks['Employee']['nl-30-regeling-salarisnorm']($nonGranted));

		$payslip = ['employeeId' => 'emp-2', 'period' => '2026-02', 'grossPay' => 1500.00, 'thirtyPercentRulingExemption' => null];
		self::assertTrue($this->checks['Payslip']['nl-30-regeling-aftoppingsgrens-bedrag']($payslip, $this->context([$nonGranted])));

	}//end testNonGrantedEmployeeIsVacuousForAllThreeChecks()

	// ------------------------------------------------------------- seed clean

	/**
	 * Task 12 — the seeded Employee and Payslip audit clean on all five
	 * 30%-ruling checks (the two pre-existing structural checks plus the three
	 * new ones).
	 *
	 * @return void
	 */
	public function testSeededObjectsAuditCleanOnAllFiveThirtyPercentChecks(): void {
		$seed = NlPayrollChecks::seedObjects();
		$employee = $seed['Employee'][0];
		$payslip = $seed['Payslip'][0];

		foreach (['nl-30-percent-regeling', 'nl-30-regeling-aftoppingsgrens', 'nl-30-regeling-looptijd-5jaar', 'nl-30-regeling-salarisnorm'] as $ruleId) {
			self::assertTrue($this->checks['Employee'][$ruleId]($employee), $ruleId . ' must pass on the seeded Employee.');
		}

		self::assertTrue(
			$this->checks['Payslip']['nl-30-regeling-aftoppingsgrens-bedrag']($payslip, $this->context([$employee])),
			'nl-30-regeling-aftoppingsgrens-bedrag must pass on the seeded Payslip (no exemption recorded -> vacuous).'
		);

	}//end testSeededObjectsAuditCleanOnAllFiveThirtyPercentChecks()

	// --------------------------------------------------- REAL RuleEngine reach

	/**
	 * The three new predicates are genuinely reachable via the REAL
	 * `RuleEngine::evaluate()` (catalogue + auto-discovered CheckProviders),
	 * proving none is an orphaned capability (`occ humaniq:rules:audit`).
	 *
	 * @return void
	 */
	public function testRealRuleEngineFiresTheThreeNewViolations(): void {
		$termViolations = RuleEngine::evaluate(
			'Employee',
			$this->employee(['thirtyPercentRulingStartDate' => '2019-01-01', 'thirtyPercentRulingEndDate' => '2024-12-31']),
			[]
		);
		self::assertContains('nl-30-regeling-looptijd-5jaar', array_map(static fn ($v) => $v->ruleId, $termViolations));

		$normViolations = RuleEngine::evaluate(
			'Employee',
			$this->employee(['grossMonthlySalary' => 3500.00, 'thirtyPercentRulingReducedNormApplies' => false]),
			[]
		);
		self::assertContains('nl-30-regeling-salarisnorm', array_map(static fn ($v) => $v->ruleId, $normViolations));

		$capViolations = RuleEngine::evaluate(
			'Payslip',
			['employeeId' => 'emp-1', 'period' => '2026-02', 'grossPay' => 3800.00, 'thirtyPercentRulingExemption' => 1300.00],
			$this->context([$this->employee()])
		);
		self::assertContains('nl-30-regeling-aftoppingsgrens-bedrag', array_map(static fn ($v) => $v->ruleId, $capViolations));

	}//end testRealRuleEngineFiresTheThreeNewViolations()

	/**
	 * The mirror-image REAL RuleEngine check: a well-formed granted ruling and
	 * a correctly-recorded exemption produce NO 30%-ruling violation.
	 *
	 * @return void
	 */
	public function testRealRuleEngineIsSilentForACompliantRuling(): void {
		$employeeViolations = RuleEngine::evaluate('Employee', $this->employee(), []);
		$employeeRuleIds = array_map(static fn ($v) => $v->ruleId, $employeeViolations);
		self::assertNotContains('nl-30-regeling-looptijd-5jaar', $employeeRuleIds);
		self::assertNotContains('nl-30-regeling-salarisnorm', $employeeRuleIds);

		$payslipViolations = RuleEngine::evaluate(
			'Payslip',
			['employeeId' => 'emp-1', 'period' => '2026-02', 'grossPay' => 3800.00, 'thirtyPercentRulingExemption' => 1140.00],
			$this->context([$this->employee()])
		);
		self::assertNotContains('nl-30-regeling-aftoppingsgrens-bedrag', array_map(static fn ($v) => $v->ruleId, $payslipViolations));

	}//end testRealRuleEngineIsSilentForACompliantRuling()

	/**
	 * The loonheffingenverklaring retention rule is vacuous when no statement is
	 * on file.
	 *
	 * The retention duty attaches to a document that exists. Whether one must be
	 * on file at all is a different obligation, and no corpus rule covers it, so
	 * reporting its absence here would put the wrong finding on the wrong rule.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/document-dossier-avg/spec.md#REQ-DOSS-002
	 */
	public function testLoonbelastingverklaringRetentionIsVacuousWithoutAStatement(): void {
		$check = $this->checks['Employee']['nl-loonbelastingverklaring-bewaarplicht-5jaar'];

		self::assertTrue($check(['endDate' => '2020-06-30']), 'no statement on file: nothing to retain');
		self::assertTrue(
			$check(['loonheffingenVerklaringOnFile' => false, 'endDate' => '2020-06-30']),
			'an explicit false is still vacuous'
		);

	}//end testLoonbelastingverklaringRetentionIsVacuousWithoutAStatement()

	/**
	 * Satisfied when the statement is kept past the end of the fifth year after
	 * employment ended, and while employment is still running.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/document-dossier-avg/spec.md#REQ-DOSS-002
	 */
	public function testLoonbelastingverklaringRetentionIsSatisfiedAtFiveFullYears(): void {
		$check = $this->checks['Employee']['nl-loonbelastingverklaring-bewaarplicht-5jaar'];

		// Employment ended in 2020, so the clock runs to the end of 2025.
		self::assertTrue($check([
			'loonheffingenVerklaringOnFile' => true,
			'endDate' => '2020-06-30',
			'loonheffingenVerklaringRetainedUntil' => '2025-12-31',
		]));

		self::assertTrue($check([
			'loonheffingenVerklaringOnFile' => true,
			'loonheffingenVerklaringRetainedUntil' => '2030-01-01',
		]), 'still employed: the retention clock has not started');

	}//end testLoonbelastingverklaringRetentionIsSatisfiedAtFiveFullYears()

	/**
	 * Violated when the date is missing, or falls short of the five-year clock.
	 *
	 * The boundary is the END of the fifth calendar year, not the fifth
	 * anniversary, so a date inside 2025 is short for employment ending in 2020.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/document-dossier-avg/spec.md#REQ-DOSS-002
	 */
	public function testLoonbelastingverklaringRetentionIsViolatedWhenShort(): void {
		$check = $this->checks['Employee']['nl-loonbelastingverklaring-bewaarplicht-5jaar'];

		self::assertFalse(
			$check(['loonheffingenVerklaringOnFile' => true, 'endDate' => '2020-06-30']),
			'a statement on file with no retention date recorded'
		);

		self::assertFalse(
			$check([
				'loonheffingenVerklaringOnFile' => true,
				'endDate' => '2020-06-30',
				'loonheffingenVerklaringRetainedUntil' => '2025-06-30',
			]),
			'five years to the day is short: the clock runs to the end of the year'
		);

	}//end testLoonbelastingverklaringRetentionIsViolatedWhenShort()

	/**
	 * The rule is registered on Employee and carries a corpus entry citing its
	 * legal source.
	 *
	 * A predicate with no corpus entry is not enforceable, and a retention period
	 * without a citation is a number somebody guessed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/document-dossier-avg/spec.md#REQ-DOSS-002
	 */
	public function testLoonbelastingverklaringRuleIsCataloguedWithItsSource(): void {
		self::assertArrayHasKey('nl-loonbelastingverklaring-bewaarplicht-5jaar', $this->checks['Employee']);

		$corpus = json_decode(
			(string)file_get_contents(dirname(__DIR__, 4) . '/lib/Standards/rules/payroll.json'),
			true
		);
		$entry = null;
		foreach (($corpus['rules'] ?? []) as $rule) {
			if (($rule['id'] ?? '') === 'nl-loonbelastingverklaring-bewaarplicht-5jaar') {
				$entry = $rule;
			}
		}

		self::assertIsArray($entry, 'the predicate exists but the corpus does not catalogue it');
		self::assertSame('recommended', $entry['severity']);
		self::assertTrue($entry['machineCheckable']);
		self::assertStringContainsString('Uitvoeringsregeling loonbelasting 2011', (string)$entry['source']);

	}//end testLoonbelastingverklaringRuleIsCataloguedWithItsSource()

	/**
	 * filings-premium-differentiation REQ-AWF-101: a signed BBL contract is
	 * expected at the low Awf rate; a fixed-term one at the high rate.
	 *
	 * @return void
	 */
	public function testTheAwfRuleExpectsLowForASignedBblContract(): void {
		$rule = $this->checks['EmploymentContract']['nl-awf-laag-hoog-tarief'];
		$bbl = ['type' => 'bbl', 'writtenContract' => true, 'bpvOvereenkomstOndertekend' => true];
		$this->assertTrue($rule(array_merge($bbl, ['awfTariff' => 'low'])));
		$this->assertFalse($rule(array_merge($bbl, ['awfTariff' => 'high'])));
		$this->assertTrue($rule(['type' => 'temporary', 'writtenContract' => true, 'awfTariff' => 'high']));
		$this->assertTrue($rule(['type' => 'permanent', 'writtenContract' => true, 'awfTariff' => 'low']));

	}//end testTheAwfRuleExpectsLowForASignedBblContract()

	/**
	 * REQ-AWF-102: the signal flags a low payslip whose year-to-date paid
	 * hours run more than 30% above the contract at 30 contracted hours a
	 * week or less; a payslip without the figures, a high one, an exception
	 * and a full-timer pass.
	 *
	 * @return void
	 */
	public function testTheExtraHoursSignalFlagsARunningOverrun(): void {
		$rule = $this->checks['Payslip']['nl-awf-herziening-uren-signaal'];
		$slip = ['awfTariff' => 'low', 'awfTariffBasis' => 'contract', 'awfPaidHoursYearToDate' => 883.98, 'awfContractHoursYearToDate' => 624.0, 'awfAverageContractHoursPerWeek' => 25, 'awfOverrunPercentYearToDate' => 41];
		$this->assertFalse($rule($slip));
		$this->assertTrue($rule(array_merge($slip, ['awfOverrunPercentYearToDate' => 30])));
		$this->assertTrue($rule(array_merge($slip, ['awfAverageContractHoursPerWeek' => 31])));
		$this->assertTrue($rule(array_merge($slip, ['awfTariffBasis' => 'bbl'])));
		$this->assertTrue($rule(array_merge($slip, ['awfTariff' => 'high'])));
		$this->assertTrue($rule(['awfTariff' => 'low']));

	}//end testTheExtraHoursSignalFlagsARunningOverrun()

}//end class
