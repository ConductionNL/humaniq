<?php

/**
 * Contract of FrequentAbsenceService
 *
 * The public surface of FrequentAbsenceService that other components call, so they depend
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
 * Contract of FrequentAbsenceService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FrequentAbsenceServiceInterface {
	/**
	 * Evaluate a case and stamp the count and signal on it.
	 *
	 * @param string $caseId The SickLeaveCase id.
	 * @param array<string, mixed> $case The SickLeaveCase as saved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-003
	 */
	public function stamp(string $caseId, array $case): void;
}//end interface
