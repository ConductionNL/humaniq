<?php

/**
 * Working Day
 *
 * One employee's day, as the contracted hours of that day are worked out.
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
 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * An employee's working time on one date.
 *
 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
 */
final class WorkingDay {

	/**
	 * @param EmployeeWorkingTime $time The employee and their records.
	 * @param DateTimeImmutable   $date The date asked about.
	 */
	public function __construct(
		public readonly EmployeeWorkingTime $time,
		public readonly DateTimeImmutable $date,
	) {

	}//end __construct()

	/**
	 * The lowercase English weekday name of the date.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-002
	 */
	public function weekday(): string {
		return strtolower($this->date->format('l'));
	}//end weekday()

	/**
	 * The date as `Y-m-d`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-002
	 */
	public function isoDate(): string {
		return $this->date->format('Y-m-d');
	}//end isoDate()

	/**
	 * Whether the working calendar was read.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
	 */
	public function isPatternOnly(): bool {
		return $this->time->sources->nonWorkingDates === null;
	}//end isPatternOnly()

	/**
	 * Whether the working calendar marks the date non-working.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
	 */
	public function isCalendarNonWorking(): bool {
		$dates = $this->time->sources->nonWorkingDates;

		return $dates !== null && in_array($this->isoDate(), $dates, true) === true;
	}//end isCalendarNonWorking()
}//end class
