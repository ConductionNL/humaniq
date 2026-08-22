<?php

/**
 * Unit tests for TimeEntryEventService.
 *
 * Pins the emit contract of the time-entry-capture approval event: a Timesheet
 * crossing into `approved` emits exactly one `nl.conduction.hrmq.timeentry.approved`
 * CloudEvent carrying the approved hours / project / billable a finance consumer
 * needs, AND dispatches the typed {@see \OCA\Humaniq\Event\TimesheetApprovedEvent}
 * (ADR-041) with the same provenance plus an explicit period-grain marker; an
 * unapproved change, a non-approval transition, a re-save of an
 * already-approved timesheet, and a non-Timesheet schema all stay silent on
 * BOTH dispatch paths.
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
 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md
 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Event\TimesheetApprovedEvent;
use OCA\Humaniq\Service\TimeEntryEventService;
use OCP\EventDispatcher\IEventDispatcher;
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
	 * Every {@see TimesheetApprovedEvent} passed to `dispatchTyped()`, recorded
	 * by the mocked `IEventDispatcher` `serviceWithSpy()` wires in.
	 *
	 * @var array<int, TimesheetApprovedEvent>
	 */
	private array $typedDispatches = [];

	/**
	 * Whether the mocked `IEventDispatcher::dispatchTyped()` should throw, to
	 * exercise the fail-soft path.
	 *
	 * @var bool
	 */
	private bool $typedDispatchThrows = false;

	/**
	 * Build a service whose lazily-resolved WebhookService is a recording spy,
	 * and whose `IEventDispatcher` is a mock recording every typed dispatch
	 * into {@see $typedDispatches}.
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

		$this->typedDispatches = [];
		$eventDispatcher = $this->createMock(IEventDispatcher::class);
		$eventDispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				if ($this->typedDispatchThrows === true) {
					throw new \RuntimeException('no listener');
				}

				if ($event instanceof TimesheetApprovedEvent) {
					$this->typedDispatches[] = $event;
				}
			}
		);

		return new TimeEntryEventService(
			container: $container,
			eventDispatcher: $eventDispatcher,
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
	 * project and billable flag a finance consumer needs, AND dispatches the
	 * typed {@see TimesheetApprovedEvent} with the same provenance plus a
	 * `month` period-grain marker (period `2026-07`).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
	 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
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
		$this->assertSame('/apps/humaniq/timesheets', $call['payload']['source']);
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

		// The typed cross-app event (ADR-041) is dispatched ADDITIVELY,
		// alongside the webhook above, on the exact same approval edge.
		$this->assertCount(1, $this->typedDispatches);
		$typed = $this->typedDispatches[0];
		$this->assertSame('ts-0001', $typed->getTimesheetId());
		$this->assertSame('emp-devries', $typed->getEmployeeId());
		$this->assertSame('2026-07', $typed->getPeriod());
		$this->assertSame(TimesheetApprovedEvent::GRAIN_MONTH, $typed->getPeriodGrain());
		$this->assertSame(36.5, $typed->getHours());
		$this->assertTrue($typed->isBillable());
		$this->assertSame('proj-alpha', $typed->getProjectId());
		$this->assertSame('cc-42', $typed->getCostCenter());
		$this->assertSame('client-7', $typed->getClientRef());
		$this->assertSame('manager-jansen', $typed->getApprovedBy());
		$this->assertSame('2026-07-15T10:00:00Z', $typed->getApprovedAt());

	}//end testSubmittedToApprovedEmitsEvent()

	/**
	 * An unapproved change (draft→submitted) emits nothing on either dispatch
	 * path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
	 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
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
		$this->assertCount(0, $this->typedDispatches);

	}//end testUnapprovedTransitionDoesNotEmit()

	/**
	 * Re-saving an already-approved timesheet does not re-emit (idempotent
	 * edge), on either dispatch path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
	 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
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
		$this->assertCount(0, $this->typedDispatches);

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
		$this->assertCount(0, $this->typedDispatches);

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

	/**
	 * A typed-dispatch failure (e.g. no listener registered — shillinq not
	 * installed) never blocks the webhook dispatch that follows it — the two
	 * paths are independent, mirroring pipelinq's
	 * `PosTransactionService::emitStockMovedEvent()` fail-soft contract.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function testTypedDispatchFailureDoesNotBlockWebhook(): void {
		$this->typedDispatchThrows = true;
		$service = $this->serviceWithSpy();

		$emitted = $service->maybeDispatchApproved(
			schemaSlug: 'Timesheet',
			oldData: ['status' => 'submitted'],
			newData: $this->approvedTimesheet()
		);

		$this->assertTrue($emitted);
		$this->assertCount(1, $this->spy->calls);
		$this->assertCount(0, $this->typedDispatches);

	}//end testTypedDispatchFailureDoesNotBlockWebhook()

	/**
	 * buildTypedEvent() classifies a `YYYY-MM` period as `month`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-The-typed-event-SHALL-carry-the-raw-period-plus-an-explicit-grain-marker
	 */
	public function testBuildTypedEventClassifiesMonthGrain(): void {
		$service = $this->serviceWithSpy();

		$typed = $service->buildTypedEvent(['id' => 'ts-1', 'period' => '2026-07']);

		$this->assertSame('2026-07', $typed->getPeriod());
		$this->assertSame(TimesheetApprovedEvent::GRAIN_MONTH, $typed->getPeriodGrain());

	}//end testBuildTypedEventClassifiesMonthGrain()

	/**
	 * buildTypedEvent() classifies a `YYYY-Www` period as `week`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-The-typed-event-SHALL-carry-the-raw-period-plus-an-explicit-grain-marker
	 */
	public function testBuildTypedEventClassifiesWeekGrain(): void {
		$service = $this->serviceWithSpy();

		$typed = $service->buildTypedEvent(['id' => 'ts-2', 'period' => '2026-W29']);

		$this->assertSame('2026-W29', $typed->getPeriod());
		$this->assertSame(TimesheetApprovedEvent::GRAIN_WEEK, $typed->getPeriodGrain());

	}//end testBuildTypedEventClassifiesWeekGrain()

	/**
	 * buildTypedEvent() classifies a `YYYY-Wnn-D` period as `day`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-The-typed-event-SHALL-carry-the-raw-period-plus-an-explicit-grain-marker
	 */
	public function testBuildTypedEventClassifiesDayGrain(): void {
		$service = $this->serviceWithSpy();

		$typed = $service->buildTypedEvent(['id' => 'ts-3', 'period' => '2026-W29-3']);

		$this->assertSame('2026-W29-3', $typed->getPeriod());
		$this->assertSame(TimesheetApprovedEvent::GRAIN_DAY, $typed->getPeriodGrain());

	}//end testBuildTypedEventClassifiesDayGrain()

	/**
	 * buildTypedEvent() carries an unrecognised period shape as-is, marked
	 * `unknown` rather than refusing to build the event — the producer never
	 * decides an unrecognised shape is fatal; that is a consumer decision.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-The-typed-event-SHALL-carry-the-raw-period-plus-an-explicit-grain-marker
	 */
	public function testBuildTypedEventClassifiesUnknownGrain(): void {
		$service = $this->serviceWithSpy();

		$typed = $service->buildTypedEvent(['id' => 'ts-4', 'period' => 'Q3-2026']);

		$this->assertSame('Q3-2026', $typed->getPeriod());
		$this->assertSame(TimesheetApprovedEvent::GRAIN_UNKNOWN, $typed->getPeriodGrain());

	}//end testBuildTypedEventClassifiesUnknownGrain()

	/**
	 * buildTypedEvent() carries administrationId when the payload has one, for
	 * shillinq's multi-tenant cost-allocation projection.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function testBuildTypedEventCarriesAdministrationId(): void {
		$service = $this->serviceWithSpy();

		$timeEntry = $this->approvedTimesheet();
		$timeEntry['administrationId'] = 'ADM-001';

		$typed = $service->buildTypedEvent($timeEntry);

		$this->assertSame('ADM-001', $typed->getAdministrationId());

	}//end testBuildTypedEventCarriesAdministrationId()

	/**
	 * hours-process-redesign regression (a): an AGGREGATION write on an
	 * already-approved timesheet (approved → approved, new hours) emits
	 * NOTHING on either dispatch path — the edge check keeps the aggregate
	 * listener's recompute writes silent (Decision 7).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-The-event-carries-what-a-finance-consumer-needs-(REQ-TEC-003)
	 */
	public function testAggregationWriteOnApprovedTimesheetEmitsNothing(): void {
		$service = $this->serviceWithSpy();

		$before = $this->approvedTimesheet();
		$after = $this->approvedTimesheet();
		$after['hours'] = 40.0;
		$after['entryCount'] = 5;

		$emitted = $service->maybeDispatchApproved(
			schemaSlug: 'Timesheet',
			oldData: $before,
			newData: $after
		);

		$this->assertFalse($emitted);
		$this->assertCount(0, $this->spy->calls, 'An approved→approved recompute must not re-emit.');
		$this->assertCount(0, $this->typedDispatches);

	}//end testAggregationWriteOnApprovedTimesheetEmitsNothing()

	/**
	 * hours-process-redesign regression (b): a STAMPED approval write — the
	 * shape TimesheetProcessStampListener now produces on the carrying write
	 * — emits non-empty approvedBy/approvedAt, with zero change to this
	 * service (Decision 7: the defect of empty provenance is closed upstream).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-The-event-carries-what-a-finance-consumer-needs-(REQ-TEC-003)
	 */
	public function testStampedApprovalWriteEmitsPopulatedProvenance(): void {
		$service = $this->serviceWithSpy();

		$stamped = $this->approvedTimesheet();
		$stamped['approvedBy'] = 'manager1';
		$stamped['approvedAt'] = '2026-08-21T09:30:00Z';
		$stamped['entryCount'] = 3;

		$emitted = $service->maybeDispatchApproved(
			schemaSlug: 'Timesheet',
			oldData: ['status' => 'submitted', 'hours' => 36.5],
			newData: $stamped
		);

		$this->assertTrue($emitted);
		$data = $this->spy->calls[0]['payload']['data'];
		$this->assertSame('manager1', $data['approvedBy']);
		$this->assertSame('2026-08-21T09:30:00Z', $data['approvedAt']);
		$this->assertNotSame('', $data['approvedBy'], 'Provenance must be populated, not the legacy empty string.');
		$this->assertNotSame('', $data['approvedAt']);

		// Envelope compatibility: the entryCount aggregate does NOT leak into
		// the event — no key added, removed or retyped (REQ-TEC-003).
		$this->assertArrayNotHasKey('entryCount', $data);
		$this->assertSame(
			['timesheetId', 'employeeId', 'period', 'hours', 'billable', 'projectId', 'costCenter', 'clientRef', 'description', 'approvedBy', 'approvedAt'],
			array_keys($data),
			'The CloudEvent data keys must stay byte-compatible with the pre-redesign contract.'
		);

	}//end testStampedApprovalWriteEmitsPopulatedProvenance()

}//end class
