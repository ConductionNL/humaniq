<?php

/**
 * TimesheetEntryStateListener unit tests
 *
 * The post-save carry-down of a timesheet's status edge to its entries
 * (time-entry-approval-state D3): submit, approve (with approvedAt), reject
 * and reopen each reach every entry; an update that keeps the status writes
 * nothing; foreign schemas and events are ignored; a failure never escapes;
 * and the aggregate recompute does not run for the entry saves.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
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
 * @spec openspec/changes/time-entry-approval-state/specs/time-entry-approval-state/spec.md#Requirement:-A-time-entry-SHALL-carry-the-approval-state-of-its-timesheet-(REQ-TAS-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\TimesheetAggregateListener;
use OCA\Humaniq\Listener\TimesheetEntryStateListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\TimeEntryApprovalStamper;
use OCA\Humaniq\Service\TimesheetAggregationService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for the timesheet-edge carry-down listener.
 */
class TimesheetEntryStateListenerTest extends TestCase {

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
	 * The shared register plumbing.
	 *
	 * @var HoursRegisterGateway
	 */
	private HoursRegisterGateway $gateway;

	/**
	 * The subject.
	 *
	 * @var TimesheetEntryStateListener
	 */
	private TimesheetEntryStateListener $listener;

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
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$this->gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);

		$this->listener = new TimesheetEntryStateListener(
			stamper: new TimeEntryApprovalStamper(gateway: $this->gateway, marker: $this->marker, logger: new NullLogger()),
			gateway: $this->gateway,
			logger: new NullLogger()
		);

		$this->store->seed('Timesheet', 'ts-visser', ['employeeId' => 'employee-visser', 'period' => '2026-09', 'status' => 'draft', 'hours' => 14.5]);
		foreach (['e1' => 8.0, 'e2' => 4.5, 'e3' => 2.0] as $id => $hours) {
			$this->store->seed('TimeEntry', $id, ['timesheetId' => 'ts-visser', 'hours' => $hours, 'approvalState' => 'draft', 'approvedAt' => null]);
		}
	}//end setUp()

	/**
	 * A Timesheet entity with the given payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function timesheet(array $payload, string $schema = 'Timesheet'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ts-visser');
		$entity->setSchema($schema);
		$entity->setObject($payload);

		return $entity;
	}//end timesheet()

	/**
	 * Move the timesheet from one status to another through the listener.
	 *
	 * @param string $from The old status.
	 * @param array<string, mixed> $to The new payload.
	 *
	 * @return void
	 */
	private function edge(string $from, array $to): void {
		$this->listener->handle(new ObjectUpdatedEvent($this->timesheet($to), $this->timesheet(['status' => $from])));
	}//end edge()

	/**
	 * The approval state of each Visser entry.
	 *
	 * @return array<string, array{0: mixed, 1: mixed}> State and approvedAt per entry.
	 */
	private function entryStates(): array {
		$states = [];
		foreach (['e1', 'e2', 'e3'] as $id) {
			$entry = $this->store->state->objects['TimeEntry'][$id];
			$states[$id] = [$entry['approvalState'], $entry['approvedAt']];
		}

		return $states;
	}//end entryStates()

	/**
	 * Submit, approve, reject and reopen each reach every entry; approvedAt
	 * only on approve.
	 *
	 * @return void
	 */
	public function testEveryStatusEdgeReachesTheEntries(): void {
		$this->edge('draft', ['status' => 'submitted']);
		$this->assertSame(array_fill_keys(['e1', 'e2', 'e3'], ['submitted', null]), $this->entryStates(), 'submit');

		$this->edge('submitted', ['status' => 'approved', 'approvedAt' => '2026-10-01T09:00:00Z']);
		$this->assertSame(array_fill_keys(['e1', 'e2', 'e3'], ['approved', '2026-10-01T09:00:00Z']), $this->entryStates(), 'approve');

		$this->edge('approved', ['status' => 'draft', 'approvedAt' => null]);
		$this->assertSame(array_fill_keys(['e1', 'e2', 'e3'], ['draft', null]), $this->entryStates(), 'reopen');

		$this->edge('draft', ['status' => 'submitted']);
		$this->edge('submitted', ['status' => 'rejected', 'approvedAt' => '2026-10-02T09:00:00Z']);
		$this->assertSame(array_fill_keys(['e1', 'e2', 'e3'], ['rejected', null]), $this->entryStates(), 'reject');
	}//end testEveryStatusEdgeReachesTheEntries()

	/**
	 * An update that keeps the status (the aggregate recompute, an edit of a
	 * draft) writes nothing.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutAStatusChangeWritesNothing(): void {
		$this->listener->handle(new ObjectUpdatedEvent(
			$this->timesheet(['status' => 'Draft', 'hours' => 16.5]),
			$this->timesheet(['status' => 'draft', 'hours' => 14.5])
		));

		$this->assertSame([], $this->store->state->saves);
	}//end testAnUpdateWithoutAStatusChangeWritesNothing()

	/**
	 * Another schema's update and a create event are ignored.
	 *
	 * @return void
	 */
	public function testForeignSchemasAndEventsAreIgnored(): void {
		$this->listener->handle(new ObjectUpdatedEvent(
			$this->timesheet(['status' => 'approved'], 'Expense'),
			$this->timesheet(['status' => 'submitted'], 'Expense')
		));
		$this->listener->handle(new ObjectCreatedEvent($this->timesheet(['status' => 'approved'])));

		$this->assertSame([], $this->store->state->saves);
	}//end testForeignSchemasAndEventsAreIgnored()

	/**
	 * A failure is logged and never escapes the timesheet transition.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndSwallowed(): void {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('resolveSchemaSlug')->willReturn('Timesheet');
		$gateway->method('findFiltered')->willThrowException(new \RuntimeException('register down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('could not carry'), ['exception' => 'register down']);

		$listener = new TimesheetEntryStateListener(
			stamper: new TimeEntryApprovalStamper(gateway: $gateway, marker: $this->marker, logger: new NullLogger()),
			gateway: $gateway,
			logger: $logger
		);
		$listener->handle(new ObjectUpdatedEvent($this->timesheet(['status' => 'approved']), $this->timesheet(['status' => 'submitted'])));

		$this->assertFalse($this->marker->isInternal());
	}//end testAFailureIsLoggedAndSwallowed()

	/**
	 * The entry saves run as internal writes, so the aggregate listener,
	 * handed the same post-save event inside them, recomputes nothing.
	 *
	 * @return void
	 */
	public function testTheAggregateListenerDoesNotRecomputeForTheEntrySaves(): void {
		$aggregation = $this->createMock(TimesheetAggregationService::class);
		$aggregation->expects($this->never())->method('recomputeForTimesheet');
		$aggregate = new TimesheetAggregateListener(
			aggregationService: $aggregation,
			marker: $this->marker,
			gateway: $this->gateway,
			logger: new NullLogger()
		);

		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('resolveSchemaSlug')->willReturnArgument(0);
		$gateway->method('findFiltered')->willReturn([['id' => 'e1', 'timesheetId' => 'ts-visser', 'approvalState' => 'submitted']]);
		$gateway->expects($this->once())->method('save')->willReturnCallback(
			function (array $payload, string $schema, ?string $uuid) use ($aggregate): ObjectEntity {
				$saved = new ObjectEntity();
				$saved->setUuid((string)$uuid);
				$saved->setSchema($schema);
				$saved->setObject($payload);
				// OpenRegister dispatches the entry's post-save event inside the save.
				$aggregate->handle(new ObjectUpdatedEvent($saved, $saved));
				return $saved;
			}
		);

		$listener = new TimesheetEntryStateListener(
			stamper: new TimeEntryApprovalStamper(gateway: $gateway, marker: $this->marker, logger: new NullLogger()),
			gateway: $gateway,
			logger: new NullLogger()
		);
		$listener->handle(new ObjectUpdatedEvent(
			$this->timesheet(['status' => 'approved', 'approvedAt' => '2026-10-01T09:00:00Z']),
			$this->timesheet(['status' => 'submitted'])
		));
	}//end testTheAggregateListenerDoesNotRecomputeForTheEntrySaves()

}//end class
