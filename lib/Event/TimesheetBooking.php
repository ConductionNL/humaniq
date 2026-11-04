<?php

/**
 * Timesheet Booking
 *
 * Where the approved hours of a timesheet event are booked: project, cost
 * centre, client and whether they are billable.
 *
 * @category Event
 * @package  OCA\Humaniq\Event
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
 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
 */

declare(strict_types=1);

namespace OCA\Humaniq\Event;

/**
 * Part of a {@see TimesheetApprovedEvent}.
 *
 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
 */
final class TimesheetBooking {

	/**
	 * Optional project reference the hours are booked against (may be empty).
	 *
	 * @var string
	 */
	public readonly string $projectId;

	/**
	 * Optional cost centre / kostenplaats reference (may be empty).
	 *
	 * @var string
	 */
	public readonly string $costCenter;

	/**
	 * Whether the hours are billable to a client/project.
	 *
	 * @var boolean
	 */
	public readonly bool $billable;

	/**
	 * Optional cross-app client/organisation reference (pipelinq domain object uuid; may be empty).
	 *
	 * @var string
	 */
	public readonly string $clientRef;

	/**
	 * @param array<string, mixed> $timeEntry The approved Timesheet payload; absent fields read as empty or not billable.
	 */
	public function __construct(array $timeEntry) {
		$this->projectId = (string)($timeEntry['projectId'] ?? '');
		$this->costCenter = (string)($timeEntry['costCenter'] ?? '');
		$this->billable = (bool)($timeEntry['billable'] ?? false);
		$this->clientRef = (string)($timeEntry['clientRef'] ?? '');

	}//end __construct()
}//end class
