<?php

/**
 * Hrmq TimeEntryEventService.
 *
 * Emits the `nl.conduction.hrmq.timeentry.approved` CloudEvent when a Timesheet
 * (hrmq's per-employee, per-period time-entry record) transitions into the
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
 * @category Service
 * @package  OCA\Hrmq\Service
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

namespace OCA\Hrmq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCP\EventDispatcher\Event;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds and dispatches the hrmq approved-time-entry CloudEvent.
 *
 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md
 */
class TimeEntryEventService
{

    /**
     * CloudEvents `type` for an approved time entry (Timesheet).
     *
     * @var string
     */
    public const EVENT_TYPE = 'nl.conduction.hrmq.timeentry.approved';

    /**
     * CloudEvents `source` for hrmq time-entry events.
     *
     * @var string
     */
    public const EVENT_SOURCE = '/apps/hrmq/timesheets';

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
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
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
     * @param string                    $schemaSlug The slug of the changed object's schema.
     * @param array<string, mixed>|null $oldData    The object payload BEFORE the change (null on create).
     * @param array<string, mixed>      $newData    The object payload AFTER the change.
     *
     * @return bool True when the CloudEvent was dispatched.
     *
     * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
     */
    public function maybeDispatchApproved(string $schemaSlug, ?array $oldData, array $newData): bool
    {
        if (strtolower($schemaSlug) !== self::TIMESHEET_SLUG) {
            return false;
        }

        if ($this->isApprovalTransition($oldData, $newData) === false) {
            return false;
        }

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
     * @param array<string, mixed>      $newData The payload after the change.
     *
     * @return bool True on the approval edge.
     *
     * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
     */
    public function isApprovalTransition(?array $oldData, array $newData): bool
    {
        $newStatus = (string) ($newData['status'] ?? '');
        if ($newStatus !== self::APPROVED_STATUS) {
            return false;
        }

        $oldStatus = (string) (($oldData['status'] ?? '') ?: '');

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
    public function buildApprovedEvent(array $timeEntry): array
    {
        $uuid       = (string) ($timeEntry['id'] ?? $timeEntry['uuid'] ?? '');
        $approvedAt = (string) ($timeEntry['approvedAt'] ?? '');
        $time       = $approvedAt;
        if ($time === '') {
            $time = $this->now();
        }

        return [
            'specversion'     => '1.0',
            'type'            => self::EVENT_TYPE,
            'source'          => self::EVENT_SOURCE,
            'id'              => $uuid,
            'time'            => $time,
            'datacontenttype' => 'application/json',
            'data'            => [
                'timesheetId' => $uuid,
                'employeeId'  => (string) ($timeEntry['employeeId'] ?? ''),
                'period'      => (string) ($timeEntry['period'] ?? ''),
                'hours'       => (float) ($timeEntry['hours'] ?? 0),
                'billable'    => (bool) ($timeEntry['billable'] ?? false),
                'projectId'   => (string) ($timeEntry['projectId'] ?? ''),
                'costCenter'  => (string) ($timeEntry['costCenter'] ?? ''),
                'clientRef'   => (string) ($timeEntry['clientRef'] ?? ''),
                'description' => (string) ($timeEntry['description'] ?? ''),
                'approvedBy'  => (string) ($timeEntry['approvedBy'] ?? ''),
                'approvedAt'  => $approvedAt,
            ],
        ];

    }//end buildApprovedEvent()


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
    private function dispatch(array $payload): bool
    {
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
                'hrmq: approved-time-entry CloudEvent not dispatched (no consumer or OpenRegister unavailable)',
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
     */
    public function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

    }//end now()


}//end class
