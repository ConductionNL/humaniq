<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The personnel budget matches the recorded golden: per place the monthly
 * budgeted, occupied and vacant FTE, the occupied and vacant cost, the
 * unpriced flag and the surcharges, and the roll-ups per unit, function and
 * cost centre, over raises, two years, scenario mutations of this and
 * another scenario, approved and draft salary changes, contracts that start
 * and end within the year, an over-occupied place and an unpriced function.
 *
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\AbsenceProgression;
use OCA\Humaniq\Service\CaoScaleLookup;
use OCA\Humaniq\Service\PersonnelBudgetService;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for {@see PersonnelBudgetService::budget()}.
 *
 * @covers \OCA\Humaniq\Service\PersonnelBudgetService
 * @uses   \OCA\Humaniq\Service\PersonnelBudgetTotals
 * @uses   \OCA\Humaniq\Service\AbsenceProgression
 */
class PersonnelBudgetGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/personnel-budget.json';

	/**
	 * Every case produces the recorded budget.
	 *
	 * @return void
	 */
	public function testBudgetsMatchTheGolden(): void {
		$scales = $this->createMock(CaoScaleLookup::class);
		$scales->method('minimumCents')->willReturnCallback(static fn (string $cao, string $schaal): ?int => ['8' => 300000, '10' => 412345][$schaal] ?? null);
		$service = new PersonnelBudgetService(progression: new AbsenceProgression(), scales: $scales);

		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $service->budget(scenario: $case['scenario'], rows: $case['rows'], year: $case['year']);
		}

		if (getenv('HUMANIQ_GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($golden as $name => $expected) {
			self::assertSame(json_encode($expected, JSON_PRESERVE_ZERO_FRACTION), json_encode($actual[$name], JSON_PRESERVE_ZERO_FRACTION), $name);
		}
	}//end testBudgetsMatchTheGolden()

	/**
	 * The cases.
	 *
	 * @return array<string, array{scenario: array<string, mixed>, rows: array<string, mixed>, year: int}>
	 */
	private function cases(): array {
		$rows = [
			'places' => [
				['id' => 'place-1', 'orgUnitId' => 'unit-1', 'normfunctieId' => 'nf-8', 'title' => 'Medewerker burgerzaken', 'budgetedFte' => 5.0, 'validFrom' => '2026-01-01', 'status' => 'actief'],
				['id' => 'place-2', 'orgUnitId' => 'unit-2', 'normfunctieId' => 'nf-10', 'title' => 'Adviseur', 'budgetedFte' => 1.5, 'validFrom' => '2027-05-01', 'status' => 'actief'],
				['id' => 'place-3', 'orgUnitId' => 'unit-1', 'normfunctieId' => 'nf-x', 'title' => 'Onbekende functie', 'budgetedFte' => 2.0, 'validFrom' => '2026-01-01', 'status' => 'actief'],
				['id' => 'place-4', 'orgUnitId' => 'unit-ghost', 'title' => 'Zonder functie', 'budgetedFte' => 1.0, 'validFrom' => '2026-01-01', 'validUntil' => '2027-08-31', 'status' => 'actief'],
				['id' => 'place-5', 'orgUnitId' => 'unit-2', 'normfunctieId' => 'nf-8', 'title' => 'Vol', 'budgetedFte' => 0.5, 'validFrom' => '2026-01-01', 'status' => 'actief'],
			],
			'contracts' => [
				['id' => 'c-1', 'employeeId' => 'emp-1', 'formatieplaatsId' => 'place-1', 'hoursPerWeek' => 40, 'startDate' => '2024-01-01'],
				['id' => 'c-2', 'employeeId' => 'emp-2', 'formatieplaatsId' => 'place-1', 'hoursPerWeek' => 20, 'startDate' => '2025-01-01', 'endDate' => '2027-06-30'],
				['id' => 'c-3', 'employeeId' => 'emp-3', 'formatieplaatsId' => 'place-2', 'hoursPerWeek' => 32, 'startDate' => '2027-09-01'],
				['id' => 'c-4', 'employeeId' => 'emp-4', 'formatieplaatsId' => 'place-5', 'hoursPerWeek' => 36, 'startDate' => '2020-01-01'],
				['id' => 'c-5', 'employeeId' => 'emp-4', 'formatieplaatsId' => 'place-3', 'hoursPerWeek' => 4, 'startDate' => '2020-01-01'],
				['id' => 'c-6', 'employeeId' => 'emp-5', 'formatieplaatsId' => 'place-4', 'hoursPerWeek' => 18, 'startDate' => '2027-02-15'],
			],
			'employees' => [
				['id' => 'emp-1', 'grossMonthlySalary' => 3000],
				['id' => 'emp-2', 'grossMonthlySalary' => '2000.50'],
				['id' => 'emp-3', 'grossMonthlySalary' => 4123.45],
				['id' => 'emp-4', 'grossMonthlySalary' => 5000],
				['id' => 'emp-5'],
			],
			'normfuncties' => [['id' => 'nf-8', 'caoSchaal' => '8'], ['id' => 'nf-10', 'caoSchaal' => '10'], ['id' => 'nf-x', 'caoSchaal' => '99']],
			'orgUnits' => [['id' => 'unit-1', 'name' => 'Team Burgerzaken', 'costCenter' => '4100'], ['id' => 'unit-2', 'name' => 'Advies', 'costCenter' => '4200']],
			'compAdjustments' => [
				['id' => 'ca-1', 'employeeId' => 'emp-1', 'status' => 'approved', 'effectiveDate' => '2027-04-01', 'proposedSalary' => 320000],
				['id' => 'ca-2', 'employeeId' => 'emp-1', 'status' => 'draft', 'effectiveDate' => '2027-02-01', 'proposedSalary' => 900000],
				['id' => 'ca-3', 'employeeId' => 'emp-4', 'status' => 'approved', 'effectiveDate' => '2028-01-15', 'proposedSalary' => 510000],
			],
			'mutations' => [
				['id' => 'm-1', 'scenarioId' => 'sc-growth', 'formatieplaatsId' => 'place-1', 'fteDelta' => 2.0, 'effectiveDate' => '2027-03-01'],
				['id' => 'm-2', 'scenarioId' => 'sc-base', 'formatieplaatsId' => null, 'orgUnitId' => 'unit-1', 'normfunctieId' => 'nf-8', 'title' => 'Nieuwe plaats', 'fteDelta' => 1.0, 'effectiveDate' => '2027-07-01'],
				['id' => 'm-3', 'scenarioId' => 'sc-base', 'formatieplaatsId' => 'place-1', 'fteDelta' => -1.5, 'effectiveDate' => '2027-10-01'],
				['id' => 'm-4', 'scenarioId' => 'sc-growth', 'formatieplaatsId' => 'place-2', 'fteDelta' => 0.5, 'effectiveDate' => '2028-02-01'],
			],
			'runs' => [
				['status' => 'posted', 'period' => '2026-05', 'totalGross' => 6000, 'totalEmployerCharges' => 1200],
				['status' => 'approved', 'period' => '2026-06', 'totalGross' => 4000, 'totalEmployerCharges' => 800],
				['status' => 'draft', 'period' => '2026-07', 'totalGross' => 9000, 'totalEmployerCharges' => 9000],
			],
		];
		$base = ['id' => 'sc-base', 'name' => 'Begroting basis', 'year' => 2027, 'caoRaisePercentage' => 3, 'status' => 'concept'];

		$cases = [];
		foreach ([2027, 2028] as $year) {
			foreach ([0, 3, 2.5] as $raise) {
				foreach (['sc-base', 'sc-growth', ''] as $scenarioId) {
					$cases[$year . ' raise ' . $raise . ' ' . ($scenarioId === '' ? 'no id' : $scenarioId)] = ['scenario' => array_merge($base, ['id' => $scenarioId, 'caoRaisePercentage' => $raise]), 'rows' => $rows, 'year' => $year];
				}
			}
		}

		$cases['no payroll history'] = ['scenario' => $base + ['employerChargesPercentage' => 22.5], 'rows' => array_merge($rows, ['runs' => []]), 'year' => 2027];
		$cases['empty world'] = ['scenario' => $base, 'rows' => [], 'year' => 2027];

		return $cases;
	}//end cases()
}//end class
