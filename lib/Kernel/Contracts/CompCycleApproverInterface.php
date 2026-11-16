<?php

/**
 * Contract of CompCycleApprover
 *
 * The public surface of CompCycleApprover that other components call, so they depend
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
 * Contract of CompCycleApprover.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CompCycleApproverInterface {
	/**
	 * Approve every proposed adjustment in the cycle the caller may approve.
	 *
	 * @param string $cycleId The CompReviewCycle id.
	 * @param string $callerUid Who approves.
	 * @param string|null $decisionReason An optional reason sent to every employee approved.
	 *
	 * @return array<string, mixed> Counts and one row per proposed adjustment.
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-002
	 */
	public function approveCycle(string $cycleId, string $callerUid, ?string $decisionReason = null): array;
}//end interface
