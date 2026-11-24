<?php

/**
 * Humaniq AbsenceWindow
 *
 * The days a leave or sickness spread is asked over: whose working time, the
 * first and last day, and the contracted hours of each day in between
 * (time-non-project-activities D2). Built by {@see AbsenceDays::window()}.
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
 * @spec openspec/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * An employee's working time over a range of days, with the contracted hours per day.
 *
 * @spec openspec/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
 */
final class AbsenceWindow {

	/**
	 * Constructor.
	 *
	 * @param EmployeeWorkingTime                               $time     The employee and the working-time records.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $range    First and last day, inclusive.
	 * @param array<string, float>                              $contract Contracted hours by ISO date over the range.
	 */
	public function __construct(
		public readonly EmployeeWorkingTime $time,
		public readonly array $range,
		public readonly array $contract,
	) {

	}//end __construct()
}//end class
