<?php

/**
 * Contract of ProformaPayslipService
 *
 * The public surface of ProformaPayslipService that other components call, so they depend
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
 * Contract of ProformaPayslipService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProformaPayslipServiceInterface {
	/**
	 * Compute the full gross-to-net breakdown for one hypothetical input set.
	 * Holds no state between calls, calls no ObjectService method and writes
	 * no object — the entire persistence contract is "nothing stored"
	 * (design.md D1).
	 *
	 * @param array<string, mixed> $params Raw hypothetical params: gross, table, loonheffingskorting,
	 *                                     dateOfBirth, period, parttime, bijzonder, aof, whk.
	 *
	 * @return array<string, mixed> The euro-decimal breakdown.
	 *
	 * @throws \InvalidArgumentException When any input is malformed (the controller maps this to HTTP 400).
	 *
	 * @spec openspec/specs/proforma-payslip/spec.md#REQ-PRO-001
	 * @spec openspec/specs/proforma-payslip/spec.md#REQ-PRO-006
	 */
	public function simulate(array $params): array;
}//end interface
