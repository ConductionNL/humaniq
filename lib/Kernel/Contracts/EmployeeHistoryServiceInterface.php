<?php

/**
 * Contract of EmployeeHistoryService
 *
 * The public surface of EmployeeHistoryService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of EmployeeHistoryService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmployeeHistoryServiceInterface {
	/**
	 * The schemas a history reads, with the detail page each event links to.
	 *
	 * @var array<string, string>
	 */
	public const SOURCES = [
		'EmploymentContract' => 'EmploymentContractDetail',
		'OrgAssignment' => 'OrgAssignmentDetail',
		'CompAdjustment' => 'CompAdjustmentDetail',
		'LeaveRequest' => 'LeaveRequestDetail',
		'SickLeaveCase' => 'SickLeaveCaseDetail',
		'PerformanceReview' => 'PerformanceReviewDetail',
	];

	/**
	 * The events in the rows handed in, newest first. Only rows of
	 * `$employeeId` count; a rejected or unsubmitted leave request, a pay
	 * change not yet applied and a review not yet final are left out.
	 *
	 * @param string                                         $employeeId   The employee.
	 * @param array<string, list<array<string, mixed>>>       $rowsBySchema Rows per source schema.
	 *
	 * @return list<array{id: string, schema: string, kind: string, start: string, end: string|null, detail: string, route: string}>
	 *
	 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-001
	 */
	public function historyFor(string $employeeId, array $rowsBySchema): array;

	/**
	 * The contracts active on `$date`, with summed hours and FTE.
	 *
	 * @param string                     $employeeId        The employee.
	 * @param list<array<string, mixed>> $contracts         EmploymentContract rows.
	 * @param DateTimeImmutable          $date              The day.
	 * @param float                      $fullTimeHoursWeek Hours of one FTE.
	 *
	 * @return array{date: string, contracts: list<array<string, mixed>>, totalHoursPerWeek: float, totalFte: float, concurrent: bool}
	 *
	 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-003
	 */
	public function activeEmploymentsOn(string $employeeId, array $contracts, DateTimeImmutable $date, float $fullTimeHoursWeek): array;
}//end interface
