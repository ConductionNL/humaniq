<?php

/**
 * Contract of ExpenseSettings
 *
 * The public surface of ExpenseSettings that other components call, so they depend
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
 * Contract of ExpenseSettings.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExpenseSettingsInterface {
	/**
	 * The route an approved claim takes by default, `payroll` or `direct`
	 * (payroll-expenses-and-allowances D1), configurable via app config key
	 * `expense_reimbursement_route`. Unset or unknown keeps today's direct route.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
	 */
	public function getExpenseReimbursementRoute(): string;

	/**
	 * expenses-travel-calculation: the rate per kilometre the employer pays
	 * for business and commuting travel. App config key
	 * `mileage_rate_per_km` (for example `0.30`); null when unset or not a
	 * positive number, and the caller then pays the tax-free rate from the
	 * rule corpus.
	 *
	 * @return float|null
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-001
	 */
	public function getMileageRatePerKm(): ?float;
}//end interface
