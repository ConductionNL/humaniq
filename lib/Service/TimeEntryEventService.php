<?php

/**
 * Humaniq TimeEntryEventService.
 *
 * Emits the `nl.conduction.hrmq.timeentry.approved` CloudEvent when a Timesheet
 * (humaniq's per-employee, per-period time-entry record) transitions into the
 * `approved` state. The event carries the approved hours, project / cost centre
 * and billable flag a downstream finance app (shillinq) needs to feed
 * invoice-from-time and the WBSO urenregistratie export — closing the dangling
 * `bookkeeping-time-tracking` dependency shillinq's hours-consumers assumed.
 *
 * Delivery is fire-and-forget through OpenRegister's WebhookService (mirroring
 * the fleet `nl.conduction.*` CloudEvent convention, e.g. pipelinq's
 * ShillinqWipService): a missing consumer or an unavailable OpenRegister must
 * never fail the originating approval write. Only the approval EDGE emits — an
 * update to an already-approved timesheet, or any non-approval transition, is
 * silent (idempotent).
 *
 * On the SAME edge, this service ALSO dispatches a typed
 * {@see \OCA\Humaniq\Event\TimesheetApprovedEvent} through Nextcloud's
 * `IEventDispatcher` — the ADR-041 cross-app command recipe. The webhook is an
 * admin-configured outbound HTTP delivery with no in-process consumer surface;
 * the typed event is what lets a sibling Conduction app (shillinq) react to
 * the SAME approval within the same request via a plain `IEventListener`,
 * without standing up an HTTP receiver. The two dispatches are independent and
 * additive — mirroring pipelinq's `PosTransactionService::emitStockMovedEvent()`
 * (typed `dispatchTyped()` + webhook fire-and-forget, neither gating the
 * other): a typed-dispatch failure never blocks the webhook, and vice versa.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Humaniq\Event\TimesheetApprovedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds and dispatches the humaniq approved-time-entry CloudEvent.
 *
 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md
 */
class TimeEntryEventService {

	/**
	 * CloudEvents `type` for an approved time entry (Timesheet).
	 *
	 * FROZEN at the `hrmq` spelling across the Humaniq rename: this is a
	 * cross-app wire contract that shillinq subscribes to by exact string, and
	 * the spec states the envelope SHALL remain stable. Renaming it would
	 * silently stop every existing subscriber from matching. The sibling
	 * EVENT_SOURCE below is a locator, not a contract, so it DOES follow the
	 * route to its new `/apps/humaniq/` location.
	 *
	 * @var string
	 */
	public const EVENT_TYPE = 'nl.conduction.hrmq.timeentry.approved';

	/**
	 * CloudEvents `source` for humaniq time-entry events.
	 *
	 * @var string
	 */
	public const EVENT_SOURCE = '/apps/humaniq/timesheets';

	/**
	 * Slug of the schema whose approval emits the event (lower-cased for a
	 * case-insensitive compare against the resolved schema slug).
	 *
	 * @var string
	 */
	public const TIMESHEET_SLUG = 'timesheet';

	/**
	 * The lifecycle status that represents an approved timesheet.
	 *
	 * @var string
	 */
	public const APPROVED_STATUS = 'approved';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy OpenRegister WebhookService lookup).
	 * @param IEventDispatcher $eventDispatcher Nextcloud's typed event dispatcher (ADR-041 cross-app event).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Emit the approved-time-entry CloudEvent iff this change is a Timesheet
	 * crossing into `approved`.
	 *
	 * Returns true only when an event was actually dispatched. All three gates
	 * (schema, transition, dispatch) must pass; each returns false silently
	 * otherwise so the listener stays a thin adapter.
	 *
	 * @param string $schemaSlug The slug of the changed object's schema.
	 * @param array<string, mixed>|null $oldData The object payload BEFORE the change (null on create).
	 * @param array<string, mixed> $newData The object payload AFTER the change.
	 *
	 * @return bool True when the CloudEvent was dispatched through the webhook.
	 *              The typed {@see TimesheetApprovedEvent} dispatch (ADR-041) is
	 *              always attempted alongside it, independently — its own
	 *              success/failure does not affect this return value, mirroring
	 *              the fire-and-forget contract of the webhook dispatch itself.
	 *
	 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
	 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function maybeDispatchApproved(string $schemaSlug, ?array $oldData, array $newData): bool {
		if (strtolower($schemaSlug) !== self::TIMESHEET_SLUG) {
			return false;
		}

		if ($this->isApprovalTransition($oldData, $newData) === false) {
			return false;
		}

		$this->dispatchTypedEvent($newData);

		return $this->dispatch($this->buildApprovedEvent($newData));
	}//end maybeDispatchApproved()

	/**
	 * Whether the change is the edge into `approved`.
	 *
	 * True only when the new status is `approved` AND the old status was not —
	 * so a re-save of an already-approved timesheet (or any non-approval
	 * transition) does not re-emit (idempotent per REQ-TEC-002).
	 *
	 * @param array<string, mixed>|null $oldData The payload before the change (null on create).
	 * @param array<string, mixed> $newData The payload after the change.
	 *
	 * @return bool True on the approval edge.
	 *
	 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
	 */
	public function isApprovalTransition(?array $oldData, array $newData): bool {
		$newStatus = (string)($newData['status'] ?? '');
		if ($newStatus !== self::APPROVED_STATUS) {
			return false;
		}

		$oldStatus = (string)(($oldData['status'] ?? '') ?: '');

		return $oldStatus !== self::APPROVED_STATUS;
	}//end isApprovalTransition()

	/**
	 * Build the CloudEvents 1.0 envelope for an approved timesheet.
	 *
	 * The `data` object carries exactly what a finance consumer needs to raise
	 * an invoice line / WBSO urenregistratie row: the approved hours, the
	 * project / cost centre it is booked against, the billable flag, and the
	 * approval provenance.
	 *
	 * @param array<string, mixed> $timeEntry The approved Timesheet payload.
	 *
	 * @return array<string, mixed> The CloudEvent envelope.
	 *
	 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-003
	 */
	public function buildApprovedEvent(array $timeEntry): array {
		$uuid = (string)($timeEntry['id'] ?? $timeEntry['uuid'] ?? '');
		$approvedAt = (string)($timeEntry['approvedAt'] ?? '');
		$time = $approvedAt;
		if ($time === '') {
			$time = $this->now();
		}

		return [
			'specversion' => '1.0',
			'type' => self::EVENT_TYPE,
			'source' => self::EVENT_SOURCE,
			'id' => $uuid,
			'time' => $time,
			'datacontenttype' => 'application/json',
			'data' => [
				'timesheetId' => $uuid,
				'employeeId' => (string)($timeEntry['employeeId'] ?? ''),
				'period' => (string)($timeEntry['period'] ?? ''),
				'hours' => (float)($timeEntry['hours'] ?? 0),
				'billable' => (bool)($timeEntry['billable'] ?? false),
				'projectId' => (string)($timeEntry['projectId'] ?? ''),
				'costCenter' => (string)($timeEntry['costCenter'] ?? ''),
				'clientRef' => (string)($timeEntry['clientRef'] ?? ''),
				'description' => (string)($timeEntry['description'] ?? ''),
				'approvedBy' => (string)($timeEntry['approvedBy'] ?? ''),
				'approvedAt' => $approvedAt,
			],
		];

	}//end buildApprovedEvent()

	/**
	 * Build the typed {@see TimesheetApprovedEvent} for an approved timesheet.
	 *
	 * Carries the same approval-provenance data as {@see buildApprovedEvent()}'s
	 * CloudEvent `data` object, plus an explicit `periodGrain` marker
	 * classifying the RAW `period` string — humaniq's Timesheet.period is
	 * polymorphic-grain (`YYYY-MM` | `YYYY-Www` | `YYYY-Wnn-D`) and this event
	 * never flattens it to a single day; a consumer that needs one date decides
	 * that projection itself using the grain marker.
	 *
	 * @param array<string, mixed> $timeEntry The approved Timesheet payload.
	 *
	 * @return TimesheetApprovedEvent The typed cross-app event.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) TimesheetApprovedEvent::classifyPeriodGrain()
	 *  is a pure, side-effect-free classifier — the same "pure value-object
	 *  factory method" precedent already used unguarded in PayrollReproduceService.
	 *
	 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function buildTypedEvent(array $timeEntry): TimesheetApprovedEvent {
		$uuid = (string)($timeEntry['id'] ?? $timeEntry['uuid'] ?? '');
		$approvedAt = (string)($timeEntry['approvedAt'] ?? '');
		$time = $approvedAt;
		if ($time === '') {
			$time = $this->now();
		}

		$period = (string)($timeEntry['period'] ?? '');

		return new TimesheetApprovedEvent(
			eventId: $uuid,
			timesheetId: $uuid,
			employeeId: (string)($timeEntry['employeeId'] ?? ''),
			period: $period,
			periodGrain: TimesheetApprovedEvent::classifyPeriodGrain($period),
			hours: (float)($timeEntry['hours'] ?? 0),
			projectId: (string)($timeEntry['projectId'] ?? ''),
			costCenter: (string)($timeEntry['costCenter'] ?? ''),
			billable: (bool)($timeEntry['billable'] ?? false),
			clientRef: (string)($timeEntry['clientRef'] ?? ''),
			administrationId: (string)($timeEntry['administrationId'] ?? ''),
			approvedBy: (string)($timeEntry['approvedBy'] ?? ''),
			approvedAt: $time,
		);

	}//end buildTypedEvent()

	/**
	 * Dispatch the typed {@see TimesheetApprovedEvent} through Nextcloud's
	 * `IEventDispatcher` (ADR-041). Fire-and-forget: any failure is logged and
	 * never thrown — this dispatch is additive to the webhook and must never
	 * block or fail the approval write, nor the webhook dispatch that follows
	 * it in {@see maybeDispatchApproved()}.
	 *
	 * @param array<string, mixed> $timeEntry The approved Timesheet payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	private function dispatchTypedEvent(array $timeEntry): void {
		try {
			$this->eventDispatcher->dispatchTyped($this->buildTypedEvent($timeEntry));
		} catch (\Throwable $e) {
			$this->logger->warning(
				'humaniq: TimesheetApprovedEvent typed dispatch failed (webhook dispatch still attempted)',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end dispatchTypedEvent()

	/**
	 * Dispatch a CloudEvent through OpenRegister's WebhookService.
	 *
	 * Fire-and-forget: any failure to resolve or invoke the WebhookService is
	 * logged and reported as a false return, but never throws — the approval
	 * write must complete regardless of consumer availability.
	 *
	 * @param array<string, mixed> $payload The CloudEvent envelope.
	 *
	 * @return bool True on successful dispatch, false on failure.
	 */
	private function dispatch(array $payload): bool {
		try {
			$webhookService = $this->container->get('OCA\OpenRegister\Service\WebhookService');
			$webhookService->dispatchEvent(
				_event: new Event(),
				eventName: self::EVENT_TYPE,
				payload: $payload
			);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'humaniq: approved-time-entry CloudEvent not dispatched (no consumer or OpenRegister unavailable)',
				['exception' => $e->getMessage(), 'eventName' => self::EVENT_TYPE]
			);
			return false;
		}//end try

	}//end dispatch()

	/**
	 * Current UTC timestamp in ISO 8601 format.
	 *
	 * Fallback for the CloudEvent `time` when the approval write did not stamp
	 * `approvedAt`.
	 *
	 * @return string The ISO 8601 timestamp.
	 *
	 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-003
	 */
	public function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
	}//end now()

}//end class
