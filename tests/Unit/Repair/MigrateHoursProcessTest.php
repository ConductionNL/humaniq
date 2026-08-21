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
 * @package  OCA\Hrmq\Tests\Unit\Repair
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
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Repair;

use OCA\Hrmq\Repair\MigrateHoursProcess;
use OCA\Hrmq\Service\InternalWriteMarker;
use OCA\Hrmq\Service\OrgResolutionService;
use OCA\Hrmq\Service\SettingsService;
use OCA\Hrmq\Service\TimesheetAggregationService;
use OCA\Hrmq\Tests\Unit\Support\FakeContainer;
use OCA\Hrmq\Tests\Unit\Support\FakeObjectStore;
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
		$this->spy = new class ($this->store, $this->marker, $this->recorder) extends FakeObjectStore {

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
				array | object $object,
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
		$settings->method('getRegisterSlug')->willReturn('hrmq');

		$container = new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $this->spy]);
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

}//end class
