<?php

/**
 * Timesheet Period Hours
 *
 * The approved hours of a timesheet event and the period they cover, with the
 * grain the raw period is expressed in, classified from the period itself.
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
final class TimesheetPeriodHours {

	/**
	 * One of the `GRAIN_*` constants of {@see TimesheetApprovedEvent} — the grain `period` is expressed in.
	 *
	 * @var string
	 */
	public readonly string $periodGrain;

	/**
	 * The grain is classified from the raw period, never passed in.
	 *
	 * @param string $period The RAW Timesheet.period value, unmodified (`YYYY-MM` | `YYYY-Www` | `YYYY-Wnn-D`).
	 * @param float $hours Total approved hours for the period.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) TimesheetApprovedEvent::classifyPeriodGrain()
	 *  is the event's own pure classifier, the one consumers reuse.
	 */
	public function __construct(
		public readonly string $period,
		public readonly float $hours,
	) {
		$this->periodGrain = TimesheetApprovedEvent::classifyPeriodGrain($period);

	}//end __construct()
}//end class
