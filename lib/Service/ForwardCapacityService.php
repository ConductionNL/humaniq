<?php

/**
 * Forward Capacity Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What is planned on each person, forward from a date, against the hours that
 * person is actually contracted for.
 *
 * WHY FORWARD, AND WHY IT NEEDED A DEPENDENCY
 * -------------------------------------------
 * humaniq reads backward today: `absence-rate` and the verzuim widgets report
 * what happened. A planner needs the other direction, and the other direction
 * needs a denominator. Until `working-hours-per-person` landed, the only
 * denominator available was the instance's full-time week, which is wrong for
 * every part-timer, which is most of a gemeente's bezwaarteam (design D8).
 *
 * WHY A MISSING CONTRACT IS SAID AND NOT FILLED IN
 * ------------------------------------------------
 * An employee with no working pattern gets `hasContractedHours` false and NO
 * percentage. Substituting 40 hours would report a full-timer's utilisation for
 * somebody whose contract nobody recorded, and it would look exactly like a
 * real measurement (REQ-ROST-C03).
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rostering/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Reads planned hours against contracted hours, forward from a date.
 *
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C03
 */
class ForwardCapacityService {

	/**
	 * Constructor.
	 *
	 * @param WorkingHoursService $workingHours The contracted-hours denominator.
	 * @param AvailabilityService $availability The committed-hours half, shared so the two figures cannot drift.
	 */
	public function __construct(
		private readonly WorkingHoursService $workingHours = new WorkingHoursService(),
		private readonly AvailabilityService $availability = new AvailabilityService(),
	) {

	}//end __construct()

	/**
	 * Planned against contracted, per employee, over a forward window.
	 *
	 * @param array<int, string> $employeeIds The employees to read.
	 * @param DateTimeImmutable $from First day, inclusive.
	 * @param DateTimeImmutable $to Last day, inclusive.
	 * @param array<string, mixed> $sources Rows per source, as {@see AvailabilityService::availability()} takes them.
	 *
	 * @return array{from: string, to: string, employees: array<int, array<string, mixed>>, totals: array<string, mixed>}
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C03
	 */
	public function capacity(
		array $employeeIds,
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		array $sources,
	): array {
		$free = self::byEmployee($this->availability->availability(employeeIds: $employeeIds, from: $from, to: $to, sources: $sources));
		$patterns = ($sources['workingPatterns'] ?? []);
		$tally = new CapacityTally();

		$employees = [];
		foreach (array_values(array_unique(array_map('strval', $employeeIds))) as $employeeId) {
			$hasPattern = ($this->workingHours->patternInForce(employeeId: $employeeId, date: $from, patterns: $patterns) !== null);
			$employees[] = ($hasPattern === true)
				? $tally->withContract($employeeId, ($free[$employeeId] ?? null))
				: $tally->withoutContract($employeeId, ($free[$employeeId] ?? null));
		}

		return [
			'from' => $from->format('Y-m-d'),
			'to' => $to->format('Y-m-d'),
			'employees' => $employees,
			'totals' => $tally->totals(),
		];
	}//end capacity()

	/**
	 * Availability rows keyed by employee id (a later row wins).
	 *
	 * @param array<int, array<string, mixed>> $rows The availability rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function byEmployee(array $rows): array {
		$byEmployee = [];
		foreach ($rows as $row) {
			$byEmployee[(string)$row['employeeId']] = $row;
		}

		return $byEmployee;

	}//end byEmployee()
}//end class
