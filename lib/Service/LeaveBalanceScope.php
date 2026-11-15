<?php

/**
 * Leave Balance Scope
 *
 * One employee and leave type whose balances a projection recomputes.
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
 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Leave-taken-SHALL-draw-from-the-hours-that-lapse-first-(REQ-LEX-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One employee, one leave type, and the administered type it means.
 *
 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Leave-taken-SHALL-draw-from-the-hours-that-lapse-first-(REQ-LEX-001)
 */
final class LeaveBalanceScope {

	/**
	 * @param string                    $employeeId The employee.
	 * @param string                    $leaveType  The leave type code.
	 * @param array<string, mixed>|null $type       The administered LeaveType, or null.
	 */
	public function __construct(
		public readonly string $employeeId,
		public readonly string $leaveType,
		public readonly ?array $type = null,
	) {

	}//end __construct()

}//end class
