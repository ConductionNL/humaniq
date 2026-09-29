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
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
	 * @param ContainerInterface $container DI container, for the RBAC-guarded ObjectService resolve.
	 * @param EmployeeCostRateService $costRates The cost-rate resolver.
	 * @param SettingsService $settings Register-slug lookup.
	 * @param CostRateAccess $access The project-manager path and the contract choice.
	 * @param IUserSession $userSession The caller.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly EmployeeCostRateService $costRates,
		private readonly SettingsService $settings,
		private readonly CostRateAccess $access,
		private readonly IUserSession $userSession,
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
		$employee = $this->findEmployeeForCaller($employeeId);
		$period = ($period ?? date('Y-m'));

		try {
			$rate = null;
			if ($employee !== null) {
				$rate = $this->costRates->resolve(
					employee: $employee,
					contract: $this->activeContract(employee: $employee, period: $period),
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
	private function findEmployeeForCaller(string $employeeId): ?array {
		try {
			$employee = $this->objectService()->find(
				id: $employeeId,
				register: $this->settings->getRegisterSlug(),
				schema: 'Employee'
			);
		} catch (\Throwable $e) {
			$this->logger->info('EmployerCostRateController: employee ' . $employeeId . ' not retrievable: ' . $e->getMessage());
			return null;
		}

		if ($employee === null) {
			return null;
		}

		return $this->toArray($employee);
	}//end findEmployeeForCaller()

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
		$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		$employee = $this->access->employeeManagedBy(uid: $uid, employeeId: $employeeId);
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
	private function activeContract(array $employee, string $period): ?array {
		$employeeId = (string)($employee['id'] ?? '');
		if ($employeeId === '') {
			return null;
		}

		try {
			$found = $this->objectService()
				->setRegister($this->settings->getRegisterSlug())
				->setSchema('EmploymentContract')
				->findAll(['limit' => 100, 'filters' => ['employeeId' => $employeeId]]);
		} catch (\Throwable $e) {
			$this->logger->info('EmployerCostRateController: no contract for ' . $employeeId . ': ' . $e->getMessage());
			return null;
		}

		$rows = array_map(fn (mixed $row): array => $this->toArray($row), (is_array($found) === true ? $found : []));

		return $this->access->pickActive(rows: array_values($rows), employeeId: $employeeId, period: $period);
	}//end activeContract()

	/**
	 * The OpenRegister ObjectService, under the caller's ambient RBAC.
	 *
	 * @return mixed The ObjectService.
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. Unguarded, an
		// instance without OpenRegister gets a container exception naming a
		// class the admin has never heard of; guarded, it is told which app to
		// install — which is rule 3's promise that the app still explains
		// itself.
		if ($this->settings->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * Normalise an ObjectService row to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The row as an array.
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return (array)$row;
	}//end toArray()
}//end class
