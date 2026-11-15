<?php

/**
 * Wage Tax Election
 *
 * How the wage tax applies to one calculation: the table colour, whether the
 * loonheffingskorting is applied, and the 30%-ruling rate.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll
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
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

/**
 * Part of a {@see CalculationInput}.
 *
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-001
 */
final class WageTaxElection {

	/**
	 * @param string $taxTableColor `wit` or `groen`.
	 * @param bool $loonheffingskortingToegepast Whether the employee elected the loonheffingskorting.
	 * @param float $thirtyPercentRulingRate The applied 30%-ruling percentage (30-procent-regeling). Additive and defaults to `0.0`, so every pre-existing named-argument call site is unaffected: a positive rate drives the pack's `thirtyPercentExemption`/`belastbaarLoon` bindings, reducing the taxable base while leaving the net-fold's gross unchanged. `0.0` degrades the exemption to zero (non-ruling path byte-identical).
	 */
	public function __construct(
		public readonly string $taxTableColor,
		public readonly bool $loonheffingskortingToegepast,
		public readonly float $thirtyPercentRulingRate = 0.0,
	) {

	}//end __construct()
}//end class
