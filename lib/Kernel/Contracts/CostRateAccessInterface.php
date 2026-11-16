<?php

/**
 * Contract of CostRateAccess
 *
 * The public surface of CostRateAccess that other components call, so they depend
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
 * Contract of CostRateAccess.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CostRateAccessInterface {
	/**
	 * Look the employee up under the caller's ambient RBAC.
	 *
	 * NAMED AS A LOOKUP, NOT A GUARD, BECAUSE THAT IS WHAT IT IS. This method
	 * makes no authorization decision — `ObjectService` does, by resolving (or
	 * refusing to resolve) the id under the caller's own RBAC. Calling it
	 * `authorizeEmployee` claimed a decision it does not make, and gate-8
	 * flagged the resulting `catch (\Throwable) { return null; }` in an
	 * auth-named method as a possible fail-open resolver.
	 *
	 * That gate is right to be suspicious of the shape. The defect it exists
	 * for is decidesk's `getAuthorizationService()`, which returned null on
	 * Throwable while its caller wrote `if ($auth !== null) { check }` — so an
	 * unavailable service silently meant NO CHECK.
	 *
	 * ⚠️ THE CONTRACT HERE IS THE OPPOSITE, AND CALLERS MUST KEEP IT THAT WAY:
	 * null means DENY. The only caller answers 404 on null, before any
	 * salary-derived figure is produced. A future caller that treats null as
	 * "skip the lookup and carry on" would turn this into the very fail-open
	 * the gate is named after.
	 *
	 * @param string $employeeId The Employee object id.
	 *
	 * @return array<string, mixed>|null The employee, or null when absent OR unauthorised — the two are deliberately indistinguishable.
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
	 */
	public function employeeForCaller(string $employeeId): ?array;

	/**
	 * Find the employee's EmploymentContract that runs in the period, under the
	 * caller's RBAC, if any.
	 *
	 * Returns null rather than throwing when none is found: the service then
	 * falls back to a reasoned override, and answers null itself if there is
	 * no wage base at all. Resolving the contract HERE rather than letting the
	 * service pick one is deliberate — the service's own docblock notes that
	 * taking the contract from the caller stops it silently costing against a
	 * different contract than the caller believes it is using.
	 *
	 * The lookup uses OpenRegister's real `findAll(array $config, ...)`
	 * signature. It used to pass named `register`/`schema`/`filters`
	 * arguments that method does not have, and filtered on `employee` and
	 * `status`, which the contract schema does not carry: the error was caught
	 * and no contract-derived rate was ever produced.
	 *
	 * @param array<string, mixed> $employee The employee.
	 * @param string $period Costing period `YYYY-MM`.
	 *
	 * @return array<string, mixed>|null The contract, or null.
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
	 */
	public function contractForCaller(array $employee, string $period): ?array;

	/**
	 * The employee, read without the caller's field access, when the signed-in
	 * caller runs a project the employee works on; otherwise null.
	 *
	 * @param string $employeeId The Employee object id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md#Requirement:-A-project-manager-gets-the-derived-rate-of-the-people-on-the-project,-never-their-salary-(REQ-ECR-PM)
	 */
	public function employeeManagedByCaller(string $employeeId): ?array;

	/**
	 * The employee's contract for the period, read without the caller's field access.
	 *
	 * @param string $employeeId The Employee object id.
	 * @param string $period     The costing period, `YYYY-MM`.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md#Requirement:-A-project-manager-gets-the-derived-rate-of-the-people-on-the-project,-never-their-salary-(REQ-ECR-PM)
	 */
	public function contractFor(string $employeeId, string $period): ?array;
}//end interface
