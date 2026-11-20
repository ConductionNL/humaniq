<?php

/**
 * Contract of PayrollGLPostService
 *
 * The public surface of PayrollGLPostService that other components call, so they depend
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
 * Contract of PayrollGLPostService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PayrollGLPostServiceInterface {
	/**
	 * Post every approved PayrollRun (optionally period-filtered) — the MVP
	 * trigger's entry point (design.md D5).
	 *
	 * @param string|null $period Only post runs for this wage period (YYYY-MM), or null for all.
	 *
	 * @return array<int, array<string, mixed>> One outcome array per selected run.
	 *
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-006
	 */
	public function postApprovedRuns(?string $period = null): array;

	/**
	 * Post a single PayrollRun: idempotency pre-check, duck-typed availability
	 * probe, balanced-journal construction, shillinq JournalEntry creation (or
	 * adoption via the journalNumber probe), and — on success — the run's GL
	 * fields + status advance.
	 *
	 * @param array<string, mixed> $run The PayrollRun object.
	 *
	 * @return array<string, mixed> Outcome: {runId, status, message, glPostId, journalEntryId}.
	 *
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-002
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-003
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-004
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-005
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
	 */
	public function postRun(array $run): array;
}//end interface
