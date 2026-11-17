<?php

/**
 * Contract of CompCollectiveService
 *
 * The public surface of CompCollectiveService that other components call, so they depend
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
 * Contract of CompCollectiveService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CompCollectiveServiceInterface {
	/**
	 * Propose one adjustment per employee the cycle covers.
	 *
	 * @param string $cycleId The CompReviewCycle id.
	 * @param string $callerUid Who proposes; stamped as proposedBy.
	 * @param bool $dryRun Count and list, write nothing.
	 * @param array<int, string> $employeeIds A hand-picked selection that replaces the cycle's scope; empty uses the scope.
	 *
	 * @return array<string, mixed> The outcome: counts, skipped employees with a reason, and the first proposal rows.
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
	 */
	public function proposeForCycle(string $cycleId, string $callerUid, bool $dryRun = false, array $employeeIds = []): array;
}//end interface
