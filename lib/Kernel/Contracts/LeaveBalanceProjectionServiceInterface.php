<?php

/**
 * Contract of LeaveBalanceProjectionService
 *
 * The public surface of LeaveBalanceProjectionService that other components call, so they depend
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
 * Contract of LeaveBalanceProjectionService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LeaveBalanceProjectionServiceInterface {
	/**
	 * Recompute every balance one changed LeaveRequest can affect.
	 *
	 * A request is projected onto its own employee/leaveType for each calendar
	 * year its range touches, so a request spanning New Year restates both
	 * years. Never creates a balance: when none matches, this logs at info and
	 * writes nothing, leaving auto provisioning to the named follow-up
	 * `leave-balance-auto-provision`.
	 *
	 * @param array<string, mixed> $request The changed LeaveRequest row.
	 * @param string|null $today The date lapses are measured on, `Y-m-d`; today when null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LEAVE-POST-001
	 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Leave-taken-SHALL-draw-from-the-hours-that-lapse-first-(REQ-LEX-001)
	 */
	public function projectForRequest(array $request, ?string $today = null): void;

	/**
	 * Recompute every balance of every employee and leave type, and so apply
	 * the lapses due on `$today`. Run daily by LeaveAccrualJob.
	 *
	 * @param string $today The date lapses are measured on, `Y-m-d`.
	 *
	 * @return int The number of balances written.
	 *
	 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Statutory-hours-SHALL-lapse-on-their-expiry-date-unless-HR-waives-it-(REQ-LEX-003)
	 */
	public function recomputeAll(string $today): int;
}//end interface
