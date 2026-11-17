<?php

/**
 * Contract of OnCallAverageService
 *
 * The public surface of OnCallAverageService that other components call, so they depend
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
 * Contract of OnCallAverageService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OnCallAverageServiceInterface {
	/**
	 * One row per on-call contract that ran in the window.
	 *
	 * @param array<int, array<string, mixed>> $contracts            EmploymentContract rows.
	 * @param array<int, array<string, mixed>> $entries              TimeEntry rows: employeeId, timesheetId, date, hours.
	 * @param array<int, string>               $approvedTimesheetIds The ids of approved timesheets.
	 * @param array{0: string, 1: string}      $window               First and last day of the window (Y-m-d), as {@see window()} returns them.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-002
	 */
	public function averages(array $contracts, array $entries, array $approvedTimesheetIds, array $window): array;

	/**
	 * The window as two Y-m-d days, or null when it is not one. Without `to`
	 * it ends today; without `from` it starts a year before `to`.
	 *
	 * @param string|null $from The first day.
	 * @param string|null $to   The last day.
	 *
	 * @return array{0: string, 1: string}|null
	 *
	 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-002
	 */
	public function window(?string $from, ?string $to): ?array;
}//end interface
