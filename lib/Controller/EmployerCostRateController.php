<?php

/**
 * Employer cost-rate read API.
 *
 * The HTTP surface of {@see EmployeeCostRateService} — the humaniq half of
 * ADR-081's `hourlyCost = wageCost + Σ additions`. Shillinq is the only
 * intended consumer today.
 *
 * @category Controller
 * @package  OCA\Humaniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://humaniq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\CostRateAccess;
use OCA\Humaniq\Service\EmployeeCostRateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Serve one employee's loaded employer cost per hour.
 *
 * WHY THIS EXISTS. ADR-081 puts the wage half of an hour's cost in humaniq and
 * the ledger-derived half (overhead, equipment) in Shillinq, because Shillinq
 * owns the general ledger those pools live in. humaniq has had
 * {@see EmployeeCostRateService} since #68 and it already accepts
 * `extraAdditions` for exactly that caller — but the service had no HTTP
 * surface, so nothing outside this app could reach it. This is that surface.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not compute or store Shillinq's
 * additions, and it does not write anything. A cost rate is derived on read
 * from the contract, so persisting it would create a second copy that goes
 * stale the moment a contract or a CLA changes. The consumer sends its own
 * additions per request and gets a total back.
 *
 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
 */
class EmployerCostRateController extends Controller {

	/**
	 * Wire collaborators.
	 *
	 * @param IRequest $request The request.
	 * @param EmployeeCostRateService $costRates The cost-rate resolver.
	 * @param CostRateAccess $access The employee and contract a rate is computed from, under the caller's access or as their project manager.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly EmployeeCostRateService $costRates,
		private readonly CostRateAccess $access,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Resolve the employer cost per hour for one employee.
	 *
	 * `additions` accepts the caller's own per-hour amounts — Shillinq's
	 * ledger-derived overhead and equipment pools. They are merged with the
	 * employee's stored additions by the service, which also enforces the
	 * rules that make the sum defensible: an addition states a fixed amount
	 * OR a percentage of the wage base and never both, a percentage resolves
	 * against the wage base rather than a running total, and an overtime
	 * addition cannot be stacked on a wage base that already blends overtime.
	 *
	 * @param string|null $employeeId The Employee object id.
	 * @param string|null $period Costing period `YYYY-MM`; defaults to the current month.
	 * @param array<int, array<string, mixed>> $additions Caller-computed additions, per ADR-081.
	 *
	 * @return JSONResponse The resolved rate, or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
	 */
	#[NoAdminRequired]
	public function show(?string $employeeId = null, ?string $period = null, array $additions = []): JSONResponse {
		$employeeId = trim((string)$employeeId);
		if ($employeeId === '') {
			return new JSONResponse(['error' => 'employeeId is required.'], Http::STATUS_BAD_REQUEST);
		}

		// No-admin-idor guard (ADR-005 Rule 3): the employee must resolve
		// through OpenRegister's ObjectService under the CALLER's RBAC before
		// any cost figure is produced. A salary-derived rate is exactly the
		// kind of value an unguarded id would leak, so an unresolvable or
		// unauthorised id must be indistinguishable from a missing one.
		$employee = $this->access->employeeForCaller(employeeId: $employeeId);
		$period = ($period ?? date('Y-m'));

		try {
			$rate = null;
			if ($employee !== null) {
				$rate = $this->costRates->resolve(
					employee: $employee,
					contract: $this->access->contractForCaller(employee: $employee, period: $period),
					period: $period,
					extraAdditions: $additions
				);
			}

			if ($rate === null) {
				// DECISIONS row 8 (Ruben, 29 Sep 2026): a project manager of a
				// project the employee works on gets the derived rate while the
				// salary stays hidden. Only reached when the caller's own read
				// gave no employee or no wage base, so a caller who can read the
				// salary keeps the full answer and anyone else learns nothing.
				$managed = $this->projectManagerRate(employeeId: $employeeId, period: $period, additions: $additions);
				if ($managed !== null) {
					return new JSONResponse($managed);
				}
			}
		} catch (\InvalidArgumentException $e) {
			// The service refuses an indefensible composition — an override
			// with no reason, an addition with no basis, overtime stacked on
			// an overtime-blended base. That is a client error, not a 500.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('EmployerCostRateController: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Could not resolve the cost rate.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($employee === null) {
			return new JSONResponse(['error' => 'Employee not found.'], Http::STATUS_NOT_FOUND);
		}

		if ($rate === null) {
			// Additions alone are never a cost rate: an hour with overhead and
			// no wage is not an hour anyone worked. 409 rather than 404 —
			// the employee exists, the wage base does not.
			return new JSONResponse(
				[
					'error' => 'No wage base: the employee has neither a reasoned override nor a contract this period can be costed from.',
					'employeeId' => $employeeId,
				],
				Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse(
			[
				'employeeId' => $employeeId,
				'period' => $period,
				'currency' => 'EUR',
			] + $rate
		);
	}//end show()

	/**
	 * The reduced answer for a project manager of a project the employee works
	 * on, or null when the caller is not one or no wage base exists.
	 *
	 * The employee and contract are read without the caller's field access and
	 * never leave this method: the answer carries the hourly figures only, not
	 * the salary, the contract or the wage basis (for an override that is HR's
	 * own free-text reason).
	 *
	 * @param string $employeeId The Employee object id.
	 * @param string $period Costing period `YYYY-MM`.
	 * @param array<int, array<string, mixed>> $additions Caller-computed additions.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md#Requirement:-A-project-manager-gets-the-derived-rate-of-the-people-on-the-project,-never-their-salary-(REQ-ECR-PM)
	 */
	private function projectManagerRate(string $employeeId, string $period, array $additions): ?array {
		$employee = $this->access->employeeManagedByCaller(employeeId: $employeeId);
		if ($employee === null) {
			return null;
		}

		$rate = $this->costRates->resolve(
			employee: $employee,
			contract: $this->access->contractFor(employeeId: $employeeId, period: $period),
			period: $period,
			extraAdditions: $additions
		);
		if ($rate === null) {
			return null;
		}

		return [
			'employeeId' => $employeeId,
			'period' => $period,
			'currency' => 'EUR',
			'access' => 'project-manager',
			'totalCentsPerHour' => $rate['totalCentsPerHour'],
			'wageCostCents' => $rate['wageCostCents'],
			'additions' => $rate['additions'],
		];
	}//end projectManagerRate()
}//end class
