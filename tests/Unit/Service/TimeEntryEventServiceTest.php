<?php

/**
 * Unit tests for TimeEntryEventService.
 *
 * Pins the emit contract of the time-entry-capture approval event: a Timesheet
 * crossing into `approved` emits exactly one `nl.conduction.hrmq.timeentry.approved`
 * CloudEvent carrying the approved hours / project / billable a finance consumer
 * needs; an unapproved change, a non-approval transition, a re-save of an
 * already-approved timesheet, and a non-Timesheet schema all stay silent.
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
 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\TimeEntryEventService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for TimeEntryEventService.
 *
 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md
 */
class TimeEntryEventServiceTest extends TestCase {

	/**
	 * A spy that records every CloudEvent dispatched through the fake
	 * WebhookService, so a test can assert whether — and with what payload —
	 * the service emitted.
	 *
	 * @var object{calls: array<int, array{eventName: string, payload: array<string, mixed>}>}
	 */
	private object $spy;

	/**
	 * Build a service whose lazily-resolved WebhookService is a recording spy.
	 *
	 * @return TimeEntryEventService
	 */
	private function serviceWithSpy(): TimeEntryEventService {
		$this->spy = new class {

			/**
			 * @var array<int, array{eventName: string, payload: array<string, mixed>}>
			 */
			public array $calls = [];

			/**
			 * Record a dispatched CloudEvent.
			 *
			 * @param object $_event The (unused) event object.
			 * @param string $eventName The CloudEvent type.
			 * @param array<string, mixed> $payload The CloudEvent envelope.
			 *
			 * @return void
			 */
			public function dispatchEvent(object $_event, string $eventName, array $payload): void {
				$this->calls[] = ['eventName' => $eventName, 'payload' => $payload];
			}//end dispatchEvent()
		};

		$spy = $this->spy;
		$container = new class($spy) implements ContainerInterface {

			/**
			 * @param object $spy The WebhookService spy.
			 */
			public function __construct(
				private readonly object $spy,
			) {
			}//end __construct()

			/**
			 * @param string $id Service id.
			 *
			 * @return mixed
			 */
			public function get(string $id): mixed {
				if ($id === 'OCA\OpenRegister\Service\WebhookService') {
					return $this->spy;
				}

				throw new \RuntimeException('unexpected service ' . $id);
			}//end get()

			/**
			 * @param string $id Service id.
			 *
			 * @return bool
			 */
			public function has(string $id): bool {
				return $id === 'OCA\OpenRegister\Service\WebhookService';
			}//end has()
		};

		return new TimeEntryEventService(
			container: $container,
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end serviceWithSpy()

	/**
	 * A representative approved Timesheet payload.
	 *
	 * @return array<string, mixed>
	 */
	private function approvedTimesheet(): array {
		return [
			'id' => 'ts-0001',
			'employeeId' => 'emp-devries',
			'period' => '2026-07',
			'hours' => 36.5,
			'billable' => true,
			'projectId' => 'proj-alpha',
			'costCenter' => 'cc-42',
			'clientRef' => 'client-7',
			'description' => 'Sprint work',
			'status' => 'approved',
			'approvedBy' => 'manager-jansen',
			'approvedAt' => '2026-07-15T10:00:00Z',
		];
	}//end approvedTimesheet()

	/**
	 * A submitted→approved transition emits one CloudEvent with the hours,
	 * project and billable flag a finance consumer needs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
	 */
	public function testSubmittedToApprovedEmitsEvent(): void {
		$service = $this->serviceWithSpy();

		$emitted = $service->maybeDispatchApproved(
			schemaSlug: 'Timesheet',
			oldData: ['status' => 'submitted', 'hours' => 36.5],
			newData: $this->approvedTimesheet()
		);

		$this->assertTrue($emitted);
		$this->assertCount(1, $this->spy->calls);

		$call = $this->spy->calls[0];
		$this->assertSame('nl.conduction.hrmq.timeentry.approved', $call['eventName']);
		$this->assertSame('1.0', $call['payload']['specversion']);
		$this->assertSame('/apps/hrmq/timesheets', $call['payload']['source']);
		$this->assertSame('ts-0001', $call['payload']['id']);

		$data = $call['payload']['data'];
		$this->assertSame('ts-0001', $data['timesheetId']);
		$this->assertSame('emp-devries', $data['employeeId']);
		$this->assertSame('2026-07', $data['period']);
		$this->assertSame(36.5, $data['hours']);
		$this->assertTrue($data['billable']);
		$this->assertSame('proj-alpha', $data['projectId']);
		$this->assertSame('cc-42', $data['costCenter']);
		$this->assertSame('manager-jansen', $data['approvedBy']);
		$this->assertSame('2026-07-15T10:00:00Z', $data['approvedAt']);

	}//end testSubmittedToApprovedEmitsEvent()

	/**
	 * An unapproved change (draft→submitted) emits nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
	 */
	public function testUnapprovedTransitionDoesNotEmit(): void {
		$service = $this->serviceWithSpy();

		$emitted = $service->maybeDispatchApproved(
			schemaSlug: 'Timesheet',
			oldData: ['status' => 'draft'],
			newData: ['status' => 'submitted', 'hours' => 8.0, 'employeeId' => 'emp-devries']
		);

		$this->assertFalse($emitted);
		$this->assertCount(0, $this->spy->calls);

	}//end testUnapprovedTransitionDoesNotEmit()

	/**
	 * Re-saving an already-approved timesheet does not re-emit (idempotent edge).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
	 */
	public function testAlreadyApprovedDoesNotReEmit(): void {
		$service = $this->serviceWithSpy();

		$emitted = $service->maybeDispatchApproved(
			schemaSlug: 'Timesheet',
			oldData: ['status' => 'approved'],
			newData: $this->approvedTimesheet()
		);

		$this->assertFalse($emitted);
		$this->assertCount(0, $this->spy->calls);

	}//end testAlreadyApprovedDoesNotReEmit()

	/**
	 * An approval on a non-Timesheet schema does not emit the time-entry event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
	 */
	public function testNonTimesheetSchemaDoesNotEmit(): void {
		$service = $this->serviceWithSpy();

		$emitted = $service->maybeDispatchApproved(
			schemaSlug: 'Employee',
			oldData: ['status' => 'submitted'],
			newData: ['status' => 'approved', 'hours' => 10.0]
		);

		$this->assertFalse($emitted);
		$this->assertCount(0, $this->spy->calls);

	}//end testNonTimesheetSchemaDoesNotEmit()

	/**
	 * isApprovalTransition pins the edge semantics directly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
	 */
	public function testIsApprovalTransitionEdges(): void {
		$service = $this->serviceWithSpy();

		$this->assertTrue($service->isApprovalTransition(['status' => 'submitted'], ['status' => 'approved']));
		$this->assertTrue($service->isApprovalTransition(null, ['status' => 'approved']));
		$this->assertFalse($service->isApprovalTransition(['status' => 'approved'], ['status' => 'approved']));
		$this->assertFalse($service->isApprovalTransition(['status' => 'draft'], ['status' => 'submitted']));
		$this->assertFalse($service->isApprovalTransition(['status' => 'submitted'], ['status' => 'rejected']));

	}//end testIsApprovalTransitionEdges()

	/**
	 * The CloudEvent envelope carries the CloudEvents 1.0 required attributes,
	 * falling back to a generated `time` when approvedAt is absent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-003
	 */
	public function testBuildApprovedEventEnvelope(): void {
		$service = $this->serviceWithSpy();

		$event = $service->buildApprovedEvent(['id' => 'ts-9', 'hours' => 4, 'status' => 'approved']);

		$this->assertSame('1.0', $event['specversion']);
		$this->assertSame('nl.conduction.hrmq.timeentry.approved', $event['type']);
		$this->assertSame('application/json', $event['datacontenttype']);
		$this->assertSame('ts-9', $event['id']);
		$this->assertNotSame('', $event['time']);
		$this->assertSame(4.0, $event['data']['hours']);
		$this->assertFalse($event['data']['billable']);

	}//end testBuildApprovedEventEnvelope()

}//end class
