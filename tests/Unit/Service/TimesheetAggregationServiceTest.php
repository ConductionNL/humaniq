<?php

/**
 * TimesheetAggregationService unit tests
 *
 * The shared recompute (hours-process-redesign Decision 3, REQ-TEC-004):
 * asserted on the recomputed VALUES — sum, homogeneous-vs-null, all-billable
 * — not merely on "a write occurred" (a flat total is necessary, not
 * sufficient). Plus the write-skipping idempotency and the marker scope of
 * the persisting write.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Service
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
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\InternalWriteMarker;
use OCA\Hrmq\Service\SettingsService;
use OCA\Hrmq\Service\TimesheetAggregationService;
use OCA\Hrmq\Tests\Unit\Support\FakeContainer;
use OCA\Hrmq\Tests\Unit\Support\FakeObjectStore;
use PHPUnit\Framework\TestCase;

/**
 * Value-level assertions on the shared aggregate recompute.
 */
class TimesheetAggregationServiceTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The internal-writer marker handed to the service.
	 *
	 * @var InternalWriteMarker
	 */
	private InternalWriteMarker $marker;

	/**
	 * The subject.
	 *
	 * @var TimesheetAggregationService
	 */
	private TimesheetAggregationService $service;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->marker = new InternalWriteMarker();

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');

		$this->service = new TimesheetAggregationService(
			container: new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $this->store]),
			marker: $this->marker,
			settingsService: $settings
		);
	}//end setUp()

	/**
	 * Homogeneous entries: exact sum, shared project/cost centre, all
	 * billable.
	 *
	 * @return void
	 */
	public function testComputesHomogeneousAggregates(): void {
		$aggregates = $this->service->computeAggregates([
			['hours' => 8.0, 'projectId' => 'project-alpha', 'costCenter' => 'CC-100', 'billable' => true],
			['hours' => 4.25, 'projectId' => 'project-alpha', 'costCenter' => 'CC-100', 'billable' => true],
		]);

		$this->assertSame(12.25, $aggregates['hours']);
		$this->assertSame(2, $aggregates['entryCount']);
		$this->assertSame('project-alpha', $aggregates['projectId']);
		$this->assertSame('CC-100', $aggregates['costCenter']);
		$this->assertTrue($aggregates['billable']);
	}//end testComputesHomogeneousAggregates()

	/**
	 * Heterogeneous entries: the sum stays exact, the allocation fields go
	 * null (never a guess), and one non-billable entry flips billable off
	 * (REQ-TEC-003 semantics).
	 *
	 * @return void
	 */
	public function testMixedEntriesYieldNullAllocationsNotAGuess(): void {
		$aggregates = $this->service->computeAggregates([
			['hours' => 8, 'projectId' => 'project-alpha', 'costCenter' => 'CC-100', 'billable' => true],
			['hours' => 4, 'projectId' => 'project-beta', 'costCenter' => 'CC-100', 'billable' => false],
		]);

		$this->assertSame(12.0, $aggregates['hours']);
		$this->assertNull($aggregates['projectId'], 'Two projects must aggregate to null, never to either one.');
		$this->assertSame('CC-100', $aggregates['costCenter'], 'The cost centre IS shared and survives.');
		$this->assertFalse($aggregates['billable'], 'billable is all-entries-billable.');
	}//end testMixedEntriesYieldNullAllocationsNotAGuess()

	/**
	 * Zero entries: zeroed totals, null allocations, not billable.
	 *
	 * @return void
	 */
	public function testEmptyEntrySetZeroesTheAggregates(): void {
		$aggregates = $this->service->computeAggregates([]);

		$this->assertSame(0.0, $aggregates['hours']);
		$this->assertSame(0, $aggregates['entryCount']);
		$this->assertNull($aggregates['projectId']);
		$this->assertNull($aggregates['costCenter']);
		$this->assertFalse($aggregates['billable']);
	}//end testEmptyEntrySetZeroesTheAggregates()

	/**
	 * recomputeForTimesheet persists the values onto the stored timesheet,
	 * under the internal-writer marker; a second run over unchanged entries
	 * performs ZERO additional writes (idempotency asserted, not assumed).
	 *
	 * @return void
	 */
	public function testRecomputePersistsOnceAndIsIdempotent(): void {
		$this->store->seed('Timesheet', 'ts-1', [
			'employeeId' => 'employee-jansen',
			'period' => '2026-05',
			'status' => 'draft',
			'hours' => 0,
			'entryCount' => 0,
		]);
		$this->store->seed('TimeEntry', 'entry-1', ['timesheetId' => 'ts-1', 'hours' => 8.0, 'projectId' => 'p', 'billable' => true]);
		$this->store->seed('TimeEntry', 'entry-2', ['timesheetId' => 'ts-1', 'hours' => 4.0, 'projectId' => 'p', 'billable' => true]);
		$this->store->seed('TimeEntry', 'entry-other', ['timesheetId' => 'ts-2', 'hours' => 99.0]);

		$aggregates = $this->service->recomputeForTimesheet('ts-1');
		$this->assertNotNull($aggregates);
		$this->assertSame(12.0, $aggregates['hours'], 'Entries of OTHER timesheets must not leak into the sum.');

		$persisted = $this->store->state->objects['Timesheet']['ts-1'];
		$this->assertSame(12.0, $persisted['hours']);
		$this->assertSame(2, $persisted['entryCount']);
		$this->assertSame('p', $persisted['projectId']);
		$this->assertTrue($persisted['billable']);
		$this->assertSame('draft', $persisted['status'], 'A recompute never touches the process.');

		$savesAfterFirstRun = count($this->store->state->saves);
		$this->assertGreaterThan(0, $savesAfterFirstRun);

		// Second run: same entries, same values — zero new writes.
		$this->service->recomputeForTimesheet('ts-1');
		$this->assertCount($savesAfterFirstRun, $this->store->state->saves, 'A re-run over unchanged entries must not write.');

		// The marker did not leak out of the recompute.
		$this->assertFalse($this->marker->isInternal());
	}//end testRecomputePersistsOnceAndIsIdempotent()

	/**
	 * The persisting write runs INSIDE the marker scope — proven with a
	 * store that records the marker state at save time.
	 *
	 * @return void
	 */
	public function testPersistingWriteRunsUnderTheMarker(): void {
		$this->store->seed('Timesheet', 'ts-1', ['hours' => 0, 'entryCount' => 0, 'status' => 'draft']);
		$this->store->seed('TimeEntry', 'entry-1', ['timesheetId' => 'ts-1', 'hours' => 8.0]);

		// Recorder is a SHARED OBJECT (like FakeObjectStore::$state): the
		// production code clones the store, and a plain array property would
		// record onto the clone, invisibly to this test.
		$recorder = new class {

			/**
			 * @var array<int, bool>
			 */
			public array $markerStates = [];
		};
		$spy = new class ($this->store, $this->marker, $recorder) extends FakeObjectStore {

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

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		$service = new TimesheetAggregationService(
			container: new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $spy]),
			marker: $this->marker,
			settingsService: $settings
		);

		$service->recomputeForTimesheet('ts-1');

		$this->assertSame([true], $recorder->markerStates, 'The aggregate write must run inside the marker scope.');
		$this->assertFalse($this->marker->isInternal(), 'And the marker must be reset afterwards.');
	}//end testPersistingWriteRunsUnderTheMarker()

	/**
	 * A vanished timesheet recomputes nothing and writes nothing.
	 *
	 * @return void
	 */
	public function testMissingTimesheetIsANoOp(): void {
		$this->assertNull($this->service->recomputeForTimesheet('ts-gone'));
		$this->assertNull($this->service->recomputeForTimesheet(''));
		$this->assertCount(0, $this->store->state->saves);
	}//end testMissingTimesheetIsANoOp()

	/**
	 * A THROWING timesheet load degrades to the missing-timesheet no-op —
	 * infrastructure trouble during a post-save recompute must never bubble
	 * back into the save path that triggered it.
	 *
	 * @return void
	 */
	public function testThrowingTimesheetLoadIsANoOp(): void {
		$store = new class extends FakeObjectStore {

			/**
			 * {@inheritDoc}
			 */
			public function find(
				int | string $id,
				?array $_extend = [],
				bool $files = false,
				mixed $register = null,
				mixed $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): ?\OCA\OpenRegister\Db\ObjectEntity {
				throw new \RuntimeException('register down');
			}//end find()

		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		$service = new TimesheetAggregationService(
			container: new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $store]),
			marker: $this->marker,
			settingsService: $settings
		);

		$this->assertNull($service->recomputeForTimesheet('ts-1'));
		$this->assertCount(0, $store->state->saves);
	}//end testThrowingTimesheetLoadIsANoOp()

	/**
	 * Entry rows arriving as jsonSerializable OBJECTS (the real
	 * ObjectService shape) are unwrapped and garbage rows are dropped
	 * before aggregation.
	 *
	 * @return void
	 */
	public function testObjectShapedEntryRowsAreUnwrapped(): void {
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
				if ($this->schemaSeen !== 'TimeEntry') {
					return parent::findAll($config, $_rbac, $_multitenancy);
				}

				return [
					new class implements \JsonSerializable {

						/**
						 * @return array<string, mixed>
						 */
						public function jsonSerialize(): array {
							return ['timesheetId' => 'ts-1', 'hours' => 5.5, 'billable' => true];
						}//end jsonSerialize()

					},
					17,
				];
			}//end findAll()

		};
		$store->seed('Timesheet', 'ts-1', ['status' => 'draft', 'hours' => 0, 'entryCount' => 0]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		$service = new TimesheetAggregationService(
			container: new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $store]),
			marker: $this->marker,
			settingsService: $settings
		);

		$aggregates = $service->recomputeForTimesheet('ts-1');

		$this->assertSame(5.5, $aggregates['hours'], 'The object row was unwrapped; the garbage row was dropped.');
		$this->assertSame(1, $aggregates['entryCount']);
	}//end testObjectShapedEntryRowsAreUnwrapped()

}//end class
