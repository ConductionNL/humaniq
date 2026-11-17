<?php

/**
 * Contract of TimesheetAggregationService
 *
 * The public surface of TimesheetAggregationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
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
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Kernel\Contracts;

/**
 * Contract of TimesheetAggregationService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TimesheetAggregationServiceInterface {
	/**
	 * Recompute one timesheet's aggregates from its entries and persist them
	 * when they differ from the stored values.
	 *
	 * @param string $timesheetId The timesheet uuid.
	 *
	 * @return array<string, mixed>|null The recomputed aggregate values, or
	 *                                   null when the timesheet is gone.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
	 */
	public function recomputeForTimesheet(string $timesheetId): ?array;
}//end interface
