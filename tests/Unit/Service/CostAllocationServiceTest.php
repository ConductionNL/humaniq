<?php

/**
 * Wage costs split over cost centres and projects, per payslip.
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
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CostAllocationService;
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
 * The resolution order of design D2 and the cent-exact lines of D3, over the
 * real gateway and org resolver.
 */
class CostAllocationServiceTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The subject.
	 *
	 * @var CostAllocationService
	 */
	private CostAllocationService $service;

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

		$this->service = new CostAllocationService(gateway: $gateway, orgResolution: new OrgResolutionService(), logger: new NullLogger());

		$this->store->seed('OrgUnit', 'unit-consultancy', ['name' => 'Consultancy', 'type' => 'afdeling', 'costCenter' => 'CC-100']);
		$this->store->seed('OrgUnit', 'unit-finance', ['name' => 'Finance', 'type' => 'afdeling', 'costCenter' => 'CC-200']);
	}//end setUp()

	/**
	 * A fixed split of 60 and 40 percent over a gross of 2912.00 puts 1747.20
	 * on CC-100 and 1164.80 on CC-200, the charges split the same way.
	 *
	 * @return void
	 */
	public function testAFixedSplitOverTwoDepartments(): void {
		$this->store->seed('CostAllocation', 'ca-1', ['employeeId' => 'emp-devries', 'basis' => 'fixed', 'startDate' => '2026-01-01', 'endDate' => null, 'splits' => [['costCenter' => 'CC-100', 'projectId' => null, 'percentage' => 60], ['costCenter' => 'CC-200', 'projectId' => 'PRJ-7', 'percentage' => 40]]]);

		$inputs = $this->service->inputs('2026-05');
		$shares = $this->service->splitFor('emp-devries', '2026-05', $inputs);
		$lines = $this->service->linesFor($shares, 291200, 50000);

		self::assertCount(2, $lines);
		self::assertSame(['CC-100', null, 60.0, 1747.2, 300.0, 2047.2, 'fixed'], [$lines[0]['costCenter'], $lines[0]['projectId'], $lines[0]['percentage'], $lines[0]['gross'], $lines[0]['employerCharges'], $lines[0]['totalCost'], $lines[0]['allocationSource']]);
		self::assertSame(['CC-200', 'PRJ-7', 40.0, 1164.8, 200.0, 1364.8, 'fixed'], [$lines[1]['costCenter'], $lines[1]['projectId'], $lines[1]['percentage'], $lines[1]['gross'], $lines[1]['employerCharges'], $lines[1]['totalCost'], $lines[1]['allocationSource']]);
	}//end testAFixedSplitOverTwoDepartments()

	/**
	 * An allocation that ended before the period does not apply; the
	 * placement does.
	 *
	 * @return void
	 */
	public function testAnEndedAllocationFallsBackToThePlacement(): void {
		$this->store->seed('CostAllocation', 'ca-old', ['employeeId' => 'emp-jansen', 'basis' => 'fixed', 'startDate' => '2025-01-01', 'endDate' => '2026-03-31', 'splits' => [['costCenter' => 'CC-200', 'percentage' => 100]]]);
		$this->store->seed('OrgAssignment', 'as-1', ['employeeId' => 'emp-jansen', 'orgUnitId' => 'unit-consultancy', 'startDate' => '2024-01-01', 'endDate' => null]);

		$shares = $this->service->splitFor('emp-jansen', '2026-05', $this->service->inputs('2026-05'));

		self::assertSame([['costCenter' => 'CC-100', 'projectId' => null, 'percentage' => 100.0, 'allocationSource' => 'placement']], $shares);
	}//end testAnEndedAllocationFallsBackToThePlacement()

	/**
	 * Two placements covering the period split the cost equally, and the
	 * source says so.
	 *
	 * @return void
	 */
	public function testTwoPlacementsSplitEqually(): void {
		$this->store->seed('OrgAssignment', 'as-1', ['employeeId' => 'emp-jansen', 'orgUnitId' => 'unit-consultancy', 'startDate' => '2024-01-01', 'endDate' => '2026-05-15']);
		$this->store->seed('OrgAssignment', 'as-2', ['employeeId' => 'emp-jansen', 'orgUnitId' => 'unit-finance', 'startDate' => '2026-05-16', 'endDate' => null]);
		$this->store->seed('OrgAssignment', 'as-3', ['employeeId' => 'emp-jansen', 'orgUnitId' => 'unit-finance', 'startDate' => '2023-01-01', 'endDate' => '2023-12-31']);

		$shares = $this->service->splitFor('emp-jansen', '2026-05', $this->service->inputs('2026-05'));

		self::assertSame(['CC-100', 'CC-200'], array_column($shares, 'costCenter'));
		self::assertSame([50.0, 50.0], array_column($shares, 'percentage'));
		self::assertSame(['placement-equal-split', 'placement-equal-split'], array_column($shares, 'allocationSource'));
	}//end testTwoPlacementsSplitEqually()

	/**
	 * Nothing resolves: one unallocated share without a cost centre.
	 *
	 * @return void
	 */
	public function testNothingResolvesIsUnallocated(): void {
		$shares = $this->service->splitFor('emp-nobody', '2026-05', $this->service->inputs('2026-05'));

		self::assertSame([['costCenter' => null, 'projectId' => null, 'percentage' => 100.0, 'allocationSource' => 'unallocated']], $shares);
	}//end testNothingResolvesIsUnallocated()

	/**
	 * By hours: the approved entries of the period, grouped by cost centre
	 * and project; an entry without a cost centre takes the placement's;
	 * entries of a timesheet that is not approved do not count.
	 *
	 * @return void
	 */
	public function testByHoursUsesTheApprovedEntries(): void {
		$this->store->seed('CostAllocation', 'ca-h', ['employeeId' => 'emp-jansen', 'basis' => 'hours', 'startDate' => '2026-01-01', 'splits' => []]);
		$this->store->seed('OrgAssignment', 'as-1', ['employeeId' => 'emp-jansen', 'orgUnitId' => 'unit-consultancy', 'startDate' => '2024-01-01']);
		$this->store->seed('Timesheet', 'ts-may', ['employeeId' => 'emp-jansen', 'period' => '2026-05', 'status' => 'approved']);
		$this->store->seed('Timesheet', 'ts-draft', ['employeeId' => 'emp-jansen', 'period' => '2026-05', 'status' => 'draft']);
		$this->store->seed('TimeEntry', 'te-1', ['employeeId' => 'emp-jansen', 'timesheetId' => 'ts-may', 'date' => '2026-05-04', 'hours' => 30, 'costCenter' => 'CC-200', 'projectId' => 'PRJ-7']);
		$this->store->seed('TimeEntry', 'te-2', ['employeeId' => 'emp-jansen', 'timesheetId' => 'ts-may', 'date' => '2026-05-05', 'hours' => 10, 'costCenter' => null, 'projectId' => null]);
		$this->store->seed('TimeEntry', 'te-3', ['employeeId' => 'emp-jansen', 'timesheetId' => 'ts-draft', 'date' => '2026-05-06', 'hours' => 40, 'costCenter' => 'CC-300', 'projectId' => null]);

		$shares = $this->service->splitFor('emp-jansen', '2026-05', $this->service->inputs('2026-05'));

		self::assertSame(
			[
				['costCenter' => 'CC-200', 'projectId' => 'PRJ-7', 'percentage' => 75.0, 'allocationSource' => 'hours'],
				['costCenter' => 'CC-100', 'projectId' => null, 'percentage' => 25.0, 'allocationSource' => 'hours'],
			],
			$shares
		);
	}//end testByHoursUsesTheApprovedEntries()

	/**
	 * An hours allocation with no approved hours in the period falls back to
	 * the placement.
	 *
	 * @return void
	 */
	public function testByHoursWithoutHoursFallsBackToThePlacement(): void {
		$this->store->seed('CostAllocation', 'ca-h', ['employeeId' => 'emp-jansen', 'basis' => 'hours', 'startDate' => '2026-01-01']);
		$this->store->seed('OrgAssignment', 'as-1', ['employeeId' => 'emp-jansen', 'orgUnitId' => 'unit-consultancy', 'startDate' => '2024-01-01']);

		$shares = $this->service->splitFor('emp-jansen', '2026-05', $this->service->inputs('2026-05'));

		self::assertSame('placement', $shares[0]['allocationSource']);
		self::assertSame('CC-100', $shares[0]['costCenter']);
	}//end testByHoursWithoutHoursFallsBackToThePlacement()

	/**
	 * Three equal shares of 1000.01 add up to 1000.01; the remainder cent
	 * goes to the largest share.
	 *
	 * @return void
	 */
	public function testThreeSharesAddUpToTheCent(): void {
		$shares = [
			['costCenter' => 'CC-1', 'projectId' => null, 'percentage' => 33.33, 'allocationSource' => 'fixed'],
			['costCenter' => 'CC-2', 'projectId' => null, 'percentage' => 33.33, 'allocationSource' => 'fixed'],
			['costCenter' => 'CC-3', 'projectId' => null, 'percentage' => 33.34, 'allocationSource' => 'fixed'],
		];

		$lines = $this->service->linesFor($shares, 100001, 17001);

		self::assertSame(100001, (int)round(array_sum(array_column($lines, 'gross')) * 100));
		self::assertSame(17001, (int)round(array_sum(array_column($lines, 'employerCharges')) * 100));
		self::assertSame(117002, (int)round(array_sum(array_column($lines, 'totalCost')) * 100));
		self::assertSame(333.4, $lines[2]['gross']);
	}//end testThreeSharesAddUpToTheCent()

	/**
	 * The run replaces its lines: earlier lines of the same run are removed,
	 * another run's are kept, and every written line fits the real schema.
	 *
	 * @return void
	 */
	public function testTheRunReplacesItsLinesAndTheyFitTheSchema(): void {
		$this->store->seed('WageCostAllocation', 'old-1', ['payrollRunId' => 'run-5', 'payslipId' => 'slip-gone', 'costCenter' => 'CC-9']);
		$this->store->seed('WageCostAllocation', 'other-1', ['payrollRunId' => 'run-4', 'payslipId' => 'slip-april', 'costCenter' => 'CC-9']);
		$this->store->seed('CostAllocation', 'ca-1', ['employeeId' => 'emp-devries', 'basis' => 'fixed', 'startDate' => '2026-01-01', 'splits' => [['costCenter' => 'CC-100', 'percentage' => 60], ['costCenter' => 'CC-200', 'percentage' => 40]]]);
		$this->store->seed('OrgAssignment', 'as-1', ['employeeId' => 'emp-jansen', 'orgUnitId' => 'unit-consultancy', 'startDate' => '2024-01-01']);

		$written = $this->service->allocateRun(
			runId: 'run-5',
			period: '2026-05',
			administrationId: 'ADM-001',
			payslips: [
				['payslipId' => 'slip-devries', 'employeeId' => 'emp-devries', 'grossCents' => 291200, 'chargesCents' => 50000],
				['payslipId' => 'slip-jansen', 'employeeId' => 'emp-jansen', 'grossCents' => 380000, 'chargesCents' => 64980],
			]
		);

		self::assertSame(3, $written);
		$rows = array_values(array_filter($this->rowsOf('WageCostAllocation'), static fn (array $r): bool => ($r['payrollRunId'] ?? '') === 'run-5'));
		self::assertCount(3, $rows);
		self::assertNotContains('slip-gone', array_column($rows, 'payslipId'));
		self::assertCount(1, array_filter($this->rowsOf('WageCostAllocation'), static fn (array $r): bool => ($r['payrollRunId'] ?? '') === 'run-4'));

		foreach ($rows as $row) {
			unset($row['id']);
			self::assertSame([], RegisterSchemaValidator::errors('WageCostAllocation', $row));
			self::assertSame('2026-05', $row['period']);
			self::assertSame('ADM-001', $row['administrationId']);
		}

		self::assertSame(4449.8, round(array_sum(array_column(array_filter($rows, static fn (array $r): bool => $r['payslipId'] === 'slip-jansen'), 'totalCost')), 2));
	}//end testTheRunReplacesItsLinesAndTheyFitTheSchema()

	/**
	 * A register that cannot be read degrades to no lines, never an error.
	 *
	 * @return void
	 */
	public function testAnEmptyRunWritesNothing(): void {
		self::assertSame(0, $this->service->allocateRun(runId: '', period: '2026-05', administrationId: 'ADM-001', payslips: []));
	}//end testAnEmptyRunWritesNothing()

	/**
	 * Every row of a schema in the fake store.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rowsOf(string $schema): array {
		$this->store->setSchema($schema);
		return $this->store->findAll();
	}//end rowsOf()

}//end class
