<?php

/**
 * Unit tests for AnalyticsService.
 *
 * Pins the two refusals this class carries forward from `AbsenceRateService`
 * and the orchestrator's REQ-DSI-007 revision: a period bucket with nothing
 * to measure serialises to JSON `null`, never `0` (absence-rate/payroll-cost/
 * approval-lead-time alike), and approval lead time is a MEDIAN + p90, never
 * a mean — the outlier control (nine records at 2 days plus one at 200) is
 * the assertion that fails if anyone reverts to a mean. The Obligations list
 * (a DIFFERENT class, {@see ObligationsService} — the class split a phpmd
 * class-complexity finding forced) has its own test file,
 * `ObligationsServiceTest.php`.
 *
 * Drives the service through a fake ObjectService double (a fake
 * collaborator, not a fake of the logic under test) — the
 * AdministrationServiceTest / RuleAuditServiceTest precedent.
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
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-004
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-006
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\Service\AbsenceRateService;
use OCA\Humaniq\Service\AnalyticsService;
use OCA\Humaniq\Service\Percentile;
use OCA\Humaniq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AnalyticsService.
 *
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
 */
class AnalyticsServiceTest extends TestCase {

	/**
	 * The current calendar-month bucket key every trend test resolves
	 * fixtures against — `trailingPeriodKeys()` always includes it as the
	 * most recent bucket, so it needs no clock injection to test.
	 *
	 * @return string `YYYY-MM`.
	 */
	private function thisMonth(): string {
		return (new DateTimeImmutable('first day of this month'))->format('Y-m');
	}//end thisMonth()

	/**
	 * REQ-DSI-004 scenario: a zero-availability bucket (no SickLeaveCase, no
	 * EmploymentContract for this administration) serialises to JSON `null`,
	 * never `0`.
	 *
	 * @return void
	 */
	public function testAbsenceRateZeroAvailabilityBucketIsNullNotZero(): void {
		$service = $this->buildService([]);

		$result = $service->getTrends('absence-rate', 'quarter', 'ADM-001');

		$bucket = $this->bucketFor($result['series'], $this->thisMonth());
		$this->assertNull($bucket['value']);
	}//end testAbsenceRateZeroAvailabilityBucketIsNullNotZero()

	/**
	 * REQ-DSI-004: a period with real availability/absence renders the exact
	 * figure `AbsenceRateService::absenceRate()` computes — the endpoint adds
	 * no arithmetic of its own.
	 *
	 * @return void
	 */
	public function testAbsenceRateRendersTheServicesOwnFigure(): void {
		$thisMonth = $this->thisMonth();
		$service = $this->buildService([
			'EmploymentContract' => [
				['employeeId' => 'emp-1', 'administrationId' => 'ADM-001', 'hoursPerWeek' => 40.0, 'startDate' => '2020-01-01', 'endDate' => null],
			],
			'SickLeaveCase' => [
				['employeeId' => 'emp-1', 'administrationId' => 'ADM-001', 'firstSickDay' => $thisMonth . '-01', 'status' => 'gemeld'],
			],
		]);

		$result = $service->getTrends('absence-rate', 'quarter', 'ADM-001');

		$bucket = $this->bucketFor($result['series'], $thisMonth);
		$this->assertSame(100.0, $bucket['value']);
	}//end testAbsenceRateRendersTheServicesOwnFigure()

	/**
	 * REQ-DSI-006 scenario: a `draft` run does not contribute to its
	 * period's total while a `posted` run in the same period/administration
	 * is included.
	 *
	 * @return void
	 */
	public function testPayrollCostExcludesDraftRunsButIncludesPosted(): void {
		$thisMonth = $this->thisMonth();
		$service = $this->buildService([
			'PayrollRun' => [
				['period' => $thisMonth, 'administrationId' => 'ADM-001', 'status' => 'draft', 'totalGross' => 100000.0, 'totalEmployerCharges' => 20000.0],
				['period' => $thisMonth, 'administrationId' => 'ADM-001', 'status' => 'posted', 'totalGross' => 3000.0, 'totalEmployerCharges' => 500.0],
			],
		]);

		$result = $service->getTrends('payroll-cost', 'quarter', 'ADM-001');

		$bucket = $this->bucketFor($result['series'], $thisMonth);
		$this->assertSame(3500.0, $bucket['value']);
	}//end testPayrollCostExcludesDraftRunsButIncludesPosted()

	/**
	 * REQ-DSI-006/002 (the general null-not-zero rule extended to
	 * payroll-cost): a period with no finalised PayrollRun at all reports
	 * `null`, not `0` — an unbilled period must not read as a good
	 * (zero-cost) one.
	 *
	 * @return void
	 */
	public function testPayrollCostBucketWithNoFinalisedRunIsNullNotZero(): void {
		$service = $this->buildService([]);

		$result = $service->getTrends('payroll-cost', 'quarter', 'ADM-001');

		$bucket = $this->bucketFor($result['series'], $this->thisMonth());
		$this->assertNull($bucket['value']);
	}//end testPayrollCostBucketWithNoFinalisedRunIsNullNotZero()

	/**
	 * REQ-DSI-007 scenario: a record with `approvedAt` set and `submittedAt`
	 * null is excluded entirely — never counted as a zero-day lead time.
	 *
	 * @return void
	 */
	public function testUnsubmittedThenDirectlyApprovedRecordIsExcluded(): void {
		$thisMonth = $this->thisMonth();
		$service = $this->buildService([
			'LeaveRequest' => [
				['administrationId' => 'ADM-001', 'submittedAt' => null, 'approvedAt' => $thisMonth . '-10T09:00:00Z'],
			],
		]);

		$result = $service->getTrends('approval-lead-time', 'quarter', 'ADM-001');

		$bucket = $this->bucketFor($result['series'], $thisMonth);
		$this->assertNull($bucket['median']);
		$this->assertNull($bucket['p90']);
	}//end testUnsubmittedThenDirectlyApprovedRecordIsExcluded()

	/**
	 * REQ-DSI-007 scenario: Timesheet (2 days), Expense (4 days), and
	 * LeaveRequest (6 days) approved in the same period pool into ONE
	 * population — median 4 — not three separate per-schema figures.
	 *
	 * @return void
	 */
	public function testThreeSchemasPoolIntoOnePopulation(): void {
		$thisMonth = $this->thisMonth();
		$approvedAt = $thisMonth . '-15T12:00:00Z';
		$service = $this->buildService([
			'Timesheet' => [$this->approvalRecord($approvedAt, 2)],
			'Expense' => [$this->approvalRecord($approvedAt, 4)],
			'LeaveRequest' => [$this->approvalRecord($approvedAt, 6)],
		]);

		$result = $service->getTrends('approval-lead-time', 'quarter', 'ADM-001');

		$bucket = $this->bucketFor($result['series'], $thisMonth);
		$this->assertSame(4.0, $bucket['median']);
	}//end testThreeSchemasPoolIntoOnePopulation()

	/**
	 * REQ-DSI-007 scenario: an empty bucket (no approval of any of the three
	 * schemas) reports no figure rather than `0` — the `AbsenceRateService`
	 * precedent extended to this metric, because a `0`-day lead time reads
	 * as instant approval.
	 *
	 * @return void
	 */
	public function testApprovalLeadTimeEmptyBucketIsNullNotZero(): void {
		$service = $this->buildService([]);

		$result = $service->getTrends('approval-lead-time', 'quarter', 'ADM-001');

		$bucket = $this->bucketFor($result['series'], $this->thisMonth());
		$this->assertNull($bucket['median']);
		$this->assertNull($bucket['p90']);
	}//end testApprovalLeadTimeEmptyBucketIsNullNotZero()

	/**
	 * THE OUTLIER CONTROL (REQ-DSI-007). Nine records approved in 2 days and
	 * one approved in 200 days, all in the same period: `median` must be 2
	 * and `p90` materially higher — the assertion that fails if the
	 * implementation quietly reverts to a mean, which would report ~21.8 for
	 * every statistic and describe a two-day process as a three-week one.
	 *
	 * @return void
	 */
	public function testOutlierControlMedianStaysTwoWhilePNinetyMovesMaterially(): void {
		$thisMonth = $this->thisMonth();
		$approvedAt = $thisMonth . '-20T08:00:00Z';

		$records = [];
		for ($i = 0; $i < 9; $i++) {
			$records[] = $this->approvalRecord($approvedAt, 2);
		}

		$records[] = $this->approvalRecord($approvedAt, 200);

		$service = $this->buildService(['Timesheet' => $records]);

		$result = $service->getTrends('approval-lead-time', 'quarter', 'ADM-001');

		$bucket = $this->bucketFor($result['series'], $thisMonth);
		$this->assertSame(2.0, $bucket['median']);
		$this->assertGreaterThan(10.0, $bucket['p90']);
	}//end testOutlierControlMedianStaysTwoWhilePNinetyMovesMaterially()

	/**
	 * An unsupported metric is refused before any data is loaded.
	 *
	 * @return void
	 */
	public function testUnsupportedMetricThrows(): void {
		$service = $this->buildService([]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unsupported metric');

		$service->getTrends('not-a-real-metric', 'quarter', 'ADM-001');
	}//end testUnsupportedMetricThrows()

	/**
	 * An unrecognised period window is refused.
	 *
	 * @return void
	 */
	public function testUnsupportedPeriodThrows(): void {
		$service = $this->buildService([]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid period');

		$service->getTrends('absence-rate', 'decade', 'ADM-001');
	}//end testUnsupportedPeriodThrows()

	/**
	 * A row scoped to a DIFFERENT administration never contributes — the
	 * tenant-isolation half of REQ-DSI-005, exercised at the service layer.
	 *
	 * @return void
	 */
	public function testRowsFromAnotherAdministrationAreExcluded(): void {
		$thisMonth = $this->thisMonth();
		$service = $this->buildService([
			'PayrollRun' => [
				['period' => $thisMonth, 'administrationId' => 'ADM-OTHER', 'status' => 'posted', 'totalGross' => 5000.0, 'totalEmployerCharges' => 1000.0],
			],
		]);

		$result = $service->getTrends('payroll-cost', 'quarter', 'ADM-001');

		$bucket = $this->bucketFor($result['series'], $thisMonth);
		$this->assertNull($bucket['value']);
	}//end testRowsFromAnotherAdministrationAreExcluded()

	/**
	 * One Timesheet/Expense/LeaveRequest-shaped approval record.
	 *
	 * @param string $approvedAt ISO 8601 approvedAt.
	 * @param int $days Days between submittedAt and approvedAt.
	 *
	 * @return array<string, mixed>
	 */
	private function approvalRecord(string $approvedAt, int $days): array {
		$submittedAt = (new DateTimeImmutable($approvedAt))->modify('-' . $days . ' days');
		return [
			'administrationId' => 'ADM-001',
			'submittedAt' => $submittedAt->format(DateTimeImmutable::ATOM),
			'approvedAt' => $approvedAt,
		];
	}//end approvalRecord()

	/**
	 * Find one bucket by its `date` key in a resolved trend series.
	 *
	 * @param array<int, array<string, mixed>> $series The resolved series.
	 * @param string $date The `YYYY-MM` bucket key to find.
	 *
	 * @return array<string, mixed>
	 */
	private function bucketFor(array $series, string $date): array {
		foreach ($series as $bucket) {
			if ($bucket['date'] === $date) {
				return $bucket;
			}
		}

		$this->fail('No bucket found for ' . $date);
	}//end bucketFor()

	/**
	 * REQ-DSI-002: a bucket with no registered hours at all is `null`, not
	 * `0.0`. A 0% billable ratio is a catastrophic reading and "nobody
	 * logged hours" is an absent measurement — the chart must not draw them
	 * the same way.
	 *
	 * @return void
	 */
	public function testBillableRatioBucketWithNoHoursIsNullNotZero(): void {
		$service = $this->buildService([]);

		$result = $service->getTrends('billable-ratio', 'quarter', 'ADM-001');

		$bucket = $this->bucketFor($result['series'], $this->thisMonth());
		$this->assertNull($bucket['value']);
	}//end testBillableRatioBucketWithNoHoursIsNullNotZero()

	/**
	 * REQ-DSI-002: the ratio is billable hours over ALL hours — the
	 * denominator includes the non-billable rows, which the replaced
	 * `filter: {billable: true}` GraphQL query could never see.
	 *
	 * @return void
	 */
	public function testBillableRatioDividesByAllRegisteredHours(): void {
		$thisMonth = $this->thisMonth();
		$service = $this->buildService([
			'Timesheet' => [
				['period' => $thisMonth, 'administrationId' => 'ADM-001', 'hours' => 30.0, 'billable' => true],
				['period' => $thisMonth, 'administrationId' => 'ADM-001', 'hours' => 10.0, 'billable' => false],
			],
		]);

		$result = $service->getTrends('billable-ratio', 'quarter', 'ADM-001');

		$bucket = $this->bucketFor($result['series'], $thisMonth);
		$this->assertSame(75.0, $bucket['value']);
	}//end testBillableRatioDividesByAllRegisteredHours()

	/**
	 * REQ-DSI-002: a bucket where hours WERE registered but none were
	 * billable really is `0.0` — the honest zero, distinguished from the
	 * `null` above. Without this the null-not-zero rule would be
	 * indistinguishable from "always null".
	 *
	 * @return void
	 */
	public function testBillableRatioAllNonBillableIsZeroNotNull(): void {
		$thisMonth = $this->thisMonth();
		$service = $this->buildService([
			'Timesheet' => [
				['period' => $thisMonth, 'administrationId' => 'ADM-001', 'hours' => 8.0, 'billable' => false],
			],
		]);

		$result = $service->getTrends('billable-ratio', 'quarter', 'ADM-001');

		$bucket = $this->bucketFor($result['series'], $thisMonth);
		$this->assertSame(0.0, $bucket['value']);
	}//end testBillableRatioAllNonBillableIsZeroNotNull()

	/**
	 * REQ-DSI-003: headcount counts who is active at the period's END,
	 * while starters and leavers count events WITHIN the period. One
	 * employee who started this month and one who left this month exercise
	 * all three at once.
	 *
	 * @return void
	 */
	public function testHeadcountSeparatesActiveFromStartersAndLeavers(): void {
		$thisMonth = $this->thisMonth();
		$service = $this->buildService([
			'Employee' => [
				['administrationId' => 'ADM-001', 'startDate' => '2020-01-01', 'endDate' => null],
				['administrationId' => 'ADM-001', 'startDate' => ($thisMonth . '-01'), 'endDate' => null],
				['administrationId' => 'ADM-001', 'startDate' => '2019-01-01', 'endDate' => ($thisMonth . '-02')],
			],
		]);

		$result = $service->getTrends('headcount', 'quarter', 'ADM-001');

		$bucket = $this->bucketFor($result['series'], $thisMonth);
		$this->assertSame(2, $bucket['headcount'], 'the leaver is not active at period end');
		$this->assertSame(1, $bucket['starters']);
		$this->assertSame(1, $bucket['leavers']);
	}//end testHeadcountSeparatesActiveFromStartersAndLeavers()

	/**
	 * REQ-DSI-003: an `Employee` with no parseable `startDate` cannot be
	 * placed on the timeline, so it is excluded rather than assumed to have
	 * started at some convenient moment — the same refusal
	 * `AbsenceRateService` makes for an absence with no covering contract.
	 *
	 * @return void
	 */
	public function testEmployeeWithoutStartDateIsExcludedNotAssumed(): void {
		$thisMonth = $this->thisMonth();
		$service = $this->buildService([
			'Employee' => [
				['administrationId' => 'ADM-001', 'startDate' => '2020-01-01', 'endDate' => null],
				['administrationId' => 'ADM-001', 'startDate' => null, 'endDate' => null],
				['administrationId' => 'ADM-001', 'endDate' => null],
			],
		]);

		$result = $service->getTrends('headcount', 'quarter', 'ADM-001');

		$bucket = $this->bucketFor($result['series'], $thisMonth);
		$this->assertSame(1, $bucket['headcount']);
		$this->assertSame(0, $bucket['starters']);
	}//end testEmployeeWithoutStartDateIsExcludedNotAssumed()

	/**
	 * Both new metrics read their objects from the hrmq REGISTER through
	 * `ObjectService`, never from a GraphQL type resolved by name across
	 * every register on the instance. That resolution is what shipped
	 * broken: measured live, the `Employee` root field resolved to schema
	 * 5050 in an unrelated register (`Employee5050Connection`) instead of
	 * hrmq's 1080. This asserts the schemas actually queried.
	 *
	 * @return void
	 */
	public function testNewMetricsQueryTheRegisterScopedSchemas(): void {
		$fake = $this->fakeObjectService([]);
		$service = $this->buildServiceWithObjectService($fake);

		$service->getTrends('billable-ratio', 'quarter', 'ADM-001');
		$service->getTrends('headcount', 'quarter', 'ADM-001');

		$this->assertContains('Timesheet', $fake->schemasQueried);
		$this->assertContains('Employee', $fake->schemasQueried);
	}//end testNewMetricsQueryTheRegisterScopedSchemas()

	/**
	 * Build an `AnalyticsService` backed by a fresh fake ObjectService
	 * pre-loaded with `$rowsBySchema`.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Canned rows, keyed by schema name.
	 *
	 * @return AnalyticsService
	 */
	private function buildService(array $rowsBySchema): AnalyticsService {
		return $this->buildServiceWithObjectService($this->fakeObjectService($rowsBySchema));
	}//end buildService()

	/**
	 * Build an `AnalyticsService` around an already-built fake ObjectService
	 * (so a test can inspect the fake afterwards — e.g. which schemas were
	 * queried).
	 *
	 * @param object $objectService The fake ObjectService.
	 *
	 * @return AnalyticsService
	 */
	private function buildServiceWithObjectService(object $objectService): AnalyticsService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);

		return new AnalyticsService($container, $settings, new AbsenceRateService(), new Percentile(), $logger);
	}//end buildServiceWithObjectService()

	/**
	 * A fake OpenRegister ObjectService returning canned rows per schema and
	 * recording every schema it was asked for — the AdministrationServiceTest
	 * precedent, extended with a query log for REQ-DSI-009's
	 * no-full-corpus-walk assertion.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Canned rows, keyed by schema name.
	 *
	 * @return object
	 */
	private function fakeObjectService(array $rowsBySchema): object {
		return new class($rowsBySchema) {

			/**
			 * @var array<int, string>
			 */
			public array $schemasQueried = [];

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Canned rows, keyed by schema name.
			 */
			public function __construct(
				private readonly array $rowsBySchema,
			) {
			}

			/**
			 * @param string $register Ignored; chainable.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema The schema to load on the next findAll().
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				$this->schemasQueried[] = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $options Ignored.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $options): array {
				return ($this->rowsBySchema[$this->schema] ?? []);
			}
		};
	}//end fakeObjectService()

}//end class
