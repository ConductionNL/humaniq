<?php

/**
 * Leave Calendar Event
 *
 * The desired all-day VEVENT of one LeaveRequest or SickLeaveCase: which
 * source it belongs to, its compact DATE span and its AVG-safe SUMMARY.
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
 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Immutable desired state of one leave calendar event.
 *
 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-002
 */
final class LeaveCalendarEvent {

	/**
	 * @param LeaveCalendarEventRef $ref The source of the event.
	 * @param string $dtstart Compact DATE value (YYYYMMDD).
	 * @param string $dtendExclusive Compact DATE value (YYYYMMDD), exclusive.
	 * @param string $summary The AVG-safe SUMMARY text (unescaped).
	 */
	public function __construct(
		public readonly LeaveCalendarEventRef $ref,
		public readonly string $dtstart,
		public readonly string $dtendExclusive,
		public readonly string $summary,
	) {

	}//end __construct()

}//end class
