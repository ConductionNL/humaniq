<?php

/**
 * Unit tests for LeaveAccrualJob.
 *
 * Pins the leave-accrual-job contract (design.md D2/D3/D4/D5): first-run
 * statutory provisioning at the full annual figure (never a monthly slice),
 * one bovenwettelijk 1/12 slice per accrued month, hard idempotency via
 * `lastAccruedPeriod` (a second run in the same employee-month writes
 * nothing), the `leave_accrual_enabled` off-switch, per-employee skip
 * reporting for an unresolvable covering contract, and that a
 * job-provisioned balance passes the real `nl-verlof-wettelijk-minimum` /
 * `nl-verlof-vervaltermijn` predicates from the labour.json corpus. Drives
 * the job through a fake ObjectService double (a fake collaborator, not a
 * fake of the job logic under test), mirroring PayrollRunServiceTest's
 * pattern, since the real OpenRegister ObjectService is a sibling-app
 * dependency not available in this standalone suite.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\BackgroundJob
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
 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-001
 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-002
 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-003
 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-004
 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\BackgroundJob;

use OCA\Hrmq\BackgroundJob\LeaveAccrualJob;
use OCA\Hrmq\Service\SettingsService;
use OCA\Hrmq\Standards\Checks\NlLeaveChecks;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for LeaveAccrualJob.
 *
 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-001
 */
class LeaveAccrualJobTest extends TestCase {

	/**
	 * Build a fake ObjectService double: `findAll()` returns the seeded rows
	 * for the current schema, `saveObject()` records every write (assigning
	 * a generated id when no uuid is given) and reflects it back into the
	 * seeded rows so a subsequent `findAll()` in the SAME run (or a later
	 * `runAccrual()` call against the same fake) observes it.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return object The fake ObjectService.
	 */
	private function fakeObjectService(array $rowsBySchema = []): object {
		return new class($rowsBySchema) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @var int
			 */
			private int $nextId = 1;

			/**
			 * Every saveObject() call, as `['schema' => ..., 'object' => ...]`.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
			 */
			public function __construct(
				public array $rowsBySchema,
			) {

			}//end __construct()

			/**
			 * @param string $register Register slug (unused by the fake).
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema name.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options Query options (unused by the fake).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()

			/**
			 * @param array<string, mixed> $object The object to save.
			 * @param string|null $register Register slug (unused by the fake).
			 * @param string|null $schema Schema name.
			 * @param string|null $uuid Existing id when updating.
			 * @param bool $_rbac Unused by the fake.
			 * @param bool $_multitenancy Unused by the fake.
			 *
			 * @return array<string, mixed> The saved object (with its id).
			 */
			public function saveObject(
				array $object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$targetSchema = ($schema ?? $this->schema);

				$id = ($uuid ?? ('generated-' . $targetSchema . '-' . $this->nextId++));
				$saved = array_merge($object, ['id' => $id]);

				$this->saved[] = ['schema' => $targetSchema, 'object' => $saved];

				$rows = ($this->rowsBySchema[$targetSchema] ?? []);
				$replaced = false;
				foreach ($rows as $i => $row) {
					if ((string)($row['id'] ?? '') === $id) {
						$rows[$i] = $saved;
						$replaced = true;
						break;
					}
				}

				if ($replaced === false) {
					$rows[] = $saved;
				}

				$this->rowsBySchema[$targetSchema] = $rows;

				return $saved;
			}//end saveObject()

		};

	}//end fakeObjectService()

	/**
	 * Build a fully-wired LeaveAccrualJob plus its fake ObjectService double
	 * (for assertions on what was saved) and the underlying rows array
	 * (rowsBySchema is stored by reference in the fake, so re-reading
	 * `$fake->rowsBySchema` after a run reflects the writes).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 * @param string $now ISO datetime the job believes "now" is.
	 * @param bool $enabled `leave_accrual_enabled`.
	 * @param float $annualBovenwettelijk `leave_bovenwettelijk_annual_hours`.
	 *
	 * @return array{0: LeaveAccrualJob, 1: object}
	 */
	private function job(array $rowsBySchema = [], string $now = '2026-07-15', bool $enabled = true, float $annualBovenwettelijk = 0.0): array {
		$fake = $this->fakeObjectService($rowsBySchema);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		$settings->method('isLeaveAccrualEnabled')->willReturn($enabled);
		$settings->method('getLeaveBovenwettelijkAnnualHours')->willReturn($annualBovenwettelijk);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new \DateTimeImmutable($now))->getTimestamp());

		$logger = $this->createMock(LoggerInterface::class);

		return [new LeaveAccrualJob($time, $container, $settings, $logger), $fake];
	}//end job()

	/**
	 * The anchor-case Employee fixture (40 hours/week, active throughout),
	 * overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function employee(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'emp-1',
				'startDate' => '2020-01-01',
				'endDate' => null,
			],
			$overrides
		);

	}//end employee()

	/**
	 * The anchor-case EmploymentContract fixture (40 hours/week, covers the
	 * employee throughout), overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function contract(array $overrides = []): array {
		return array_merge(
			[
				'employeeId' => 'emp-1',
				'startDate' => '2020-01-01',
				'endDate' => null,
				'hoursPerWeek' => 40,
			],
			$overrides
		);

	}//end contract()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-002
	 */
	public function testFirstRunProvisionsStatutoryInFull(): void {
		[$job, $fake] = $this->job(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'LeaveBalance' => [],
			],
			now: '2026-07-15'
		);

		$summary = $job->runAccrual();

		$this->assertSame('2026-07', $summary['period']);
		$this->assertCount(1, $summary['provisioned']);
		$this->assertCount(0, $summary['accrued']);
		$this->assertCount(0, $summary['skipped']);
		$this->assertSame(0, $summary['noop']);

		$this->assertCount(1, $fake->saved);
		$balance = $fake->saved[0]['object'];

		$this->assertSame('LeaveBalance', $fake->saved[0]['schema']);
		$this->assertSame('emp-1', $balance['employeeId']);
		$this->assertSame(2026, $balance['year']);
		$this->assertSame('holiday', $balance['leaveType']);
		$this->assertSame(160.0, $balance['entitledHours']);
		$this->assertSame(40.0, $balance['contractHoursPerWeek']);
		$this->assertSame(0.0, $balance['usedHours']);
		$this->assertSame('2027-07-01', $balance['expiryDate']);
		$this->assertSame('2026-07', $balance['lastAccruedPeriod']);
		// Default leave_bovenwettelijk_annual_hours is 0 — statutory-only.
		$this->assertSame(0.0, $balance['bovenwettelijkHours']);

	}//end testFirstRunProvisionsStatutoryInFull()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-002
	 */
	public function testProvisionedBalancePassesMandatoryVerlofRules(): void {
		[$job, $fake] = $this->job(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'LeaveBalance' => [],
			],
			now: '2026-07-15'
		);

		$job->runAccrual();
		$balance = $fake->saved[0]['object'];

		$checks = NlLeaveChecks::checks()['LeaveBalance'];
		$this->assertTrue(($checks['nl-verlof-wettelijk-minimum'])($balance), 'provisioned balance violates nl-verlof-wettelijk-minimum');
		$this->assertTrue(($checks['nl-verlof-vervaltermijn'])($balance), 'provisioned balance violates nl-verlof-vervaltermijn');
		$this->assertTrue(($checks['nl-verlof-saldo-niet-negatief'])($balance), 'provisioned balance violates nl-verlof-saldo-niet-negatief');

	}//end testProvisionedBalancePassesMandatoryVerlofRules()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-004
	 */
	public function testSecondRunInSameMonthIsANoOp(): void {
		$existingBalance = [
			'id' => 'bal-1',
			'employeeId' => 'emp-1',
			'year' => 2026,
			'leaveType' => 'holiday',
			'entitledHours' => 160.0,
			'bovenwettelijkHours' => 0.0,
			'usedHours' => 0.0,
			'contractHoursPerWeek' => 40.0,
			'expiryDate' => '2027-07-01',
			'lastAccruedPeriod' => '2026-07',
		];

		[$job, $fake] = $this->job(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'LeaveBalance' => [$existingBalance],
			],
			now: '2026-07-28'
		);

		$summary = $job->runAccrual();

		$this->assertSame('2026-07', $summary['period']);
		$this->assertCount(0, $summary['provisioned']);
		$this->assertCount(0, $summary['accrued']);
		$this->assertSame(1, $summary['noop']);

		// No ObjectService write occurred for that balance and every figure
		// is unchanged (REQ-ACCR-004 scenario).
		$this->assertCount(0, $fake->saved);
		$this->assertSame($existingBalance, $fake->rowsBySchema['LeaveBalance'][0]);

	}//end testSecondRunInSameMonthIsANoOp()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-003
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-004
	 */
	public function testNextMonthAccruesExactlyOneBovenwettelijkSlice(): void {
		$existingBalance = [
			'id' => 'bal-1',
			'employeeId' => 'emp-1',
			'year' => 2026,
			'leaveType' => 'holiday',
			'entitledHours' => 160.0,
			'bovenwettelijkHours' => 4.0,
			'usedHours' => 0.0,
			'contractHoursPerWeek' => 40.0,
			'expiryDate' => '2027-07-01',
			'lastAccruedPeriod' => '2026-07',
		];

		[$job, $fake] = $this->job(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'LeaveBalance' => [$existingBalance],
			],
			now: '2026-08-01',
			annualBovenwettelijk: 48.0
		);

		$summary = $job->runAccrual();

		$this->assertSame('2026-08', $summary['period']);
		$this->assertCount(0, $summary['provisioned']);
		$this->assertCount(1, $summary['accrued']);
		$this->assertSame(0, $summary['noop']);

		$this->assertCount(1, $fake->saved);
		$balance = $fake->saved[0]['object'];

		// round1(48 / 12) = 4 -> one further slice onto the prior 4.
		$this->assertSame(8.0, $balance['bovenwettelijkHours']);
		$this->assertSame('2026-08', $balance['lastAccruedPeriod']);
		// Statutory was already fully granted in a prior month -- unchanged.
		$this->assertSame(160.0, $balance['entitledHours']);
		$this->assertSame(40.0, $balance['contractHoursPerWeek']);

	}//end testNextMonthAccruesExactlyOneBovenwettelijkSlice()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 */
	public function testDisabledConfigNoOpsTheWholeRun(): void {
		[$job, $fake] = $this->job(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'LeaveBalance' => [],
			],
			now: '2026-07-15',
			enabled: false
		);

		$summary = $job->runAccrual();

		$this->assertFalse($summary['enabled']);
		$this->assertCount(0, $summary['provisioned']);
		$this->assertCount(0, $summary['accrued']);
		$this->assertCount(0, $summary['skipped']);
		$this->assertSame(0, $summary['noop']);
		$this->assertCount(0, $fake->saved);

	}//end testDisabledConfigNoOpsTheWholeRun()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 */
	public function testEmployeeWithoutCoveringContractIsSkippedWithReason(): void {
		[$job, $fake] = $this->job(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [],
				'LeaveBalance' => [],
			],
			now: '2026-07-15'
		);

		$summary = $job->runAccrual();

		$this->assertCount(0, $summary['provisioned']);
		$this->assertCount(1, $summary['skipped']);
		$this->assertSame('no-covering-contract', $summary['skipped'][0]['reason']);
		$this->assertCount(0, $fake->saved);

	}//end testEmployeeWithoutCoveringContractIsSkippedWithReason()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 */
	public function testEmployeeWithNoHoursPerWeekIsSkippedWithReason(): void {
		[$job, $fake] = $this->job(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract(['hoursPerWeek' => null])],
				'LeaveBalance' => [],
			],
			now: '2026-07-15'
		);

		$summary = $job->runAccrual();

		$this->assertCount(1, $summary['skipped']);
		$this->assertSame('no-hours-per-week', $summary['skipped'][0]['reason']);
		$this->assertCount(0, $fake->saved);

	}//end testEmployeeWithNoHoursPerWeekIsSkippedWithReason()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-001
	 */
	public function testEmployeeInactiveInPeriodIsNotAccrued(): void {
		[$job, $fake] = $this->job(
			[
				'Employee' => [$this->employee(['startDate' => '2020-01-01', 'endDate' => '2026-01-31'])],
				'EmploymentContract' => [$this->contract(['endDate' => '2026-01-31'])],
				'LeaveBalance' => [],
			],
			now: '2026-07-15'
		);

		$summary = $job->runAccrual();

		$this->assertCount(0, $summary['provisioned']);
		$this->assertCount(0, $summary['accrued']);
		$this->assertCount(0, $summary['skipped']);
		$this->assertCount(0, $fake->saved);

	}//end testEmployeeInactiveInPeriodIsNotAccrued()

}//end class
