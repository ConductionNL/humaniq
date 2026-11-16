<?php

/**
 * Contract of RightToWorkService
 *
 * The public surface of RightToWorkService that other components call, so they depend
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
 * Contract of RightToWorkService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RightToWorkServiceInterface {
	public const RESULT_PASS = 'geslaagd';

	/**
	 * Decide one right-to-work check.
	 *
	 * @param array<string, mixed> $input startDate, documentType, nationality, documentExpiry,
	 *                                    endorsement, twvValidUntil, and optionally mrz (lines, as
	 *                                    an array or one newline-separated string).
	 *
	 * @return array<string, mixed> result, reasonCode, reason, method and the fields the decision used.
	 *
	 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-003
	 */
	public function decide(array $input): array;
}//end interface
