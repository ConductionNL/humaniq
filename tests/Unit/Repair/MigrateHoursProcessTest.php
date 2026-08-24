<?php

/**
 * MigrateHoursProcess unit tests
 *
 * The Decision 9 migration against the three seed-shaped rows: jansen's
 * user link already resolves (`userId: admin`), devries/bakker stay null
 * and are counted K=2 in ONE summary; one `origin: "migration"` entry per
 * legacy timesheet with hours and none for a timesheet that already has
 * entries; a SECOND run reports M=0 with zero new writes (idempotency
 * asserted, not assumed); and entries for APPROVED rows are created via the
 * marker path.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Repair
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
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Repair;

use OCA\Humaniq\Repair\MigrateHoursProcess;
use OCA\Humaniq\Service\HoursMigrationRunner;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\TimesheetAggregationService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Backfill, synthesis, idempotency and the marker path of the migration.
 */
class MigrateHoursProcessTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The internal-writer marker.
	 *
	 * @var InternalWriteMarker
	 */
	private InternalWriteMarker $marker;

	/**
	 * The subject.
	 *
	 * @var MigrateHoursProcess
	 */
	private MigrateHoursProcess $repair;

	/**
	 * The container the subject resolves its collaborators from.
	 *
	 * @var FakeContainer
	 */
	private FakeContainer $container;

	/**
	 * The spying store handed to the migration (records marker state).
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $spy;

	/**
	 * Clone-surviving recorder of the marker state at each save — a plain
	 * array on the spy would record onto the CLONE the production code makes.
	 *
	 * @var object
	 */
	private object $recorder;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->marker = new InternalWriteMarker();
		$this->store = new FakeObjectStore();
		$this->recorder = new class {

			/**
			 * @var array<int, bool>
			 */
			public array $markerStates = [];
		};
		$this->spy = new class($this->store, $this->marker, $this->recorder) extends FakeObjectStore {

			/**
			 * @param FakeObjectStore $inner The store to mirror state from.
			 * @param InternalWriteMarker $marker The marker to observe.
			 * @param object $recorder Clone-surviving marker-state recorder.
			 */
			public function __construct(
				FakeObjectStore $inner,
				private readonly InternalWriteMarker $marker,
				public object $recorder,
			) {
				parent::__construct();
				$this->state = $inner->state;
			}//end __construct()

			/**
			 * {@inheritDoc}
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
				$this->recorder->markerStates[] = $this->marker->isInternal();

				return parent::saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy);
			}//end saveObject()

		};

		// The three seed-shaped rows (hr-seed.json): jansen submitted with a
		// resolving link, devries approved and bakker rejected without one.
		$this->store->seed('Employee', 'employee-jansen', [
			'nextcloudUserId' => 'admin',
			'administrationId' => 'ADM-001',
		]);
		$this->store->seed('Employee', 'employee-devries', ['administrationId' => 'ADM-001']);
		$this->store->seed('Employee', 'employee-bakker', ['administrationId' => 'ADM-001']);

		$this->store->seed('Timesheet', 'timesheet-jansen-2026-05', [
			'employeeId' => 'employee-jansen',
			'period' => '2026-05',
			'hours' => 152,
			'description' => 'Reguliere uren mei 2026',
			'projectId' => 'project-alpha',
			'costCenter' => 'CC-100',
			'billable' => true,
			'status' => 'submitted',
			'userId' => 'admin',
		]);
		$this->store->seed('Timesheet', 'timesheet-devries-2026-05', [
			'employeeId' => 'employee-devries',
			'period' => '2026-05',
			'hours' => 168,
			'billable' => false,
			'status' => 'approved',
		]);
		$this->store->seed('Timesheet', 'timesheet-bakker-2026-05', [
			'employeeId' => 'employee-bakker',
			'period' => '2026-05',
			'hours' => 140,
			'billable' => false,
			'status' => 'rejected',
		]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');

		$container = new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $this->spy]);
		$this->container = $container;
		$this->repair = new MigrateHoursProcess(
			container: $container,
			marker: $this->marker,
			orgResolution: new OrgResolutionService(),
			aggregationService: new TimesheetAggregationService(
				container: $container,
				marker: $this->marker,
				settingsService: $settings
			),
			settingsService: $settings,
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * A real HoursMigrationRunner whose session already carries a user, so
	 * runAsActingUser() runs the pass directly (no impersonation involved).
	 *
	 * @return HoursMigrationRunner The runner.
	 */
	private function directRunner(): HoursMigrationRunner {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new HoursMigrationRunner(
			$session,
			$this->createMock(IGroupManager::class),
			$this->createMock(IJobList::class),
			new NullLogger()
		);
	}//end directRunner()

	/**
	 * Build a MigrateHoursProcess over a custom store (loadAll edge cases).
	 *
	 * @param object $store The ObjectService double.
	 *
	 * @return MigrateHoursProcess The subject.
	 */
	private function repairWith(object $store): MigrateHoursProcess {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$container = new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $store]);

		return new MigrateHoursProcess(
			container: $container,
			marker: $this->marker,
			orgResolution: new OrgResolutionService(),
			aggregationService: new TimesheetAggregationService(
				container: $container,
				marker: $this->marker,
				settingsService: $settings
			),
			settingsService: $settings,
			logger: new NullLogger()
		);
	}//end repairWith()

	/**
	 * One pass over the three seed rows: N=3, M=3, K=2 — jansen resolves,
	 * devries/bakker stay null; each timesheet gains exactly one migration
	 * entry anchored at the period start; aggregates equal the legacy hours.
	 *
	 * @return void
	 */
	public function testSeedShapedRowsMigrate(): void {
		$summary = $this->repair->migrate();

		$this->assertSame(3, $summary['processed']);
		$this->assertSame(3, $summary['entriesCreated']);
		$this->assertSame(2, $summary['unresolvableUserLinks'], 'devries + bakker have no account link — counted, not logged.');

		// jansen: link resolves, one synthetic entry at the month start.
		$jansen = $this->store->state->objects['Timesheet']['timesheet-jansen-2026-05'];
		$this->assertSame('admin', $jansen['userId']);
		$this->assertSame(152.0, $jansen['hours'], 'The recomputed aggregate equals the legacy total.');
		$this->assertSame(1, $jansen['entryCount']);

		$entries = array_values(
			array_filter(
				$this->store->state->objects['TimeEntry'] ?? [],
				static fn (array $e): bool => ($e['timesheetId'] ?? '') === 'timesheet-jansen-2026-05'
			)
		);
		$this->assertCount(1, $entries);
		$this->assertSame('migration', $entries[0]['origin']);
		$this->assertSame('2026-05-01T00:00:00Z', $entries[0]['startedAt'], 'Month grain: first day at 00:00 UTC.');
		$this->assertSame('project-alpha', $entries[0]['projectId'], 'Legacy allocation copied down.');
		$this->assertSame(152.0, $entries[0]['hours']);

		// devries/bakker: fail-closed null links.
		$this->assertNull($this->store->state->objects['Timesheet']['timesheet-devries-2026-05']['userId'] ?? null);
		$this->assertNull($this->store->state->objects['Timesheet']['timesheet-bakker-2026-05']['userId'] ?? null);
	}//end testSeedShapedRowsMigrate()

	/**
	 * A timesheet that already HAS entries gains no synthetic one.
	 *
	 * @return void
	 */
	public function testNoSynthesisWhenEntriesExist(): void {
		$this->store->seed('TimeEntry', 'entry-jansen', [
			'timesheetId' => 'timesheet-jansen-2026-05',
			'hours' => 152.0,
		]);

		$summary = $this->repair->migrate();

		$this->assertSame(2, $summary['entriesCreated'], 'Only devries and bakker synthesize — jansen already has an entry.');
		$jansenEntries = array_filter(
			$this->store->state->objects['TimeEntry'],
			static fn (array $e): bool => ($e['timesheetId'] ?? '') === 'timesheet-jansen-2026-05'
		);
		$this->assertCount(1, $jansenEntries, 'The pre-existing entry stands alone.');
	}//end testNoSynthesisWhenEntriesExist()

	/**
	 * Run TWICE: the second run reports M=0 and performs ZERO new writes —
	 * idempotency asserted, not assumed.
	 *
	 * @return void
	 */
	public function testSecondRunCreatesNothingAndWritesNothing(): void {
		$first = $this->repair->migrate();
		$this->assertSame(3, $first['entriesCreated']);
		$savesAfterFirst = count($this->store->state->saves);

		$second = $this->repair->migrate();

		$this->assertSame(0, $second['entriesCreated'], 'M must be 0 on a re-run.');
		$this->assertSame(3, $second['processed']);
		$this->assertSame(2, $second['unresolvableUserLinks'], 'K stays a count, not an accumulating log.');
		$this->assertCount($savesAfterFirst, $this->store->state->saves, 'Zero additional writes on the re-run.');
	}//end testSecondRunCreatesNothingAndWritesNothing()

	/**
	 * Approved rows get their entry too — every migration write runs under
	 * the internal-writer marker (the path that exempts it from the
	 * mutability guard), and the marker never leaks.
	 *
	 * @return void
	 */
	public function testApprovedRowsSynthesizeViaTheMarkerPath(): void {
		$this->repair->migrate();

		$devriesEntries = array_values(
			array_filter(
				$this->store->state->objects['TimeEntry'],
				static fn (array $e): bool => ($e['timesheetId'] ?? '') === 'timesheet-devries-2026-05'
			)
		);
		$this->assertCount(1, $devriesEntries, 'The APPROVED row still gains its migration entry.');
		$this->assertSame('migration', $devriesEntries[0]['origin']);

		$this->assertNotEmpty($this->recorder->markerStates);
		$this->assertNotContains(false, $this->recorder->markerStates, 'EVERY migration write must run under the marker.');
		$this->assertFalse($this->marker->isInternal(), 'The marker must not leak past the run.');
	}//end testApprovedRowsSynthesizeViaTheMarkerPath()

	/**
	 * The IRepairStep display name names the step.
	 *
	 * @return void
	 */
	public function testGetNameNamesTheStep(): void {
		$this->assertStringContainsString('hours-process', $this->repair->getName());
	}//end testGetNameNamesTheStep()

	/**
	 * run() executes the pass through the runner and reports exactly ONE
	 * summary line — the warn-once semantics — through the repair output.
	 *
	 * @return void
	 */
	public function testRunReportsTheSummaryThroughOutput(): void {
		$this->container->set(HoursMigrationRunner::class, $this->directRunner());

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with($this->logicalAnd(
				$this->stringContains('3 timesheets processed'),
				$this->stringContains('3 entries created'),
				$this->stringContains('2 rows with unresolvable user link')
			));
		$output->expects($this->never())->method('warning');

		$this->repair->run($output);
	}//end testRunReportsTheSummaryThroughOutput()

	/**
	 * runDeferred() (the background-job completion vehicle) runs the SAME
	 * idempotent pass and returns the same summary shape.
	 *
	 * @return void
	 */
	public function testRunDeferredRunsTheSamePass(): void {
		$this->container->set(HoursMigrationRunner::class, $this->directRunner());

		$summary = $this->repair->runDeferred();

		$this->assertSame(3, $summary['processed']);
		$this->assertSame(3, $summary['entriesCreated']);
		$this->assertSame(2, $summary['unresolvableUserLinks']);
	}//end testRunDeferredRunsTheSamePass()

	/**
	 * A pass failing on anything but a maintenance-mode folder denial is
	 * reported as a warning through the output — never rethrown out of a
	 * repair step.
	 *
	 * @return void
	 */
	public function testRunWarnsWhenThePassFails(): void {
		$runner = $this->createMock(HoursMigrationRunner::class);
		$runner->method('runAsActingUser')->willThrowException(new \RuntimeException('register unavailable'));
		$runner->method('deferIfMaintenanceDenied')->willReturn(false);
		$this->container->set(HoursMigrationRunner::class, $runner);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');
		$output->expects($this->once())
			->method('warning')
			->with($this->stringContains('register unavailable'));

		$this->repair->run($output);
	}//end testRunWarnsWhenThePassFails()

	/**
	 * A maintenance-mode folder denial defers to the one-shot background
	 * job: the run reports the deferral as INFO (not a failure) and stops.
	 *
	 * @return void
	 */
	public function testRunDefersOnMaintenanceDenied(): void {
		$runner = $this->createMock(HoursMigrationRunner::class);
		$runner->method('runAsActingUser')->willThrowException(new \RuntimeException('folders unreachable'));
		$runner->method('deferIfMaintenanceDenied')->willReturn(true);
		$this->container->set(HoursMigrationRunner::class, $runner);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with($this->stringContains('deferred to a background job'));
		$output->expects($this->never())->method('warning');

		$this->repair->run($output);
	}//end testRunDefersOnMaintenanceDenied()

	/**
	 * An unparseable period cannot anchor a synthetic booking: no entry is
	 * created for it, while the parseable rows still synthesize theirs.
	 *
	 * @return void
	 */
	public function testUnparseablePeriodSynthesizesNothing(): void {
		$this->store->seed('Timesheet', 'timesheet-legacy-q2', [
			'employeeId' => 'employee-jansen',
			'period' => 'Q2-2026',
			'hours' => 10,
			'status' => 'draft',
		]);

		$summary = $this->repair->migrate();

		$this->assertSame(4, $summary['processed']);
		$this->assertSame(3, $summary['entriesCreated'], 'Only the three parseable periods synthesize.');
		$legacyEntries = array_filter(
			($this->store->state->objects['TimeEntry'] ?? []),
			static fn (array $e): bool => ($e['timesheetId'] ?? '') === 'timesheet-legacy-q2'
		);
		$this->assertCount(0, $legacyEntries);
	}//end testUnparseablePeriodSynthesizesNothing()

	/**
	 * The synthetic entry carries the parent timesheet's employee link
	 * VERBATIM, so a uuid-shaped reference survives the copy unchanged —
	 * `TimeEntry.employeeId` is `$ref: Employee` + `format: uuid`, and a
	 * mangled or truncated value would fail that format on the migration
	 * write.
	 *
	 * @return void
	 */
	public function testSynthesizedEntryCarriesTheUuidEmployeeLinkVerbatim(): void {
		$uuid = '2f8b1c44-6d3e-4a17-9f02-5c7ab9e10d33';
		$this->store->seed('Employee', $uuid, ['nextcloudUserId' => 'sanne', 'administrationId' => 'ADM-001']);
		$this->store->seed('Timesheet', 'timesheet-uuid-link', [
			'employeeId' => $uuid,
			'period' => '2026-04',
			'hours' => 8,
			'status' => 'draft',
		]);

		$this->repair->migrate();

		$entry = $this->entryFor('timesheet-uuid-link');
		$this->assertSame($uuid, ($entry['employeeId'] ?? null), 'The uuid reference is copied through untouched.');
		$this->assertSame('timesheet-uuid-link', $entry['timesheetId'], 'The parent link is the timesheet id itself.');
	}//end testSynthesizedEntryCarriesTheUuidEmployeeLinkVerbatim()

	/**
	 * A legacy timesheet with no employee link contributes NO `employeeId`
	 * key at all, rather than an empty string. `employeeId` is deliberately
	 * absent from TimeEntry's `required` list, so omitting it is legal —
	 * while `""` would violate the property's `format: uuid` and fail the
	 * whole migration write.
	 *
	 * @return void
	 */
	public function testSynthesizedEntryOmitsAnUnlinkedEmployeeRatherThanWritingEmptyString(): void {
		$this->store->seed('Timesheet', 'timesheet-unlinked', [
			'employeeId' => '',
			'period' => '2026-04',
			'hours' => 8,
			'status' => 'draft',
		]);

		$this->repair->migrate();

		$entry = $this->entryFor('timesheet-unlinked');
		$this->assertArrayNotHasKey('employeeId', $entry, 'No key beats an unvalidatable empty string.');
	}//end testSynthesizedEntryOmitsAnUnlinkedEmployeeRatherThanWritingEmptyString()

	/**
	 * The single synthesized TimeEntry whose `timesheetId` is the given id.
	 *
	 * @param string $timesheetId The parent timesheet id.
	 *
	 * @return array<string, mixed> The entry payload.
	 */
	private function entryFor(string $timesheetId): array {
		$matches = array_values(
			array_filter(
				($this->store->state->objects['TimeEntry'] ?? []),
				static fn (array $e): bool => ($e['timesheetId'] ?? '') === $timesheetId
			)
		);
		$this->assertCount(1, $matches, 'Exactly one migration entry per legacy timesheet.');

		return $matches[0];
	}//end entryFor()

	/**
	 * Week-grain periods (`YYYY-Www`, `YYYY-Www-D`) anchor the synthetic
	 * entry at the ISO week start / week day at 00:00 UTC — the same grain
	 * family the typed event's classifier recognises.
	 *
	 * @return void
	 */
	public function testWeekGrainPeriodsAnchorAtIsoWeekStart(): void {
		$this->store->seed('Timesheet', 'timesheet-week', [
			'employeeId' => 'employee-jansen',
			'period' => '2026-W23',
			'hours' => 8,
			'status' => 'draft',
		]);
		$this->store->seed('Timesheet', 'timesheet-week-day', [
			'employeeId' => 'employee-jansen',
			'period' => '2026-W23-3',
			'hours' => 4,
			'status' => 'draft',
		]);

		$this->repair->migrate();

		$byParent = [];
		foreach (($this->store->state->objects['TimeEntry'] ?? []) as $entry) {
			$byParent[(string)($entry['timesheetId'] ?? '')] = $entry;
		}

		$this->assertSame('2026-06-01T00:00:00Z', $byParent['timesheet-week']['startedAt'], 'ISO week 23 of 2026 starts Monday June 1.');
		$this->assertSame('2026-06-03T00:00:00Z', $byParent['timesheet-week-day']['startedAt'], 'Day 3 of ISO week 23 is Wednesday June 3.');
	}//end testWeekGrainPeriodsAnchorAtIsoWeekStart()

	/**
	 * A resolvable org chain backfills `managerUserId` onto the timesheet —
	 * through the SAME OrgResolutionService chain the runtime stamp uses.
	 *
	 * @return void
	 */
	public function testManagerChainBackfillsManagerUserId(): void {
		$this->store->seed('Employee', 'employee-manager', ['nextcloudUserId' => 'manager1']);
		$this->store->seed('OrgAssignment', 'assignment-jansen', [
			'employeeId' => 'employee-jansen',
			'orgUnitId' => 'unit-dev',
			'endDate' => '',
		]);
		$this->store->seed('OrgAssignment', 'assignment-dangling', [
			'employeeId' => '',
			'orgUnitId' => 'unit-dev',
		]);
		$this->store->seed('OrgUnit', 'unit-dev', ['managerId' => 'employee-manager']);

		$this->repair->migrate();

		$jansen = $this->store->state->objects['Timesheet']['timesheet-jansen-2026-05'];
		$this->assertSame('manager1', $jansen['managerUserId']);
	}//end testManagerChainBackfillsManagerUserId()

	/**
	 * A schema whose load fails is degraded to an empty set (logged, never
	 * fatal), and rows arriving as jsonSerializable OBJECTS (the real
	 * ObjectService shape) are unwrapped while garbage rows are dropped.
	 *
	 * @return void
	 */
	public function testLoadAllDegradesFailuresAndUnwrapsObjectRows(): void {
		$store = new class extends FakeObjectStore {

			/**
			 * The schema selected via setSchema (parent keeps it private).
			 *
			 * @var string
			 */
			private string $schemaSeen = '';

			/**
			 * {@inheritDoc}
			 */
			public function setSchema(mixed $schema): self {
				$this->schemaSeen = (string)$schema;

				return parent::setSchema($schema);
			}//end setSchema()

			/**
			 * {@inheritDoc}
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->schemaSeen === 'OrgUnit') {
					throw new \RuntimeException('OrgUnit collection unavailable');
				}

				if ($this->schemaSeen === 'Employee') {
					// The real ObjectService returns entities, not arrays.
					return [
						new class implements \JsonSerializable {

							/**
							 * @return array<string, mixed>
							 */
							public function jsonSerialize(): array {
								return [
									'id' => 'employee-jansen',
									'nextcloudUserId' => 'admin',
									'administrationId' => 'ADM-001',
								];
							}//end jsonSerialize()

						},
						42,
					];
				}

				return parent::findAll($config, $_rbac, $_multitenancy);
			}//end findAll()

		};
		$store->seed('Timesheet', 'timesheet-jansen-2026-05', [
			'employeeId' => 'employee-jansen',
			'period' => '2026-05',
			'hours' => 152,
			'status' => 'submitted',
		]);

		$summary = $this->repairWith($store)->migrate();

		$this->assertSame(1, $summary['processed'], 'The failing OrgUnit load degrades to [] and the pass completes.');
		$this->assertSame(0, $summary['unresolvableUserLinks'], 'The object-shaped Employee row was unwrapped and resolves the link.');
		$this->assertSame('admin', $store->state->objects['Timesheet']['timesheet-jansen-2026-05']['userId']);
	}//end testLoadAllDegradesFailuresAndUnwrapsObjectRows()

}//end class
