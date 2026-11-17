<?php

/**
 * Contract of RuleAuditService
 *
 * The public surface of RuleAuditService that other components call, so they depend
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
 * Contract of RuleAuditService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RuleAuditServiceInterface {
	/**
	 * Run the audit and return the structured report.
	 *
	 * @param array<string, mixed> $context Evaluation context (e.g. jurisdiction).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/time-attendance/spec.md#REQ-TA-004
	 */
	public function audit(array $context = []): array;

	/**
	 * Run the RuleEngine over EXACTLY one period's PayrollRun(s) + their
	 * payslips — the run-scoped corpus audit behind `occ humaniq:payroll:verify`
	 * (payroll-core-engine design.md D7): the same corpus that audits
	 * hand-entered data audits a computed run, so the engine has no private
	 * truth. The full cross-object context is built exactly as in `audit()`
	 * (the sibling indexes are cheap and keep every cross-object predicate
	 * working in the scoped run too).
	 *
	 * @param string $period Wage period (YYYY-MM).
	 * @param string|null $administrationId Only runs of this administration, or null for all.
	 * @param array<string, mixed> $context Evaluation context (e.g. jurisdiction).
	 *
	 * @return array<string, mixed> {runsChecked, payslipsChecked, violations: [{objectType, objectId, ruleId, severity, statement}], mandatoryViolations}.
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-006
	 */
	public function auditPayrollRunScope(string $period, ?string $administrationId = null, array $context = []): array;
}//end interface
