<?php

/**
 * Humaniq LeaveUsedHoursScope
 *
 * The balance whose used hours LeaveHoursCalculator::usedHoursFor() adds up:
 * its employee, year and leave type, and the contract hours snapshot.
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
 * @spec openspec/specs/leave-management/spec.md#REQ-LEAVE-POST-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One leave balance, as far as its used hours go.
 *
 * @spec openspec/specs/leave-management/spec.md#REQ-LEAVE-POST-001
 */
final class LeaveUsedHoursScope {

	/**
	 * The balance's employee.
	 *
	 * @var string
	 */
	public string $employeeId = '';

	/**
	 * The balance's year.
	 *
	 * @var int
	 */
	public int $year = 0;

	/**
	 * The balance's leave type.
	 *
	 * @var string
	 */
	public string $leaveType = '';

	/**
	 * The balance's contract hours snapshot, or null when unknown.
	 *
	 * @var float|null
	 */
	public ?float $contractHoursPerWeek = null;
}//end class
