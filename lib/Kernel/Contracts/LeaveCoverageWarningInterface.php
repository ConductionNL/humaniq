<?php

/**
 * Contract of LeaveCoverageWarning
 *
 * The public surface of LeaveCoverageWarning that other components call, so they depend
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
 * Contract of LeaveCoverageWarning.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LeaveCoverageWarningInterface {
	/**
	 * The warning for one request, as `GET /api/leave/coverage` answers it.
	 *
	 * A requester with no org unit has no administered minimum, which is said
	 * plainly (`reason: no-org-unit`) rather than answered as "coverage is fine".
	 *
	 * @param array<string, mixed>   $request The LeaveRequest about to be approved.
	 * @param DateTimeImmutable|null $today   The day placements must be active on; today when null.
	 *
	 * @return array<string, mixed> `belowMinimum`, `dates`, `message`, and `reason` when there is no unit.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
	 */
	public function forRequest(array $request, ?DateTimeImmutable $today = null): array;

	/**
	 * The warning an approver accepts by approving: its message when the
	 * request takes the unit below its minimum, null when there is none.
	 *
	 * @param array<string, mixed>   $request The LeaveRequest being approved.
	 * @param DateTimeImmutable|null $today   The day placements must be active on; today when null.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
	 */
	public function acceptedOnApproval(array $request, ?DateTimeImmutable $today = null): ?string;
}//end interface
