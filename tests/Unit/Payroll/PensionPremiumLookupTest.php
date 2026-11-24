<?php

/**
 * PensionPremiumLookupTest
 *
 * The participation and scheme behind a payslip's pension premium
 * (payroll-engine-port-from-shillinq 2.2).
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll
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
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\Pension\PensionPremiumLookup;
use PHPUnit\Framework\TestCase;

/**
 * Participation in force, the fund's scheme, and the period's base.
 *
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
 */
class PensionPremiumLookupTest extends TestCase {

	/**
	 * REQ-PPP-001: a full-time participant earning 4,000.00 in a scheme with
	 * a yearly franchise of 18,000 has a base of 2,500.00, and the scheme's
	 * percentages travel with it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
	 */
	public function testAFullTimeParticipantHasTheFranchiseOffTheirWage(): void {
		$premium = $this->premium(objects: $this->register(), slip: ['grossCents' => 400000]);

		$this->assertNotNull($premium);
		$this->assertSame(250000, $premium->pensionBaseCents);
		$this->assertSame(7.5, $premium->employeePercentage);
		$this->assertSame(15.0, $premium->employerPercentage);
	}//end testAFullTimeParticipantHasTheFranchiseOffTheirWage()

	/**
	 * REQ-PPP-001: a salary below the franchise has a zero base.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
	 */
	public function testASalaryBelowTheFranchiseHasAZeroBase(): void {
		$premium = $this->premium(objects: $this->register(), slip: ['grossCents' => 120000]);

		$this->assertNotNull($premium);
		$this->assertSame(0, $premium->pensionBaseCents);
	}//end testASalaryBelowTheFranchiseHasAZeroBase()

	/**
	 * REQ-PPP-001: the participation factor scales the wage and the
	 * part-time factor the franchise, as PensionBase computes them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
	 */
	public function testTheFactorsScaleTheWageAndTheFranchise(): void {
		$objects = $this->register();
		$objects['PensionParticipation'][0]['participationFactor'] = 0.5;

		// 4,000 x 0.5 - 1,500 x (18 / 36) = 1,250.00.
		$premium = $this->premium(objects: $objects, slip: ['grossCents' => 400000, 'contract' => ['hoursPerWeek' => 18.0]]);

		$this->assertSame(125000, $premium?->pensionBaseCents);
	}//end testTheFactorsScaleTheWageAndTheFranchise()

	/**
	 * REQ-PPP-002 "HR enrols a new employee": a participation from 1 May
	 * counts in the May run and not in the April run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
	 */
	public function testAParticipationCountsFromTheMonthItStarts(): void {
		$objects = $this->register();
		$objects['PensionParticipation'][0]['startDate'] = '2026-05-01';

		$this->assertNull($this->premium(objects: $objects, slip: ['lastDay' => '2026-04-30']));
		$this->assertNotNull($this->premium(objects: $objects, slip: ['lastDay' => '2026-05-31']));
	}//end testAParticipationCountsFromTheMonthItStarts()

	/**
	 * REQ-PPP-001 "No participation, no premium", and none either when the
	 * fund has no scheme of this administration valid on the last day.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
	 */
	public function testNoParticipationOrNoSchemeMeansNoPremium(): void {
		$objects = $this->register();
		$this->assertNull($this->premium(objects: ['PensionParticipation' => [], 'PensionScheme' => $objects['PensionScheme']], slip: []));

		$objects['PensionScheme'][0]['validUntil'] = '2026-03-31';
		$this->assertNull($this->premium(objects: $objects, slip: []));

		$objects = $this->register();
		$objects['PensionScheme'][0]['administrationId'] = 'ADM-002';
		$this->assertNull($this->premium(objects: $objects, slip: []));
	}//end testNoParticipationOrNoSchemeMeansNoPremium()

	/**
	 * REQ-PPP-002: an ended participation does not count after its end, and
	 * of two schemes of the fund the one valid on the last day applies.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
	 */
	public function testTheRowsInForceOnTheLastDayApply(): void {
		$objects = $this->register();
		$objects['PensionParticipation'][0]['endDate'] = '2026-04-15';
		$this->assertNull($this->premium(objects: $objects, slip: []));

		$objects = $this->register();
		$objects['PensionScheme'][] = array_merge($objects['PensionScheme'][0], ['id' => 'ps-2', 'validFrom' => '2026-04-01', 'employeePercentage' => 8.0]);
		$this->assertSame(8.0, $this->premium(objects: $objects, slip: [])?->employeePercentage);
	}//end testTheRowsInForceOnTheLastDayApply()

	/**
	 * A four-weekly wage spreads the yearly franchise over 13 periods.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
	 */
	public function testAFourWeeklyPeriodSpreadsTheFranchiseOverThirteen(): void {
		// 4,000.00 - 18,000 / 13 = 2,615.38.
		$premium = $this->premium(objects: $this->register(), slip: ['grossCents' => 400000, 'periodsPerYear' => 13]);

		$this->assertSame(261538, $premium?->pensionBaseCents);
	}//end testAFourWeeklyPeriodSpreadsTheFranchiseOverThirteen()

	/**
	 * The premium of one slip, over a register of the given objects.
	 *
	 * @param array<string, list<array<string, mixed>>> $objects The objects per schema.
	 * @param array<string, mixed>                      $slip    Slip fields over the April default.
	 *
	 * @return \OCA\Humaniq\Payroll\PensionPremium|null
	 */
	private function premium(array $objects, array $slip): ?\OCA\Humaniq\Payroll\PensionPremium {
		$lookup = new PensionPremiumLookup(fullTimeHoursWeek: 36.0);

		return $lookup->premiumFor(
			inputs: $lookup->inputs(rows: ['participations' => ($objects['PensionParticipation'] ?? []), 'schemes' => ($objects['PensionScheme'] ?? [])], administrationId: 'ADM-001'),
			slip: array_merge(['employeeId' => 'emp-1', 'contract' => ['hoursPerWeek' => 36.0], 'grossCents' => 400000, 'lastDay' => '2026-04-30', 'periodsPerYear' => 12], $slip)
		);
	}//end premium()

	/**
	 * One participant in fund pfab and the administration's pfab scheme.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function register(): array {
		return [
			'PensionParticipation' => [
				['id' => 'pp-1', 'employeeId' => 'emp-1', 'fund' => 'pfab', 'startDate' => '2026-01-01', 'endDate' => null, 'participationFactor' => 1],
			],
			'PensionScheme' => [
				['id' => 'ps-1', 'administrationId' => 'ADM-001', 'fund' => 'pfab', 'schemeCode' => 'PFAB-BASIS', 'franchise' => 18000, 'maxPensionableSalary' => 137800, 'employerPercentage' => 15, 'employeePercentage' => 7.5, 'validFrom' => '2026-01-01', 'validUntil' => null],
			],
		];
	}//end register()
}//end class
