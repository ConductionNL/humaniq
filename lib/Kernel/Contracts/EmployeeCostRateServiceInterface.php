<?php

/**
 * Contract of EmployeeCostRateService
 *
 * The public surface of EmployeeCostRateService that other components call, so they depend
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
 * Contract of EmployeeCostRateService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmployeeCostRateServiceInterface {
	/**
	 * Resolve the employer cost per hour for one employee.
	 *
	 * The caller supplies the already-loaded Employee and its active
	 * EmploymentContract, so this cannot silently pick a different contract
	 * than the caller believes it is using.
	 *
	 * @param array<string, mixed> $employee The Employee object as an array.
	 * @param array<string, mixed>|null $contract The active EmploymentContract as an array.
	 * @param string $period Costing period as `YYYY-MM`, for the tax year the proforma runs against.
	 * @param array<int, array<string, mixed>> $extraAdditions Additions supplied by a caller that computes them — Shillinq's ledger-derived overhead/equipment. Merged with the employee's own stored additions.
	 *
	 * @return array{
	 *     totalCentsPerHour: int, wageCostCents: int, wageSource: string, wageBasis: string,
	 *     wageBaseBlendsOvertime: bool, additions: array<int, array{key: string, centsPerHour: int, source: string, basis: string}>
	 * }|null Null when no wage base can be established — additions alone are
	 *        never a cost rate, because an hour with overhead but no wage is
	 *        not an hour anyone worked.
	 *
	 * @throws \InvalidArgumentException When an override amount is set without a reason, an addition
	 *                                  carries no basis, or an overtime addition is applied to an
	 *                                  overtime-blended base.
	 */
	public function resolve(
		array $employee,
		?array $contract = null,
		string $period = '',
		array $extraAdditions = [],
	): ?array;
}//end interface
