<?php

/**
 * Unit tests for the personnel budget (reporting-personnel-budget-and-scenarios D2, D3).
 *
 * Every expected figure is computed by hand in the test's comments. Rows are
 * shaped like the register's Formatieplaats, EmploymentContract, Employee,
 * Normfunctie, OrgUnit, CompAdjustment, ScenarioMutation and PayrollRun, and the
 * scenario payloads are validated against their schema fragments.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\AbsenceProgression;
use OCA\Humaniq\Service\CaoScaleLookup;
use OCA\Humaniq\Service\PersonnelBudgetService;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the personnel budget.
 *
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
 */
class PersonnelBudgetServiceTest extends TestCase {

	/**
	 * The scenario payloads are valid register objects.
	 *
	 * @return void
	 */
	public function testTheScenarioPayloadsAreValid(): void {
		self::assertSame([], RegisterSchemaValidator::errors('FormationScenario', ['name' => 'Begroting 2027', 'year' => 2027, 'caoRaisePercentage' => 3, 'status' => 'concept']));
		self::assertSame([], RegisterSchemaValidator::errors('ScenarioMutation', ['scenarioId' => '5b0c8f0e-2d1b-4c55-9d4e-1a2b3c4d5e6f', 'formatieplaatsId' => '5b0c8f0e-2d1b-4c55-9d4e-1a2b3c4d5e6a', 'fteDelta' => 2.0, 'effectiveDate' => '2027-03-01']));
	}//end testTheScenarioPayloadsAreValid()

	/**
	 * One place of 5.0 FTE in schaal 8 (minimum 3000.00): a full-timer at 3000,
	 * a half-timer at 2000 until 30 June, a 3% CAO raise, 8% holiday allowance
	 * and employer charges at 20% from the payroll history.
	 *
	 * Occupied: 3000 x 1.03 x 12 = 37080; 2000 x 1.03 x 6 = 12360; 49440.
	 * Vacant: 3.5 FTE x 3090 x 6 = 64890; 4.0 FTE x 3090 x 6 = 74160; 139050.
	 * Base 188490; holiday 8% = 15079.20; charges 20% of 203569.20 = 40713.84; total 244283.04.
	 *
	 * @return void
	 */
	public function testAHandComputedBudget(): void {
		$budget = $this->service()->budget(scenario: $this->scenario(), rows: $this->rows(), year: 2027);
		$line = $budget['lines'][0];

		self::assertEqualsWithDelta(49440.0, $line['occupiedCost'], 0.01);
		self::assertEqualsWithDelta(139050.0, $line['vacantCost'], 0.01);
		self::assertEqualsWithDelta(15079.2, $line['holidayAllowance'], 0.01);
		self::assertEqualsWithDelta(40713.84, $line['employerCharges'], 0.01);
		self::assertEqualsWithDelta(244283.04, $line['total'], 0.01);
		self::assertFalse($line['unpriced']);
		self::assertSame(3000.0, $line['scaleMinimum']);
		self::assertSame('payroll-history', $budget['basis']['employerChargesBasis']);
		self::assertEqualsWithDelta(20.0, $budget['basis']['employerChargesPercentage'], 0.001);
		self::assertSame(8.0, $budget['basis']['holidayAllowancePercentage']);
		self::assertEqualsWithDelta(244283.04, $budget['byCostCenter']['4100']['total'], 0.01);
		self::assertEqualsWithDelta(244283.04, $budget['byUnit']['unit-1']['total'], 0.01);
		self::assertEqualsWithDelta(244283.04, $budget['byFunction']['nf-8']['total'], 0.01);
		self::assertEqualsWithDelta(1.5, $line['fteByMonth'][0]['occupied'], 0.001);
		self::assertEqualsWithDelta(1.0, $line['fteByMonth'][11]['occupied'], 0.001);
	}//end testAHandComputedBudget()

	/**
	 * An approved raise counts from its effective date: 3200 from April for the full-timer.
	 *
	 * Occupied full-timer: 3090 x 3 + 3296 x 9 = 9270 + 29664 = 38934.
	 *
	 * @return void
	 */
	public function testAnApprovedRaiseCountsFromItsDate(): void {
		$rows = $this->rows();
		$rows['compAdjustments'] = [
			['id' => 'ca-1', 'employeeId' => 'emp-1', 'status' => 'approved', 'effectiveDate' => '2027-04-01', 'proposedSalary' => 320000],
			['id' => 'ca-2', 'employeeId' => 'emp-1', 'status' => 'draft', 'effectiveDate' => '2027-02-01', 'proposedSalary' => 900000],
		];
		$budget = $this->service()->budget(scenario: $this->scenario(), rows: $rows, year: 2027);

		self::assertEqualsWithDelta(38934.0 + 12360.0, $budget['lines'][0]['occupiedCost'], 0.01);
	}//end testAnApprovedRaiseCountsFromItsDate()

	/**
	 * A place without a reference job has its vacant FTE flagged, not priced.
	 *
	 * @return void
	 */
	public function testAnUnpricedPlaceIsFlagged(): void {
		$rows = $this->rows();
		unset($rows['places'][0]['normfunctieId']);
		$line = $this->service()->budget(scenario: $this->scenario(), rows: $rows, year: 2027)['lines'][0];

		self::assertTrue($line['unpriced']);
		self::assertSame(0.0, $line['vacantCost']);
		self::assertEqualsWithDelta(49440.0, $line['occupiedCost'], 0.01);
	}//end testAnUnpricedPlaceIsFlagged()

	/**
	 * Without payroll history the scenario's own percentage is used and named.
	 *
	 * @return void
	 */
	public function testChargesFallBackToTheScenario(): void {
		$rows = $this->rows();
		$rows['runs'] = [];
		$scenario = array_merge($this->scenario(), ['employerChargesPercentage' => 25]);
		$budget = $this->service()->budget(scenario: $scenario, rows: $rows, year: 2027);

		self::assertSame('scenario', $budget['basis']['employerChargesBasis']);
		self::assertEqualsWithDelta(0.25 * (188490.0 + 15079.2), $budget['lines'][0]['employerCharges'], 0.01);
	}//end testChargesFallBackToTheScenario()

	/**
	 * Growing the place by 2.0 FTE from March: 2.0 FTE more in months 3 to 12,
	 * and ten months of 2.0 x 3090 = 61800 more base cost, while the baseline is unchanged.
	 *
	 * @return void
	 */
	public function testTheGrowthScenarioDiffersFromTheBaselineFromMarch(): void {
		$rows = $this->rows();
		$growth = array_merge($this->scenario(), ['id' => 'sc-growth']);
		$rows['mutations'] = [['id' => 'm-1', 'scenarioId' => 'sc-growth', 'formatieplaatsId' => 'place-1', 'fteDelta' => 2.0, 'effectiveDate' => '2027-03-01']];

		$compare = $this->service()->compare(a: $this->scenario(), b: $growth, rows: $rows, year: 2027);
		$unit = $compare['units']['unit-1'];

		self::assertEqualsWithDelta(0.0, $unit['b']['fteByMonth'][1] - $unit['baseline']['fteByMonth'][1], 0.001);
		self::assertEqualsWithDelta(2.0, $unit['b']['fteByMonth'][2] - $unit['baseline']['fteByMonth'][2], 0.001);
		self::assertEqualsWithDelta(61800.0 * 1.08 * 1.2, $unit['diffB']['cost'], 0.01);
		self::assertEqualsWithDelta(0.0, $unit['diffA']['cost'], 0.01);
		self::assertSame(5.0, $rows['places'][0]['budgetedFte']);
	}//end testTheGrowthScenarioDiffersFromTheBaselineFromMarch()

	/**
	 * A new place from a mutation is budgeted in its unit from its date.
	 *
	 * @return void
	 */
	public function testANewPlaceFromAMutation(): void {
		$rows = $this->rows();
		$rows['mutations'] = [['id' => 'm-2', 'scenarioId' => 'sc-base', 'formatieplaatsId' => null, 'orgUnitId' => 'unit-1', 'normfunctieId' => 'nf-8', 'title' => 'Medewerker burgerzaken', 'fteDelta' => 1.0, 'effectiveDate' => '2027-07-01']];
		$budget = $this->service()->budget(scenario: $this->scenario(), rows: $rows, year: 2027);

		self::assertCount(2, $budget['lines']);
		self::assertSame('Medewerker burgerzaken', $budget['lines'][1]['title']);
		self::assertEqualsWithDelta(6 * 3090.0, $budget['lines'][1]['vacantCost'], 0.01);
	}//end testANewPlaceFromAMutation()

	/**
	 * The base scenario, 2027, 3% raise.
	 *
	 * @return array<string, mixed>
	 */
	private function scenario(): array {
		return ['id' => 'sc-base', 'name' => 'Begroting 2027 basis', 'year' => 2027, 'caoRaisePercentage' => 3, 'status' => 'concept', 'administrationId' => 'ADM-001'];
	}//end scenario()

	/**
	 * The rows of one unit with one 5.0 FTE place.
	 *
	 * @return array<string, mixed>
	 */
	private function rows(): array {
		return [
			'places' => [['id' => 'place-1', 'orgUnitId' => 'unit-1', 'normfunctieId' => 'nf-8', 'title' => 'Medewerker burgerzaken', 'budgetedFte' => 5.0, 'validFrom' => '2026-01-01', 'status' => 'actief']],
			'contracts' => [
				['id' => 'c-1', 'employeeId' => 'emp-1', 'formatieplaatsId' => 'place-1', 'hoursPerWeek' => 40, 'startDate' => '2024-01-01'],
				['id' => 'c-2', 'employeeId' => 'emp-2', 'formatieplaatsId' => 'place-1', 'hoursPerWeek' => 20, 'startDate' => '2025-01-01', 'endDate' => '2027-06-30'],
			],
			'employees' => [
				['id' => 'emp-1', 'grossMonthlySalary' => 3000],
				['id' => 'emp-2', 'grossMonthlySalary' => 2000],
			],
			'normfuncties' => [['id' => 'nf-8', 'caoSchaal' => '8']],
			'orgUnits' => [['id' => 'unit-1', 'name' => 'Team Burgerzaken', 'costCenter' => '4100']],
			'compAdjustments' => [],
			'mutations' => [],
			'runs' => [
				['status' => 'posted', 'period' => '2026-05', 'totalGross' => 6000, 'totalEmployerCharges' => 1200],
				['status' => 'approved', 'period' => '2026-06', 'totalGross' => 4000, 'totalEmployerCharges' => 800],
				['status' => 'draft', 'period' => '2026-07', 'totalGross' => 9000, 'totalEmployerCharges' => 9000],
			],
		];
	}//end rows()

	/**
	 * The service with schaal 8 priced at 3000.00 and 8% holiday allowance.
	 *
	 * @return PersonnelBudgetService
	 */
	private function service(): PersonnelBudgetService {
		$scales = $this->createMock(CaoScaleLookup::class);
		$scales->method('minimumCents')->willReturnCallback(static fn (string $cao, string $schaal): ?int => ($schaal === '8') ? 300000 : null);

		return new PersonnelBudgetService(progression: new AbsenceProgression(), scales: $scales);
	}//end service()

}//end class
