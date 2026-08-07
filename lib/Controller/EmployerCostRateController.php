<?php

/**
 * Employer cost-rate read API.
 *
 * The HTTP surface of {@see EmployeeCostRateService} — the hrmq half of
 * ADR-081's `hourlyCost = wageCost + Σ additions`. Shillinq is the only
 * intended consumer today.
 *
 * @category Controller
 * @package  OCA\Hrmq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://hrmq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Controller;

use OCA\Hrmq\AppInfo\Application;
use OCA\Hrmq\Service\EmployeeCostRateService;
use OCA\Hrmq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Serve one employee's loaded employer cost per hour.
 *
 * WHY THIS EXISTS. ADR-081 puts the wage half of an hour's cost in hrmq and
 * the ledger-derived half (overhead, equipment) in Shillinq, because Shillinq
 * owns the general ledger those pools live in. hrmq has had
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
class EmployerCostRateController extends Controller
{

    /**
     * Wire collaborators.
     *
     * @param IRequest                $request     The request.
     * @param ContainerInterface      $container   DI container, for the RBAC-guarded ObjectService resolve.
     * @param EmployeeCostRateService $costRates   The cost-rate resolver.
     * @param SettingsService         $settings    Register-slug lookup.
     * @param LoggerInterface         $logger      PSR logger.
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
        private readonly LoggerInterface $logger
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
     * @param string|null                      $employeeId The Employee object id.
     * @param string|null                      $period     Costing period `YYYY-MM`; defaults to the current month.
     * @param array<int, array<string, mixed>> $additions  Caller-computed additions, per ADR-081.
     *
     * @return JSONResponse The resolved rate, or an error.
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/employer-hourly-cost-rate/spec.md
     */
    #[NoAdminRequired]
    public function show(?string $employeeId=null, ?string $period=null, array $additions=[]): JSONResponse
    {
        $employeeId = trim((string) $employeeId);
        if ($employeeId === '') {
            return new JSONResponse(['error' => 'employeeId is required.'], Http::STATUS_BAD_REQUEST);
        }

        // No-admin-idor guard (ADR-005 Rule 3): the employee must resolve
        // through OpenRegister's ObjectService under the CALLER's RBAC before
        // any cost figure is produced. A salary-derived rate is exactly the
        // kind of value an unguarded id would leak, so an unresolvable or
        // unauthorised id must be indistinguishable from a missing one.
        $employee = $this->findEmployeeForCaller($employeeId);
        if ($employee === null) {
            return new JSONResponse(['error' => 'Employee not found.'], Http::STATUS_NOT_FOUND);
        }

        $contract = $this->activeContract($employee);

        try {
            $rate = $this->costRates->resolve(
                employee: $employee,
                contract: $contract,
                period: ($period ?? date('Y-m')),
                extraAdditions: $additions
            );
        } catch (\InvalidArgumentException $e) {
            // The service refuses an indefensible composition — an override
            // with no reason, an addition with no basis, overtime stacked on
            // an overtime-blended base. That is a client error, not a 500.
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('EmployerCostRateController: '.$e->getMessage());
            return new JSONResponse(['error' => 'Could not resolve the cost rate.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($rate === null) {
            // Additions alone are never a cost rate: an hour with overhead and
            // no wage is not an hour anyone worked. 409 rather than 404 —
            // the employee exists, the wage base does not.
            return new JSONResponse(
                [
                    'error'      => 'No wage base: the employee has neither a reasoned override nor a contract this period can be costed from.',
                    'employeeId' => $employeeId,
                ],
                Http::STATUS_CONFLICT
            );
        }

        return new JSONResponse(
            [
                'employeeId' => $employeeId,
                'period'     => ($period ?? date('Y-m')),
                'currency'   => 'EUR',
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
    private function findEmployeeForCaller(string $employeeId): ?array
    {
        try {
            $employee = $this->objectService()->find(
                id: $employeeId,
                register: $this->settings->getRegisterSlug(),
                schema: 'Employee'
            );
        } catch (\Throwable $e) {
            $this->logger->info('EmployerCostRateController: employee '.$employeeId.' not retrievable: '.$e->getMessage());
            return null;
        }

        if ($employee === null) {
            return null;
        }

        return $this->toArray($employee);
    }//end findEmployeeForCaller()


    /**
     * Find the employee's active EmploymentContract, if any.
     *
     * Returns null rather than throwing when none is found: the service then
     * falls back to a reasoned override, and answers null itself if there is
     * no wage base at all. Resolving the contract HERE rather than letting the
     * service pick one is deliberate — the service's own docblock notes that
     * taking the contract from the caller stops it silently costing against a
     * different contract than the caller believes it is using.
     *
     * @param array<string, mixed> $employee The employee.
     *
     * @return array<string, mixed>|null The active contract, or null.
     *
     * @spec openspec/specs/employer-hourly-cost-rate/spec.md
     */
    private function activeContract(array $employee): ?array
    {
        $employeeId = (string) ($employee['id'] ?? '');
        if ($employeeId === '') {
            return null;
        }

        try {
            $found = $this->objectService()->findAll(
                register: $this->settings->getRegisterSlug(),
                schema: 'EmploymentContract',
                filters: ['employee' => $employeeId, 'status' => 'active']
            );
        } catch (\Throwable $e) {
            $this->logger->info('EmployerCostRateController: no active contract for '.$employeeId.': '.$e->getMessage());
            return null;
        }

        foreach (($found ?? []) as $row) {
            return $this->toArray($row);
        }

        return null;
    }//end activeContract()


    /**
     * The OpenRegister ObjectService, under the caller's ambient RBAC.
     *
     * @return mixed The ObjectService.
     *
     * @spec openspec/specs/employer-hourly-cost-rate/spec.md
     */
    private function objectService(): mixed
    {
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
    private function toArray(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            return (array) $row->jsonSerialize();
        }

        return (array) $row;
    }//end toArray()
}//end class
