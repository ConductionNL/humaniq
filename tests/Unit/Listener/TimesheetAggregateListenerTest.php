<?php

/**
 * TimesheetAggregateListener unit tests
 *
 * The post-save recompute trigger (hours-process-redesign Decision 3):
 * create/delete recompute the entry's parent, an update recomputes BOTH
 * parents on a reparent, a Timesheet event triggers nothing (the no-loop
 * property), and internal writes (marker) are skipped. Assertions read the
 * recomputed VALUES off the store, not merely "a recompute ran".
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Listener
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

namespace OCA\Hrmq\Tests\Unit\Listener;

use OCA\Hrmq\Listener\TimesheetAggregateListener;
use OCA\Hrmq\Service\HoursRegisterGateway;
use OCA\Hrmq\Service\InternalWriteMarker;
use OCA\Hrmq\Service\OrgResolutionService;
use OCA\Hrmq\Service\SettingsService;
use OCA\Hrmq\Service\TimesheetAggregationService;
use OCA\Hrmq\Tests\Unit\Support\FakeContainer;
use OCA\Hrmq\Tests\Unit\Support\FakeObjectStore;
use OCA\Hrmq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Recompute triggering, reparenting, loop safety and marker skip.
 */
class TimesheetAggregateListenerTest extends TestCase {

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
	 * @var TimesheetAggregateListener
	 */
	private TimesheetAggregateListener $listener;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->marker = new InternalWriteMarker();

		$this->store->seed('Timesheet', 'ts-a', ['status' => 'draft', 'hours' => 0, 'entryCount' => 0]);
		$this->store->seed('Timesheet', 'ts-b', ['status' => 'draft', 'hours' => 0, 'entryCount' => 0]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');

		$aggregation = new TimesheetAggregationService(
			container: new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $this->store]),
			marker: $this->marker,
			settingsService: $settings
		);

		$this->listener = new TimesheetAggregateListener(
			aggregationService: $aggregation,
			marker: $this->marker,
			gateway: new HoursRegisterGateway(
				container: new FakeContainer(['OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper()]),
				settingsService: $settings,
				orgResolution: new OrgResolutionService()
			),
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * Build a post-save TimeEntry entity.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entryEntity(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema('TimeEntry');
		$entity->setObject($payload);

		return $entity;
	}//end entryEntity()

	/**
	 * A created entry recomputes its parent — asserted on the VALUES.
	 *
	 * @return void
	 */
	public function testCreateRecomputesTheParentValues(): void {
		$this->store->seed('TimeEntry', 'entry-1', ['timesheetId' => 'ts-a', 'hours' => 8.0, 'billable' => true, 'projectId' => 'p']);

		$this->listener->handle(new ObjectCreatedEvent($this->entryEntity(
			$this->store->state->objects['TimeEntry']['entry-1']
		)));

		$parent = $this->store->state->objects['Timesheet']['ts-a'];
		$this->assertSame(8.0, $parent['hours']);
		$this->assertSame(1, $parent['entryCount']);
		$this->assertSame('p', $parent['projectId']);
		$this->assertTrue($parent['billable']);
	}//end testCreateRecomputesTheParentValues()

	/**
	 * A reparented entry recomputes BOTH the old and the new parent.
	 *
	 * @return void
	 */
	public function testReparentRecomputesBothParents(): void {
		// The entry now lives under ts-b; ts-a's stale totals must be
		// recomputed down to zero.
		$this->store->seed('Timesheet', 'ts-a', ['status' => 'draft', 'hours' => 8.0, 'entryCount' => 1]);
		$this->store->seed('TimeEntry', 'entry-1', ['timesheetId' => 'ts-b', 'hours' => 8.0]);

		$old = $this->entryEntity(['timesheetId' => 'ts-a', 'hours' => 8.0]);
		$new = $this->entryEntity(['timesheetId' => 'ts-b', 'hours' => 8.0]);
		$this->listener->handle(new ObjectUpdatedEvent($new, $old));

		$this->assertSame(0.0, $this->store->state->objects['Timesheet']['ts-a']['hours'], 'The OLD parent must be recomputed.');
		$this->assertSame(0, $this->store->state->objects['Timesheet']['ts-a']['entryCount']);
		$this->assertSame(8.0, $this->store->state->objects['Timesheet']['ts-b']['hours'], 'The NEW parent must be recomputed.');
		$this->assertSame(1, $this->store->state->objects['Timesheet']['ts-b']['entryCount']);
	}//end testReparentRecomputesBothParents()

	/**
	 * A deleted entry recomputes its former parent down to the truth.
	 *
	 * @return void
	 */
	public function testDeleteRecomputesTheParent(): void {
		$this->store->seed('Timesheet', 'ts-a', ['status' => 'draft', 'hours' => 8.0, 'entryCount' => 1]);

		// The entry is already gone from the store — the event carries it.
		$this->listener->handle(new ObjectDeletedEvent($this->entryEntity(['timesheetId' => 'ts-a', 'hours' => 8.0])));

		$this->assertSame(0.0, $this->store->state->objects['Timesheet']['ts-a']['hours']);
		$this->assertSame(0, $this->store->state->objects['Timesheet']['ts-a']['entryCount']);
	}//end testDeleteRecomputesTheParent()

	/**
	 * The no-loop property: a Timesheet write triggers NO recompute — the
	 * listener reacts only to timeentry events.
	 *
	 * @return void
	 */
	public function testTimesheetEventsTriggerNothing(): void {
		$entity = new ObjectEntity();
		$entity->setSchema('Timesheet');
		$entity->setObject(['timesheetId' => 'ts-a', 'status' => 'draft']);

		$this->listener->handle(new ObjectUpdatedEvent($entity, null));

		$this->assertCount(0, $this->store->state->saves, 'A Timesheet event must not trigger a recompute (loop safety).');
	}//end testTimesheetEventsTriggerNothing()

	/**
	 * Internal writes (migration synthesis) are skipped — the repair step
	 * recomputes once, directly.
	 *
	 * @return void
	 */
	public function testInternalWritesAreSkipped(): void {
		$this->store->seed('TimeEntry', 'entry-1', ['timesheetId' => 'ts-a', 'hours' => 8.0]);
		$event = new ObjectCreatedEvent($this->entryEntity($this->store->state->objects['TimeEntry']['entry-1']));

		$this->marker->runInternal(function () use ($event): void {
			$this->listener->handle($event);
		});

		$this->assertCount(0, $this->store->state->saves, 'Marker-scoped entry writes must not fan out recomputes.');
		$this->assertSame(0, $this->store->state->objects['Timesheet']['ts-a']['hours']);
	}//end testInternalWritesAreSkipped()

}//end class
