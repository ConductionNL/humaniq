<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The hours migration writes the recorded identity caches (userId,
 * managerUserId, administrationId) over a world of employees, units,
 * placements and timesheets: user links with whitespace or missing, one,
 * two or no managers, self-managed units that walk up to the parent, units
 * in another administration, expired and future placements, rows whose id
 * sits only in @self. Every write is recorded with its schema, uuid, RBAC
 * and multitenancy flags and whether it ran as the internal writer, and so
 * is every manager lookup the pass makes, over two runs (the second must
 * write no caches).
 *
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Repair;

use OCA\Humaniq\Repair\MigrateHoursProcess;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\TimesheetAggregationService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Golden test for the identity-cache backfill of {@see MigrateHoursProcess::migrate()}.
 *
 * @covers \OCA\Humaniq\Repair\MigrateHoursProcess
 * @uses   \OCA\Humaniq\Repair\TimesheetIdentityCaches
 * @uses   \OCA\Humaniq\Service\InternalWriteMarker
 * @uses   \OCA\Humaniq\Service\OrgResolutionService
 * @uses   \OCA\Humaniq\Service\TimesheetAggregationService
 * @uses   \OCA\Humaniq\Support\KeepsReadableRows
 */
class MigrateHoursBackfillGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/migrate-hours-backfill.json';

	/**
	 * Both runs produce the recorded writes, lookups and summaries.
	 *
	 * @return void
	 */
	public function testTheBackfillMatchesTheGolden(): void {
		$actual = $this->record();
		if (getenv('HUMANIQ_GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($golden as $key => $expected) {
			self::assertSame(
				json_encode($expected, JSON_PRESERVE_ZERO_FRACTION),
				json_encode($actual[$key], JSON_PRESERVE_ZERO_FRACTION),
				$key
			);
		}
	}//end testTheBackfillMatchesTheGolden()

	/**
	 * Run the pass twice over the world and record what it did.
	 *
	 * @return array<string, mixed>
	 */
	private function record(): array {
		$marker = new InternalWriteMarker();
		$store = new FakeObjectStore();
		$this->seedWorld($store);

		$log = new class {

			/**
			 * @var list<array<string, mixed>>
			 */
			public array $entries = [];
		};
		$spy = new class($store, $marker, $log) extends FakeObjectStore {

			/**
			 * @param FakeObjectStore $inner The store to mirror state from.
			 * @param InternalWriteMarker $marker The marker to observe.
			 * @param object $log Clone-surviving recorder.
			 */
			public function __construct(FakeObjectStore $inner, private readonly InternalWriteMarker $marker, public object $log) {
				parent::__construct();
				$this->state = $inner->state;
			}//end __construct()

			/**
			 * {@inheritDoc}
			 *
			 * @return \OCA\OpenRegister\Db\ObjectEntity
			 */
			public function saveObject(
				array|object $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): \OCA\OpenRegister\Db\ObjectEntity {
				$this->log->entries[] = [
					'save' => $schema,
					'uuid' => $uuid,
					'register' => $register,
					'rbac' => $_rbac,
					'multitenancy' => $_multitenancy,
					'internal' => $this->marker->isInternal(),
					'object' => $object,
				];

				return parent::saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy);
			}//end saveObject()

		};

		$org = new class($log) extends OrgResolutionService {

			/**
			 * @param object $log Shared recorder.
			 */
			public function __construct(private readonly object $log) {
			}//end __construct()

			/**
			 * {@inheritDoc}
			 *
			 * @return list<string>
			 */
			public function resolveManagerUserIds(
				string $employeeId,
				array $assignmentsByEmployeeId,
				array $unitsById,
				array $employeesById,
				string $onDate,
			): array {
				$resolved = parent::resolveManagerUserIds($employeeId, $assignmentsByEmployeeId, $unitsById, $employeesById, $onDate);
				$this->log->entries[] = [
					'lookup' => $employeeId,
					'onDateIsToday' => ($onDate === gmdate('Y-m-d')),
					'indexes' => md5(json_encode([$assignmentsByEmployeeId, $unitsById, $employeesById])),
					'resolved' => $resolved,
				];

				return $resolved;
			}//end resolveManagerUserIds()

		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$container = new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $spy]);
		$repair = new MigrateHoursProcess(
			container: $container,
			marker: $marker,
			orgResolution: $org,
			aggregationService: new TimesheetAggregationService(container: $container, marker: $marker, settingsService: $settings),
			settingsService: $settings,
			logger: new NullLogger()
		);

		$out = [];
		foreach (['first', 'second'] as $run) {
			$log->entries = [];
			$out[$run . ' summary'] = $repair->migrate();
			$out[$run . ' log'] = $log->entries;
		}

		$timesheets = $store->state->objects['Timesheet'];
		ksort($timesheets);
		$out['caches after'] = array_map(
			static fn (array $row): array => [
				'userId' => ($row['userId'] ?? '(absent)'),
				'managerUserId' => ($row['managerUserId'] ?? '(absent)'),
				'administrationId' => ($row['administrationId'] ?? '(absent)'),
			],
			$timesheets
		);

		return $out;
	}//end record()

	/**
	 * Seed employees, managers, units, placements and timesheets.
	 *
	 * @param FakeObjectStore $store The store.
	 *
	 * @return void
	 */
	private function seedWorld(FakeObjectStore $store): void {
		$managers = [
			'm1' => ['nextcloudUserId' => 'mgr1', 'administrationId' => 'ADM-1'],
			'm2' => ['nextcloudUserId' => ' mgr2 ', 'administrationId' => 'ADM-1'],
			'm3' => ['nextcloudUserId' => 'mgr3', 'administrationId' => 'ADM-2'],
			'm4' => ['administrationId' => 'ADM-1'],
			'm5' => ['nextcloudUserId' => 'mgr5'],
		];
		foreach ($managers as $id => $row) {
			$store->seed('Employee', $id, $row);
		}

		$units = [
			'uA' => ['managerId' => 'm1', 'administrationId' => 'ADM-1'],
			'uB' => ['managerId' => 'm2'],
			'uC' => ['managerId' => 'm3', 'administrationId' => 'ADM-2'],
			'uSelf' => ['managerId' => 'e1', 'parentUnitId' => 'uA', 'administrationId' => 'ADM-1'],
			'uNone' => ['parentUnitId' => 'uB'],
			'uGhost' => ['managerId' => 'nobody', 'administrationId' => 'ADM-1'],
			'uNoAcct' => ['managerId' => 'm4', 'administrationId' => 'ADM-1'],
			'uLoose' => ['managerId' => 'm5'],
		];
		foreach ($units as $id => $row) {
			$store->seed('OrgUnit', $id, $row);
		}

		$users = ['u1', ' u2 ', '', null, 'mgr1'];
		$administrations = ['ADM-1', ' ADM-1 ', 'ADM-2', '', null];
		$placements = [
			[],
			[['uA', '', '']],
			[['uB', '', '']],
			[['uA', '', ''], ['uC', '', '']],
			[['uA', '', ''], ['uSelf', '', '']],
			[['uSelf', '', '']],
			[['uNone', '', '']],
			[['uGhost', '', '']],
			[['uNoAcct', '', '']],
			[['uLoose', '', '']],
			[['uA', '2000-01-01', '2001-01-01']],
			[['uB', '2099-01-01', '']],
			[['uA', '2000-01-01', '2001-01-01'], ['uB', '2000-01-01', '']],
			[['missing-unit', '', '']],
		];

		$n = 0;
		foreach ($placements as $p => $assignments) {
			foreach ([0, 1, 2, 3] as $variant) {
				$employeeId = 'e' . (++$n);
				$employee = ['firstName' => 'E' . $n];
				$user = $users[($p + $variant) % count($users)];
				$administration = $administrations[($p * 3 + $variant) % count($administrations)];
				if ($user !== null) {
					$employee['nextcloudUserId'] = $user;
				}

				if ($administration !== null) {
					$employee['administrationId'] = $administration;
				}

				$store->seed('Employee', $employeeId, $employee);
				foreach ($assignments as $a => [$unitId, $start, $end]) {
					$store->seed('OrgAssignment', 'a-' . $employeeId . '-' . $a, ['employeeId' => ' ' . $employeeId, 'orgUnitId' => $unitId, 'startDate' => $start, 'endDate' => $end]);
				}

				$this->seedTimesheets($store, $employeeId, $variant);
			}
		}

		$store->seed('Timesheet', 'ts-no-employee', ['period' => '2026-05', 'hours' => 0, 'userId' => 'stale']);
		$store->seed('Timesheet', 'ts-padded-employee', ['employeeId' => ' e5 ', 'period' => '2026-05', 'hours' => 0]);
		$store->seed('Timesheet', 'ts-unknown-employee', ['employeeId' => 'ghost', 'period' => '2026-05', 'hours' => 0]);
		$store->state->objects['Timesheet']['ts-self-only'] = ['employeeId' => 'e6', 'period' => '2026-05', 'hours' => 0, '@self' => ['id' => 'ts-self-only']];
		$store->state->objects['Timesheet']['ts-no-id'] = ['employeeId' => 'e7', 'period' => '2026-05', 'hours' => 0];
	}//end seedWorld()

	/**
	 * Seed the timesheets of one employee: blank caches, matching caches,
	 * stale caches and caches padded with whitespace.
	 *
	 * @param FakeObjectStore $store The store.
	 * @param string $employeeId The employee.
	 * @param int $variant Which cache shapes to seed.
	 *
	 * @return void
	 */
	private function seedTimesheets(FakeObjectStore $store, string $employeeId, int $variant): void {
		$shapes = [
			'blank' => [],
			'stale' => ['userId' => 'old-user', 'managerUserId' => 'old-mgr', 'administrationId' => 'ADM-9'],
			'padded' => ['userId' => ' u1 ', 'managerUserId' => ' mgr1 ', 'administrationId' => ' ADM-1 '],
			'empty' => ['userId' => '', 'managerUserId' => '', 'administrationId' => ''],
		];
		$shape = array_keys($shapes)[$variant];
		$store->seed('Timesheet', 'ts-' . $employeeId . '-' . $shape, array_merge(['employeeId' => $employeeId, 'period' => '2026-0' . ($variant + 1), 'hours' => 0, '@self' => ['id' => 'ts-' . $employeeId . '-' . $shape, 'owner' => 'x']], $shapes[$shape]));
	}//end seedTimesheets()
}//end class
