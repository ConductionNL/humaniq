<?php

/**
 * Employee Working Time
 *
 * One employee and the records that say when they work.
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

/**
 * Whose contracted hours are asked, and from which records.
 *
 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
 */
final class EmployeeWorkingTime {

	/**
	 * @param string             $employeeId The employee uuid.
	 * @param WorkingTimeSources $sources    The patterns, non-working times and calendar dates, any employee.
	 */
	public function __construct(
		public readonly string $employeeId,
		public readonly WorkingTimeSources $sources,
	) {

	}//end __construct()
}//end class
