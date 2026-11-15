<?php

/**
 * Timesheet Event Ids
 *
 * Which emission, timesheet, employee and administration an approved-timesheet
 * event is about.
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
final class TimesheetEventIds {

	/**
	 * @param string $eventId The CloudEvents id (stable UUID per emission).
	 * @param string $timesheetId The approved Timesheet's OpenRegister id/uuid.
	 * @param string $employeeId Reference to the Employee who logged the hours.
	 * @param string $administrationId Denormalized multi-administratie tenant scope (may be empty when unset).
	 */
	public function __construct(
		public readonly string $eventId,
		public readonly string $timesheetId,
		public readonly string $employeeId,
		public readonly string $administrationId,
	) {

	}//end __construct()
}//end class
