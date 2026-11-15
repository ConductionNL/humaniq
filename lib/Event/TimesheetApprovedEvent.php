<?php

/**
 * Humaniq TimesheetApprovedEvent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Typed `IEventDispatcher` cross-app command event (ADR-041) emitted by
 * {@see \OCA\Humaniq\Service\TimeEntryEventService::maybeDispatchApproved()} on the
 * SAME approval edge that already dispatches the `nl.conduction.hrmq.timeentry.approved`
 * CloudEvent through OpenRegister's `WebhookService` — this typed event is
 * ADDITIVE, dispatched alongside that webhook, never in place of it, because the
 * webhook may carry real admin-configured subscribers of its own.
 *
 * The webhook is an admin-configured outbound HTTP delivery — it has no
 * in-process consumer surface. Per ADR-041, a sibling Conduction app (shillinq)
 * that wants to react to humaniq's approval in the SAME request, without standing
 * up an HTTP receiving endpoint, consumes this typed event instead: it
 * registers an `IEventListener`, `class_exists()`-guards this FQCN (fail closed
 * when humaniq is absent), and reads the getters below. Mirrors pipelinq's
 * `PosStockMovedEvent` / `PosTransactionService::emitStockMovedEvent()` shape
 * (typed dispatch + webhook fire-and-forget, both best-effort, neither gating
 * the other).
 *
 * Payload note (period grain): `period` is humaniq's Timesheet.period field,
 * which is polymorphic-grain — `YYYY-MM` (month), `YYYY-Www` (ISO week), or
 * `YYYY-Wnn-D` (a single ISO week-day) — see
 * `lib/Settings/register.d/hr-timesheet.json`. This event carries the RAW
 * period string UNCHANGED plus an explicit `periodGrain` marker
 * (`month`|`week`|`day`|`unknown`) rather than silently flattening it to a
 * single day. A consumer that needs a single calendar date (e.g. shillinq's
 * `UrenRegistratie.date`) MUST branch on `periodGrain` and decide its own
 * projection — humaniq does not resolve a month- or week-grain period down to one
 * day, since that decision belongs to the consuming domain, not the producer.
 *
 * @category Event
 * @package  OCA\Humaniq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
 */

declare(strict_types=1);

namespace OCA\Humaniq\Event;

use OCP\EventDispatcher\Event;

/**
 * Fired alongside the webhook on the SAME Timesheet draft/submitted → approved
 * edge {@see \OCA\Humaniq\Service\TimeEntryEventService} already governs.
 *
 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
 */
class TimesheetApprovedEvent extends Event {

	/**
	 * A period expressed as `YYYY-MM` (calendar month).
	 *
	 * @var string
	 */
	public const GRAIN_MONTH = 'month';

	/**
	 * A period expressed as `YYYY-Www` (ISO calendar week).
	 *
	 * @var string
	 */
	public const GRAIN_WEEK = 'week';

	/**
	 * A period expressed as `YYYY-Wnn-D` (a single ISO week-day).
	 *
	 * @var string
	 */
	public const GRAIN_DAY = 'day';

	/**
	 * A period string that matched none of the recognised shapes. Carried, not
	 * refused — a consumer decides for itself whether an unrecognised grain is
	 * fatal to its own projection.
	 *
	 * @var string
	 */
	public const GRAIN_UNKNOWN = 'unknown';

	/**
	 * The CloudEvents id (stable UUID per emission).
	 *
	 * @var string
	 */
	private string $eventId;

	/**
	 * The approved Timesheet's OpenRegister id/uuid.
	 *
	 * @var string
	 */
	private string $timesheetId;

	/**
	 * Reference to the Employee who logged the hours.
	 *
	 * @var string
	 */
	private string $employeeId;

	/**
	 * The raw period string.
	 *
	 * @var string
	 */
	private string $period;

	/**
	 * One of the `GRAIN_*` constants — the grain `period` is expressed in.
	 *
	 * @var string
	 */
	private string $periodGrain;

	/**
	 * Total approved hours for the period.
	 *
	 * @var float
	 */
	private float $hours;

	/**
	 * Optional project reference the hours are booked against (may be empty).
	 *
	 * @var string
	 */
	private string $projectId;

	/**
	 * Optional cost centre / kostenplaats reference (may be empty).
	 *
	 * @var string
	 */
	private string $costCenter;

	/**
	 * Whether the hours are billable to a client/project.
	 *
	 * @var boolean
	 */
	private bool $billable;

	/**
	 * Optional cross-app client/organisation reference (pipelinq domain object uuid; may be empty).
	 *
	 * @var string
	 */
	private string $clientRef;

	/**
	 * Denormalized multi-administratie tenant scope (may be empty when unset).
	 *
	 * @var string
	 */
	private string $administrationId;

	/**
	 * Nextcloud user id of the approving manager.
	 *
	 * @var string
	 */
	private string $approvedBy;

	/**
	 * ISO 8601 UTC approval timestamp.
	 *
	 * @var string
	 */
	private string $approvedAt;

	/**
	 * Constructor. The fields stay flat and in this order, behind the same
	 * getters, for every consumer of the event.
	 *
	 * @param TimesheetEventIds $ids The emission, timesheet, employee and administration.
	 * @param TimesheetPeriodHours $period The raw period, its grain and the approved hours.
	 * @param TimesheetBooking $booking Project, cost centre, billable flag and client.
	 * @param TimesheetApproval $approval Who approved and when.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function __construct(TimesheetEventIds $ids, TimesheetPeriodHours $period, TimesheetBooking $booking, TimesheetApproval $approval) {
		parent::__construct();
		$this->eventId = $ids->eventId;
		$this->timesheetId = $ids->timesheetId;
		$this->employeeId = $ids->employeeId;
		$this->period = $period->period;
		$this->periodGrain = $period->periodGrain;
		$this->hours = $period->hours;
		$this->projectId = $booking->projectId;
		$this->costCenter = $booking->costCenter;
		$this->billable = $booking->billable;
		$this->clientRef = $booking->clientRef;
		$this->administrationId = $ids->administrationId;
		$this->approvedBy = $approval->approvedBy;
		$this->approvedAt = $approval->approvedAt;

	}//end __construct()

	/**
	 * The event for one approved timesheet payload.
	 *
	 * The raw period is carried unchanged with its grain classified; the
	 * timesheet id doubles as the event id.
	 *
	 * @param array<string, mixed> $timeEntry The approved Timesheet payload.
	 * @param string $approvedAt The approval timestamp (ISO 8601 UTC).
	 *
	 * @return self
	 *
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public static function forTimesheet(array $timeEntry, string $approvedAt): self {
		$uuid = (string)($timeEntry['id'] ?? $timeEntry['uuid'] ?? '');
		$period = (string)($timeEntry['period'] ?? '');

		return new self(
			ids: new TimesheetEventIds(eventId: $uuid, timesheetId: $uuid, employeeId: (string)($timeEntry['employeeId'] ?? ''), administrationId: (string)($timeEntry['administrationId'] ?? '')),
			period: new TimesheetPeriodHours(period: $period, hours: (float)($timeEntry['hours'] ?? 0)),
			booking: new TimesheetBooking($timeEntry),
			approval: new TimesheetApproval(approvedBy: (string)($timeEntry['approvedBy'] ?? ''), approvedAt: $approvedAt)
		);

	}//end forTimesheet()

	/**
	 * The CloudEvents id (stable per emission).
	 *
	 * @return string The event id.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function getEventId(): string {
		return $this->eventId;
	}//end getEventId()

	/**
	 * The approved Timesheet's OpenRegister id/uuid — the shared idempotency
	 * key a consumer keys de-duplication on.
	 *
	 * @return string The timesheet id.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function getTimesheetId(): string {
		return $this->timesheetId;
	}//end getTimesheetId()

	/**
	 * Reference to the Employee (claimant) who logged the hours.
	 *
	 * @return string The employee reference.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function getEmployeeId(): string {
		return $this->employeeId;
	}//end getEmployeeId()

	/**
	 * The RAW Timesheet.period value, unmodified.
	 *
	 * @return string The period string.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-The-typed-event-SHALL-carry-the-raw-period-plus-an-explicit-grain-marker
	 */
	public function getPeriod(): string {
		return $this->period;
	}//end getPeriod()

	/**
	 * The grain `period` is expressed in — one of the `GRAIN_*` constants.
	 *
	 * @return string The period grain marker.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-The-typed-event-SHALL-carry-the-raw-period-plus-an-explicit-grain-marker
	 */
	public function getPeriodGrain(): string {
		return $this->periodGrain;
	}//end getPeriodGrain()

	/**
	 * Total approved hours for the period.
	 *
	 * @return float The hours.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function getHours(): float {
		return $this->hours;
	}//end getHours()

	/**
	 * Optional project reference the hours are booked against (may be empty).
	 *
	 * @return string The project reference.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function getProjectId(): string {
		return $this->projectId;
	}//end getProjectId()

	/**
	 * Optional cost centre / kostenplaats reference (may be empty).
	 *
	 * @return string The cost centre reference.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function getCostCenter(): string {
		return $this->costCenter;
	}//end getCostCenter()

	/**
	 * Whether the hours are billable to a client/project.
	 *
	 * @return bool True when billable.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function isBillable(): bool {
		return $this->billable;
	}//end isBillable()

	/**
	 * Optional cross-app client/organisation reference (may be empty).
	 *
	 * @return string The client reference.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function getClientRef(): string {
		return $this->clientRef;
	}//end getClientRef()

	/**
	 * Denormalized multi-administratie tenant scope (may be empty when unset).
	 *
	 * @return string The administration id.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function getAdministrationId(): string {
		return $this->administrationId;
	}//end getAdministrationId()

	/**
	 * Nextcloud user id of the approving manager.
	 *
	 * @return string The approver's user id.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function getApprovedBy(): string {
		return $this->approvedBy;
	}//end getApprovedBy()

	/**
	 * ISO 8601 UTC approval timestamp.
	 *
	 * @return string The approval timestamp.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function getApprovedAt(): string {
		return $this->approvedAt;
	}//end getApprovedAt()

	/**
	 * Classify a raw Timesheet.period string into one of the `GRAIN_*`
	 * constants. Pure and static so a consumer can reuse the same
	 * classification independently of an event instance if it only has the
	 * raw string (e.g. read back from storage).
	 *
	 * @param string $period The raw period string.
	 *
	 * @return string One of the `GRAIN_*` constants.
	 *
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-The-typed-event-SHALL-carry-the-raw-period-plus-an-explicit-grain-marker
	 */
	public static function classifyPeriodGrain(string $period): string {
		if (preg_match('/^\d{4}-(0[1-9]|1[0-2])\z/', $period) === 1) {
			return self::GRAIN_MONTH;
		}

		if (preg_match('/^\d{4}-W(0[1-9]|[1-4]\d|5[0-3])-[1-7]\z/', $period) === 1) {
			return self::GRAIN_DAY;
		}

		if (preg_match('/^\d{4}-W(0[1-9]|[1-4]\d|5[0-3])\z/', $period) === 1) {
			return self::GRAIN_WEEK;
		}

		return self::GRAIN_UNKNOWN;
	}//end classifyPeriodGrain()

}//end class
