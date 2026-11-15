<?php

/**
 * Humaniq RosteredDay
 *
 * One roster assignment as the cross-checks read it: the assignment, the
 * employee and the day.
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
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Who is rostered on which day, by which assignment.
 *
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
 */
final class RosteredDay {

	/**
	 * The RosterAssignment id.
	 *
	 * @var string
	 */
	public string $assignmentId = '';

	/**
	 * The employee.
	 *
	 * @var string
	 */
	public string $employeeId = '';

	/**
	 * The ISO date.
	 *
	 * @var string
	 */
	public string $date = '';

	/**
	 * The rostered day of an assignment, or null when it names no employee or date.
	 *
	 * @param array<string, mixed> $assignment The RosterAssignment.
	 *
	 * @return self|null
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
	 */
	public static function fromAssignment(array $assignment): ?self {
		$day = new self();
		$day->employeeId = trim((string)($assignment['employeeId'] ?? ''));
		$day->date = substr(trim((string)($assignment['date'] ?? '')), 0, 10);
		if ($day->employeeId === '' || $day->date === '') {
			return null;
		}

		$day->assignmentId = trim((string)($assignment['id'] ?? ($assignment['@self']['id'] ?? '')));

		return $day;
	}//end fromAssignment()
}//end class
