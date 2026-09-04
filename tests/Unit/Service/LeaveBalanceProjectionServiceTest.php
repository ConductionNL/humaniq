<?php

/**
 * Unit tests for LeaveBalanceProjectionService.
 *
 * Pins the contract that `LeaveBalance.usedHours` is the SUM of the approved
 * LeaveRequests behind it: approving moves the balance, leaving `approved`
 * returns the hours, and an unchanged projection writes nothing at all. Also
 * pins the hours-derivation rules for the common case of a multi-day request
 * that carries only start and end dates.
 *
 * Drives the service through the same fake ObjectService double the
 * LeaveBuySellSettlementService suite uses (a fake collaborator, not a fake of
 * the logic under test) because the real OpenRegister ObjectService is a
 * sibling-app dependency this standalone suite does not have. Every assertion
 * is against what the fake RECORDED being written, never against the service's
 * own return value.
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
 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-001
 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\LeaveBalanceProjectionService;
use OCA\Humaniq\Service\LeaveHoursCalculator;
use OCA\Humaniq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for LeaveBalanceProjectionService.
 *
 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-001
 */
class LeaveBalanceProjectionServiceTest extends TestCase {

	/**
	 * Build a fake ObjectService double supporting the subset the projection
	 * service uses: `setRegister()`/`setSchema()`/`findAll()` and
	 * `saveObject()`, recording every write.
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
				$id = ($uuid ?? (string)($object['id'] ?? ''));
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
	 * Build a fully-wired projection service plus its fake ObjectService double.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return array{0: LeaveBalanceProjectionService, 1: object}
	 */
	private function service(array $rowsBySchema = []): array {
		$fake = $this->fakeObjectService($rowsBySchema);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		// ADR-083: objectService() establishes availability first, and a bare
		// createMock() answers a bool method with false.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);

		return [new LeaveBalanceProjectionService($container, $settings, $logger), $fake];

	}//end service()

	/**
	 * One holiday balance for 2026 plus the requests given.
	 *
	 * @param array<int, array<string, mixed>> $requests The LeaveRequest rows.
	 * @param array<string, mixed> $balanceOverrides Fields to override on the balance.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function fixture(array $requests, array $balanceOverrides = []): array {
		$balance = array_merge(
			[
				'id' => 'bal-1',
				'employeeId' => 'emp-1',
				'year' => 2026,
				'leaveType' => 'holiday',
				'entitledHours' => 160.0,
				'bovenwettelijkHours' => 0.0,
				'usedHours' => 0.0,
				'contractHoursPerWeek' => 40.0,
			],
			$balanceOverrides
		);

		return [
			'LeaveBalance' => [$balance],
			'LeaveRequest' => $requests,
		];

	}//end fixture()

	/**
	 * The usedHours value written onto the balance, or null when nothing was written.
	 *
	 * @param object $fake The fake ObjectService.
	 *
	 * @return float|null The written usedHours.
	 */
	private function writtenUsedHours(object $fake): ?float {
		foreach ($fake->saved as $write) {
			if ($write['schema'] === 'LeaveBalance') {
				return (float)$write['object']['usedHours'];
			}
		}

		return null;

	}//end writtenUsedHours()

	/**
	 * An approved request moves the balance by its explicit hours.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-001
	 */
	public function testApprovedRequestIsProjectedOntoTheBalance(): void {
		$request = [
			'id' => 'req-1',
			'employeeId' => 'emp-1',
			'leaveType' => 'holiday',
			'startDate' => '2026-03-02',
			'endDate' => '2026-03-06',
			'hours' => 40,
			'status' => 'approved',
		];

		[$service, $fake] = $this->service($this->fixture([$request]));
		$service->projectForRequest($request);

		$this->assertSame(40.0, $this->writtenUsedHours($fake));

	}//end testApprovedRequestIsProjectedOntoTheBalance()

	/**
	 * A request that is not approved contributes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-001
	 */
	public function testAnUnapprovedRequestContributesNothing(): void {
		$request = [
			'id' => 'req-1',
			'employeeId' => 'emp-1',
			'leaveType' => 'holiday',
			'startDate' => '2026-03-02',
			'endDate' => '2026-03-06',
			'hours' => 40,
			'status' => 'submitted',
		];

		// The balance already carries 40 from a previous approval, so a correct
		// recompute must write 0 rather than leave the stale value alone.
		$rows = $this->fixture([$request], ['usedHours' => 40.0]);

		[$service, $fake] = $this->service($rows);
		$service->projectForRequest($request);

		$this->assertSame(0.0, $this->writtenUsedHours($fake));

	}//end testAnUnapprovedRequestContributesNothing()

	/**
	 * An unchanged projection issues no write at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-001
	 */
	public function testAnUnchangedProjectionWritesNothing(): void {
		$request = [
			'id' => 'req-1',
			'employeeId' => 'emp-1',
			'leaveType' => 'holiday',
			'startDate' => '2026-03-02',
			'endDate' => '2026-03-06',
			'hours' => 40,
			'status' => 'approved',
		];

		$rows = $this->fixture([$request], ['usedHours' => 40.0]);

		[$service, $fake] = $this->service($rows);
		$service->projectForRequest($request);

		$this->assertSame([], $fake->saved);

	}//end testAnUnchangedProjectionWritesNothing()

	/**
	 * Several approved requests sum, and other employees are excluded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-001
	 */
	public function testOnlyTheBalancesOwnApprovedRequestsAreSummed(): void {
		$mine = [
			'id' => 'req-1',
			'employeeId' => 'emp-1',
			'leaveType' => 'holiday',
			'startDate' => '2026-03-02',
			'endDate' => '2026-03-06',
			'hours' => 40,
			'status' => 'approved',
		];
		$alsoMine = array_merge($mine, ['id' => 'req-2', 'hours' => 8]);
		$otherEmployee = array_merge($mine, ['id' => 'req-3', 'employeeId' => 'emp-2', 'hours' => 100]);
		$otherType = array_merge($mine, ['id' => 'req-4', 'leaveType' => 'sick', 'hours' => 100]);
		$otherYear = array_merge(
			$mine,
			['id' => 'req-5', 'startDate' => '2025-03-03', 'endDate' => '2025-03-07', 'hours' => 100]
		);

		$rows = $this->fixture([$mine, $alsoMine, $otherEmployee, $otherType, $otherYear]);

		[$service, $fake] = $this->service($rows);
		$service->projectForRequest($mine);

		$this->assertSame(48.0, $this->writtenUsedHours($fake));

	}//end testOnlyTheBalancesOwnApprovedRequestsAreSummed()

	/**
	 * With no matching balance nothing is written and nothing is created.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-001
	 */
	public function testAMissingBalanceIsNeverCreated(): void {
		$request = [
			'id' => 'req-1',
			'employeeId' => 'emp-unknown',
			'leaveType' => 'holiday',
			'startDate' => '2026-03-02',
			'endDate' => '2026-03-06',
			'hours' => 40,
			'status' => 'approved',
		];

		[$service, $fake] = $this->service($this->fixture([$request]));
		$service->projectForRequest($request);

		$this->assertSame([], $fake->saved);

	}//end testAMissingBalanceIsNeverCreated()

	/**
	 * A full working week with no explicit hours derives from the contract.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-002
	 */
	public function testHoursAreDerivedFromWorkingDaysWhenAbsent(): void {
		// Monday 2026-03-02 to Friday 2026-03-06 is five working days at 8h.
		$resolved = LeaveHoursCalculator::requestHours(
			['startDate' => '2026-03-02', 'endDate' => '2026-03-06'],
			40.0,
			2026
		);

		$this->assertTrue($resolved['derivable']);
		$this->assertSame(40.0, $resolved['hours']);

	}//end testHoursAreDerivedFromWorkingDaysWhenAbsent()

	/**
	 * A weekend inside the range is not counted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-002
	 */
	public function testWeekendDaysAreNotCounted(): void {
		// Friday 2026-03-06 to Monday 2026-03-09 is two working days at 8h.
		$resolved = LeaveHoursCalculator::requestHours(
			['startDate' => '2026-03-06', 'endDate' => '2026-03-09'],
			40.0,
			2026
		);

		$this->assertSame(16.0, $resolved['hours']);

	}//end testWeekendDaysAreNotCounted()

	/**
	 * A part-time contract derives proportionally smaller days.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-002
	 */
	public function testAPartTimeContractDerivesShorterDays(): void {
		$resolved = LeaveHoursCalculator::requestHours(
			['startDate' => '2026-03-02', 'endDate' => '2026-03-06'],
			24.0,
			2026
		);

		$this->assertSame(24.0, $resolved['hours']);

	}//end testAPartTimeContractDerivesShorterDays()

	/**
	 * An explicit hours value wins over derivation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-002
	 */
	public function testAnExplicitHoursValueWinsOverDerivation(): void {
		$resolved = LeaveHoursCalculator::requestHours(
			['startDate' => '2026-03-02', 'endDate' => '2026-03-06', 'hours' => 4],
			40.0,
			2026
		);

		$this->assertSame(4.0, $resolved['hours']);

	}//end testAnExplicitHoursValueWinsOverDerivation()

	/**
	 * Without a contract snapshot the hours are not derivable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-002
	 */
	public function testHoursAreNotDerivableWithoutAContractSnapshot(): void {
		$resolved = LeaveHoursCalculator::requestHours(
			['startDate' => '2026-03-02', 'endDate' => '2026-03-06'],
			null,
			2026
		);

		$this->assertFalse($resolved['derivable']);
		$this->assertSame(0.0, $resolved['hours']);

	}//end testHoursAreNotDerivableWithoutAContractSnapshot()

	/**
	 * An underivable request is named rather than silently counted as zero.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-002
	 */
	public function testAnUnderivableRequestIsNamed(): void {
		$projection = LeaveHoursCalculator::usedHoursFor(
			[
				[
					'id' => 'req-1',
					'employeeId' => 'emp-1',
					'leaveType' => 'holiday',
					'startDate' => '2026-03-02',
					'endDate' => '2026-03-06',
					'status' => 'approved',
				],
			],
			'emp-1',
			2026,
			'holiday',
			null
		);

		$this->assertSame(0.0, $projection['usedHours']);
		$this->assertSame(['req-1'], $projection['underivable']);

	}//end testAnUnderivableRequestIsNamed()

	/**
	 * A derived request spanning New Year splits across both years.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-002
	 */
	public function testADerivedRequestSpanningNewYearSplits(): void {
		// 2026-12-28 (Mon) to 2027-01-01 (Fri): four working days in 2026
		// (Mon to Thu) and one in 2027 (Fri).
		$range = ['startDate' => '2026-12-28', 'endDate' => '2027-01-01'];

		$this->assertSame(32.0, LeaveHoursCalculator::requestHours($range, 40.0, 2026)['hours']);
		$this->assertSame(8.0, LeaveHoursCalculator::requestHours($range, 40.0, 2027)['hours']);

	}//end testADerivedRequestSpanningNewYearSplits()

	/**
	 * Working-day counting handles an inverted and a single-day range.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-002
	 */
	public function testWorkingDayCountingEdgeCases(): void {
		// A single working day.
		$this->assertSame(1, LeaveHoursCalculator::workingDaysBetween('2026-03-02', '2026-03-02'));
		// A single weekend day.
		$this->assertSame(0, LeaveHoursCalculator::workingDaysBetween('2026-03-07', '2026-03-07'));
		// End before start.
		$this->assertSame(0, LeaveHoursCalculator::workingDaysBetween('2026-03-06', '2026-03-02'));
		// Unparseable input.
		$this->assertSame(0, LeaveHoursCalculator::workingDaysBetween('', ''));

	}//end testWorkingDayCountingEdgeCases()

}//end class
