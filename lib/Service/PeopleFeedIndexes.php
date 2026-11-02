<?php

/**
 * People Feed Indexes
 *
 * The employees, units and placements one people feed reads, indexed once, and the day it answers for.
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
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The indexed register rows of one people feed and its day.
 *
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-002
 */
final class PeopleFeedIndexes {

	/**
	 * @param array<string, array<string, mixed>>       $employeesById           Employees.
	 * @param array<string, array<string, mixed>>       $unitsById               Units.
	 * @param array<string, list<array<string, mixed>>> $assignmentsByEmployeeId Placements per employee.
	 * @param string                                    $today                   The day, `YYYY-MM-DD`.
	 */
	public function __construct(
		public readonly array $employeesById,
		public readonly array $unitsById,
		public readonly array $assignmentsByEmployeeId,
		public readonly string $today,
	) {

	}//end __construct()

}//end class
