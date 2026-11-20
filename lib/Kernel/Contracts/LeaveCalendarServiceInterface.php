<?php

/**
 * Contract of LeaveCalendarService
 *
 * The public surface of LeaveCalendarService that other components call, so they depend
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
 * Contract of LeaveCalendarService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LeaveCalendarServiceInterface {
	/**
	 * Run the sync: upsert one all-day VEVENT per in-scope approved
	 * LeaveRequest / SickLeaveCase, remove events of LeaveRequests that left
	 * scope, and reconcile orphaned `humaniq-`-prefixed events whose source was
	 * hard-deleted from the register (design.md D1-D6).
	 *
	 * @param string|null $from Optional ISO date (Y-m-d). Bounds the upsert set
	 *                          to LeaveRequests whose endDate is on/after this
	 *                          date and hersteld SickLeaveCases whose
	 *                          recoveredDate is on/after this date; open
	 *                          SickLeaveCases (no end date to bound on) and
	 *                          the removal/reconciliation passes are always
	 *                          unbounded (design.md D2/REQ-LC-005/REQ-LC-007).
	 *
	 * @return array<int, array<string, mixed>> One outcome row per touched
	 *                                          source, or a single
	 *                                          `skipped-no-calendar` row.
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-001
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-002
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-003
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-005
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-006
	 */
	public function sync(?string $from = null): array;
}//end interface
