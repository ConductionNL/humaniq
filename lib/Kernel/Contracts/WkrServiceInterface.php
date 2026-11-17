<?php

/**
 * Contract of WkrService
 *
 * The public surface of WkrService that other components call, so they depend
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
 * Contract of WkrService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WkrServiceInterface {
	/**
	 * Compute and idempotently upsert the WkrAssessment for one
	 * (administrationId, year) pair (spec.md REQ-WKR-003). Reads the
	 * cross-object fiscale loonsom + vrije-ruimte-used aggregate, computes the
	 * available vrije ruimte / excess / eindheffingDue / status via
	 * `NlWkrChecks`'s shared tranche arithmetic, and upserts the persisted
	 * WkrAssessment keyed on (administrationId, year) — recomputing for the
	 * same pair updates the existing record in place, never duplicates.
	 *
	 * @param string $administrationId The administration to assess.
	 * @param int $year The fiscal year to assess.
	 *
	 * @return array<string, mixed> Outcome: {status, message, assessment}.
	 *
	 * @spec openspec/specs/wkr-administration/spec.md#REQ-WKR-003
	 */
	public function assess(string $administrationId, int $year): array;

	/**
	 * `--all` path (spec.md REQ-WKR-005): assess every distinct
	 * (administrationId, year) pair found across the payslips.
	 *
	 * @return array<int, array<string, mixed>> One outcome per (administrationId, year) pair.
	 *
	 * @spec openspec/specs/wkr-administration/spec.md#REQ-WKR-005
	 */
	public function assessAll(): array;
}//end interface
