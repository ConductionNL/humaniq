<?php

/**
 * Contract of PayrollMutationService
 *
 * The public surface of PayrollMutationService that other components call, so they depend
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
 * Contract of PayrollMutationService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PayrollMutationServiceInterface {
	/**
	 * Compute the run-to-run diff (design.md D1-D5). Resolves `toRunId`
	 * (required), auto-resolves `fromRunId` when null (D4), refuses a
	 * cross-administration pair (D4), and takes the first-run path when no
	 * prior run exists (D5). Never persists — call `persist()` with the
	 * result to upsert the `PayrollMutationReport` (D7).
	 *
	 * @param string $toRunId The PayrollRun being reviewed.
	 * @param string|null $fromRunId The baseline PayrollRun, or null to auto-resolve the prior period.
	 *
	 * @return array<string, mixed> Outcome: {status, message, report} — `report` is the full diff payload (see buildReport()), null on failure/refusal.
	 *
	 * @spec openspec/specs/payroll-mutation-reports/spec.md#REQ-MUT-001
	 * @spec openspec/specs/payroll-mutation-reports/spec.md#REQ-MUT-006
	 * @spec openspec/specs/payroll-mutation-reports/spec.md#REQ-MUT-007
	 */
	public function diff(string $toRunId, ?string $fromRunId = null): array;

	/**
	 * Persist a diff() report as an idempotent `PayrollMutationReport`,
	 * probed by (fromRunId, toRunId) and upserted in place (design.md D7).
	 *
	 * @param array<string, mixed> $report The `report` payload from `diff()`'s outcome.
	 *
	 * @return array<string, mixed> Outcome: {status, message, reportId}.
	 *
	 * @spec openspec/specs/payroll-mutation-reports/spec.md#REQ-MUT-005
	 */
	public function persist(array $report): array;
}//end interface
