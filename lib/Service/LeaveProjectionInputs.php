<?php

/**
 * Leave Projection Inputs
 *
 * The records one leave balance projection reads, loaded once per projection.
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
 * The requests, balances, measuring date and working time of one projection.
 *
 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Leave-taken-SHALL-draw-from-the-hours-that-lapse-first-(REQ-LEX-001)
 */
final class LeaveProjectionInputs {

	/**
	 * @param array<int, array<string, mixed>> $requests    Every LeaveRequest.
	 * @param array<int, array<string, mixed>> $balances    The LeaveBalances in scope.
	 * @param string                           $today       The date lapses are measured on, `Y-m-d`.
	 * @param array<string, mixed>|null        $workingTime Patterns, non-working times and calendar dates.
	 */
	public function __construct(
		public readonly array $requests,
		public readonly array $balances,
		public readonly string $today,
		public readonly ?array $workingTime = null,
	) {

	}//end __construct()

	/**
	 * The same inputs over other balances.
	 *
	 * @param array<int, array<string, mixed>> $balances The balances.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Leave-taken-SHALL-draw-from-the-hours-that-lapse-first-(REQ-LEX-001)
	 */
	public function withBalances(array $balances): self {
		return new self($this->requests, $balances, $this->today, $this->workingTime);
	}//end withBalances()

}//end class
