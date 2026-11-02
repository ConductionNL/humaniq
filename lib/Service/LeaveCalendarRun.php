<?php

/**
 * Leave Calendar Run
 *
 * The state of one leave calendar sync run: the calendar target, the
 * employee name index, the optional --from bound and the outcome rows
 * collected so far.
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
 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Per-run state of LeaveCalendarService::sync().
 *
 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-001
 */
final class LeaveCalendarRun {

	/**
	 * Outcome rows, in the order they were recorded.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $results = [];

	/**
	 * @param CalendarTarget $target The resolved calendar target.
	 * @param array<string, array<string, string>> $employeesById Employee name index.
	 * @param string|null $from Optional bound (Y-m-d).
	 */
	public function __construct(
		public readonly CalendarTarget $target,
		public readonly array $employeesById,
		public readonly ?string $from,
	) {

	}//end __construct()

	/**
	 * Append one outcome row.
	 *
	 * @param array<string, mixed> $outcome The outcome row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-001
	 */
	public function record(array $outcome): void {
		$this->results[] = $outcome;

	}//end record()

	/**
	 * @return array<int, array<string, mixed>> The outcome rows.
	 *
	 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-001
	 */
	public function results(): array {
		return $this->results;

	}//end results()

}//end class
