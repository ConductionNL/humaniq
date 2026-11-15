<?php

/**
 * Humaniq LeaveAllocationRun
 *
 * The state of allocating one employee's leave uses over the balances of one
 * leave type: the balances, the leave type, the buckets being consumed and the
 * per-balance result so far.
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
 * One allocation of leave uses over balances, inside LeaveAllocationCalculator.
 *
 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Leave-taken-SHALL-draw-from-the-hours-that-lapse-first-(REQ-LEX-001)
 */
final class LeaveAllocationRun {

	/**
	 * Every balance of the employee and leave type.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $balances = [];

	/**
	 * The administered LeaveType, or null for the defaults.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $leaveType = null;

	/**
	 * The buckets of every balance, consumed in place.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $buckets = [];

	/**
	 * The per-balance result so far, keyed by balance id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $result = [];
}//end class
