<?php

/**
 * Contract of PayrollNetPayService
 *
 * The public surface of PayrollNetPayService that other components call, so they depend
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
 * Contract of PayrollNetPayService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PayrollNetPayServiceInterface {
	/**
	 * Process every payable PayrollRun (optionally period-filtered) -- the
	 * MVP trigger's entry point.
	 *
	 * @param string|null $period Only process runs for this wage period (YYYY-MM), or null for all.
	 *
	 * @return array<int, array<string, mixed>> One outcome array per selected run.
	 *
	 * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-007
	 */
	public function processPayableRuns(?string $period = null): array;

	/**
	 * Process a single PayrollRun: idempotency pre-check, duck-typed
	 * availability probe, fail-closed line collection, shillinq PaymentRun
	 * creation (or adoption via the runNumber probe). humaniq writes NOTHING
	 * back to the PayrollRun (design.md D4).
	 *
	 * @param array<string, mixed> $run The PayrollRun object.
	 *
	 * @return array<string, mixed> Outcome: {runId, status, message, batchId, paymentRunId}.
	 *
	 * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-003
	 * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-004
	 * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-005
	 * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
	 */
	public function processRun(array $run): array;
}//end interface
