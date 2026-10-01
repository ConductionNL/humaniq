<?php

/**
 * Unit tests for AwfReviewService.
 *
 * The review of the low Awf premium (filings-premium-differentiation D2/D3),
 * over the real gateway, the real calculator and the real 2026 tables, with
 * the thresholds of the Handboek Loonheffingen 2026 (maart 2026) 7.2.2 and
 * 7.2.3: an employment that ends at most two months after it began, and a
 * year in which an employee on an average of 30 contracted hours a week or
 * less is paid more than 30 percent above the contracted hours. Every delta
 * asserted here is computed by hand: the high rate (7,74%) minus the low
 * rate (2,74%) is 5% of the period's wage.
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
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-102
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Service\AwfReviewService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Design D2 (early end) and D3 (extra hours), over the real gateway.
 *
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-102
 */
class AwfReviewServiceTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The subject.
	 *
	 * @var AwfReviewService
	 */
	private AwfReviewService $service;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
		$this->service = new AwfReviewService(gateway: $gateway, calculator: new PayrollCalculator(), logger: new NullLogger());
	}//end setUp()

	/**
	 * Seed one employee, one contract, sealed runs and low payslips.
	 *
	 * @param string               $employeeId The employee id.
	 * @param array<string, mixed> $contract   The contract fields.
	 * @param array<string, float> $grossByPeriod Gross wage per period.
	 * @param float|null           $hoursWorked   Hours paid per period, or null.
	 * @param string               $basis         The stamped tariff basis.
	 *
	 * @return void
	 */
	private function seedEmployee(string $employeeId, array $contract, array $grossByPeriod, ?float $hoursWorked, string $basis='contract'): void {
		$this->store->seed('Employee', $employeeId, ['employeeNumber' => strtoupper($employeeId), 'firstName' => 'Test', 'lastName' => $employeeId, 'dateOfBirth' => '1990-04-12', 'startDate' => (string)$contract['startDate']]);
		$this->store->seed('EmploymentContract', 'ct-' . $employeeId, array_merge(['employeeId' => $employeeId, 'type' => 'permanent', 'writtenContract' => true, 'awfTariff' => 'low'], $contract));
		foreach ($grossByPeriod as $period => $gross) {
			$runId = 'run-' . $period;
			$this->store->seed('PayrollRun', $runId, ['period' => $period, 'administrationId' => 'ADM-001', 'status' => 'approved']);
			$input = $this->input($gross, $period);
			$slip = [
				'employeeId' => $employeeId,
				'payrollRunId' => $runId,
				'period' => $period,
				'grossPay' => $gross,
				'werknemersverzekeringen' => $this->charges($input),
				'awfTariff' => 'low',
				'awfTariffBasis' => $basis,
				'engineInputSnapshot' => $input->toArray(),
			];
			if ($hoursWorked !== null) {
				$slip['hoursWorked'] = $hoursWorked;
			}

			$this->store->seed('Payslip', 'ps-' . $employeeId . '-' . $period, $slip);
		}
	}//end seedEmployee()

	/**
	 * The engine input a low payslip was calculated from.
	 *
	 * @param float  $gross  The gross wage.
	 * @param string $period The period.
	 *
	 * @return CalculationInput
	 */
	private function input(float $gross, string $period): CalculationInput {
		return new CalculationInput(
			grossMonthlySalaryCents: (int)round($gross * 100),
			taxTableColor: 'wit',
			loonheffingskortingToegepast: true,
			dateOfBirth: '1990-04-12',
			period: $period,
			awfTariff: 'low',
			aofTariff: 'laag',
			whkPercentage: 0.52
		);
	}//end input()

	/**
	 * The employer insurance charges the engine stored on a low payslip.
	 *
	 * @param CalculationInput $input The engine input.
	 *
	 * @return float
	 */
	private function charges(CalculationInput $input): float {
		$result = (new PayrollCalculator())->calculate($input, TaxTables::load('nl-2026'));
		return round($result->werknemersverzekeringenCents / 100, 2);
	}//end charges()

	/**
	 * The rows of one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rowsOf(string $schema): array {
		$this->store->setSchema($schema);
		return $this->store->findAll();
	}//end rowsOf()

	/**
	 * Handboek 7.2.2: a permanent written contract from 1 January that ends
	 * on 11 February (six weeks) is reviewed at the high rate over January
	 * (EUR 3.000, delta 5% = 150,00) and February (EUR 1.500, delta 75,00),
	 * settled as employer charges in the March run; net pay is untouched.
	 *
	 * @return void
	 */
	public function testAContractEndedAfterSixWeeksIsReviewedAtTheHighRate(): void {
		$this->seedEmployee('emp-1', ['startDate' => '2026-01-01', 'endDate' => '2026-02-11', 'hoursPerWeek' => 36.0], ['2026-01' => 3000.0, '2026-02' => 1500.0], null);

		$outcome = $this->service->review(period: '2026-03', runId: 'run-2026-03');

		self::assertSame(2, $outcome['earlyEnd']);
		$adjustments = $this->rowsOf('PayrollAdjustment');
		self::assertCount(2, $adjustments);
		$byPeriod = array_column($adjustments, null, 'originalPeriod');
		self::assertSame(150.0, $byPeriod['2026-01']['deltaWerknemersverzekeringen']);
		self::assertSame(75.0, $byPeriod['2026-02']['deltaWerknemersverzekeringen']);
		foreach ($adjustments as $adjustment) {
			self::assertSame('awf-herziening', $adjustment['correctionType']);
			self::assertSame([0.0, 0.0, 0.0, 0.0, 0.0], [$adjustment['deltaNet'], $adjustment['deltaGross'], $adjustment['deltaLoonheffing'], $adjustment['deltaZvw'], $adjustment['deltaVolksverzekeringen']]);
			self::assertSame(['applied', '2026-03', 'run-2026-03'], [$adjustment['status'], $adjustment['settlementPeriod'], $adjustment['settlementPayrollRunId']]);
			unset($adjustment['id']);
			foreach (['employeeId', 'originalPayrollRunId', 'originalPayslipId', 'settlementPayrollRunId'] as $ref) {
				$adjustment[$ref] = '5b1d3f4e-0000-4000-8000-000000000001';
			}

			self::assertSame([], RegisterSchemaValidator::errors('PayrollAdjustment', $adjustment));
		}

		// A second run in the same or a later period settles nothing twice.
		$again = $this->service->review(period: '2026-04', runId: 'run-2026-04');
		self::assertSame(0, $again['earlyEnd']);
		self::assertCount(2, $this->rowsOf('PayrollAdjustment'));
	}//end testAContractEndedAfterSixWeeksIsReviewedAtTheHighRate()

	/**
	 * A contract that runs past the two months, or has no end, is not
	 * reviewed; nor is a payslip of a draft run.
	 *
	 * @return void
	 */
	public function testALongerOrOpenContractIsNotReviewed(): void {
		$this->seedEmployee('emp-1', ['startDate' => '2026-01-01', 'endDate' => '2026-03-01', 'hoursPerWeek' => 36.0], ['2026-01' => 3000.0], null);
		$this->seedEmployee('emp-2', ['startDate' => '2026-01-01', 'endDate' => null, 'hoursPerWeek' => 36.0], ['2026-01' => 3000.0], null);

		self::assertSame(0, $this->service->review(period: '2026-03', runId: 'run-2026-03')['earlyEnd']);
		self::assertSame([], $this->rowsOf('PayrollAdjustment'));
	}//end testALongerOrOpenContractIsNotReviewed()

	/**
	 * Handboek 7.2.3: 24 contracted hours a week (104,00 a month, 13/3),
	 * paid 34 a week (147,33 a month) over 2026: 41% above, so the first
	 * run of 2027 reviews all twelve months at the high rate (EUR 2.000 a
	 * month, delta 100,00 each).
	 *
	 * @return void
	 */
	public function testAPartTimerPaidFarAboveTheContractIsReviewedAfterTheYear(): void {
		$this->seedEmployee('emp-1', ['startDate' => '2025-06-01', 'endDate' => null, 'hoursPerWeek' => 24.0], $this->year(2000.0), 147.33);

		self::assertSame(0, $this->service->review(period: '2026-12', runId: 'run-x')['extraHours']);
		$outcome = $this->service->review(period: '2027-01', runId: 'run-2027-01');

		self::assertSame(12, $outcome['extraHours']);
		$adjustments = $this->rowsOf('PayrollAdjustment');
		self::assertCount(12, $adjustments);
		self::assertSame(array_fill(0, 12, 100.0), array_column($adjustments, 'deltaWerknemersverzekeringen'));
		self::assertSame(['2027-01'], array_values(array_unique(array_column($adjustments, 'settlementPeriod'))));
	}//end testAPartTimerPaidFarAboveTheContractIsReviewedAfterTheYear()

	/**
	 * 24 contracted, paid 30 a week: 25% above, no review; paid 31,2 a week
	 * (135,20 a month) is exactly 30%, still no review.
	 *
	 * @return void
	 */
	public function testThirtyPercentOrLessIsNotReviewed(): void {
		$this->seedEmployee('emp-1', ['startDate' => '2025-06-01', 'endDate' => null, 'hoursPerWeek' => 24.0], $this->year(2000.0), 130.0);
		$this->seedEmployee('emp-2', ['startDate' => '2025-06-01', 'endDate' => null, 'hoursPerWeek' => 24.0], $this->year(2000.0), 135.2);

		self::assertSame(0, $this->service->review(period: '2027-01', runId: 'run-2027-01')['extraHours']);
		self::assertSame([], $this->rowsOf('PayrollAdjustment'));
	}//end testThirtyPercentOrLessIsNotReviewed()

	/**
	 * More than 30 contracted hours a week on average: never reviewed
	 * (from 2025, Handboek 7.2.3 let op 1). The BBL and young part-timer
	 * exceptions are never reviewed either (let op 3).
	 *
	 * @return void
	 */
	public function testAboveThirtyContractedHoursAndTheExceptionsAreNotReviewed(): void {
		$this->seedEmployee('emp-1', ['startDate' => '2025-06-01', 'endDate' => null, 'hoursPerWeek' => 32.0], $this->year(2000.0), 195.0);
		$this->seedEmployee('emp-2', ['startDate' => '2025-06-01', 'endDate' => null, 'hoursPerWeek' => 24.0, 'type' => 'bbl', 'bpvOvereenkomstOndertekend' => true], $this->year(2000.0), 147.33, 'bbl');

		self::assertSame(0, $this->service->review(period: '2027-01', runId: 'run-2027-01')['extraHours']);
		self::assertSame([], $this->rowsOf('PayrollAdjustment'));
	}//end testAboveThirtyContractedHoursAndTheExceptionsAreNotReviewed()

	/**
	 * The figures the signal rule reads, during the year: five sealed
	 * months at 147,33 paid hours plus the current one, against 6 x 104,00
	 * contracted. January to June is 181 days, 25,86 weeks: 624 / 25,86 is
	 * 24,13 contracted hours a week, rounded up to 25 (Handboek 7.2.3 stap 5);
	 * (883,98 - 624) / 624 is 41,66%, rounded down to 41.
	 *
	 * @return void
	 */
	public function testYearToDateFiguresForTheSignal(): void {
		$grossByPeriod = array_slice($this->year(2000.0), 0, 5, true);
		$this->seedEmployee('emp-1', ['startDate' => '2025-06-01', 'endDate' => null, 'hoursPerWeek' => 24.0], $grossByPeriod, 147.33);
		$this->store->setSchema('EmploymentContract');
		$contracts = $this->store->findAll();

		$ytd = $this->service->yearToDate(employeeId: 'emp-1', contracts: $contracts, period: '2026-06', paidHours: 147.33);

		self::assertSame(['paid' => 883.98, 'contract' => 624.0, 'averageHoursPerWeek' => 25, 'overrunPercent' => 41], $ytd);
		self::assertTrue(AwfReviewService::signals($ytd));
		self::assertFalse(AwfReviewService::signals(['paid' => 800.0, 'contract' => 624.0, 'averageHoursPerWeek' => 24, 'overrunPercent' => 28]));
		self::assertFalse(AwfReviewService::signals(['paid' => 1300.0, 'contract' => 624.0, 'averageHoursPerWeek' => 32, 'overrunPercent' => 108]));
	}//end testYearToDateFiguresForTheSignal()

	/**
	 * A payslip without an engine snapshot cannot be recomputed: it is
	 * named in the outcome, not settled.
	 *
	 * @return void
	 */
	public function testAPayslipWithoutASnapshotIsReportedNotSettled(): void {
		$this->seedEmployee('emp-1', ['startDate' => '2026-01-01', 'endDate' => '2026-02-11', 'hoursPerWeek' => 36.0], ['2026-01' => 3000.0], null);
		$this->store->seed('Payslip', 'ps-emp-1-2026-01', ['employeeId' => 'emp-1', 'payrollRunId' => 'run-2026-01', 'period' => '2026-01', 'werknemersverzekeringen' => 300.0, 'awfTariff' => 'low', 'awfTariffBasis' => 'contract', 'engineInputSnapshot' => null]);

		$outcome = $this->service->review(period: '2026-03', runId: 'run-2026-03');

		self::assertSame(0, $outcome['earlyEnd']);
		self::assertSame([['payslipId' => 'ps-emp-1-2026-01', 'reason' => 'no-engine-snapshot']], $outcome['skipped']);
		self::assertSame([], $this->rowsOf('PayrollAdjustment'));
	}//end testAPayslipWithoutASnapshotIsReportedNotSettled()

	/**
	 * Twelve months of 2026 at one gross wage.
	 *
	 * @param float $gross The monthly gross wage.
	 *
	 * @return array<string, float>
	 */
	private function year(float $gross): array {
		$out = [];
		for ($m = 1; $m <= 12; $m++) {
			$out[sprintf('2026-%02d', $m)] = $gross;
		}

		return $out;
	}//end year()

}//end class
