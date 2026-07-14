<?php

/**
 * Payroll Controller
 *
 * Backs the `PayrollRunDetail` manifest page action "(Her)berekenen"
 * (payroll-core-engine design.md D6): a single POST endpoint that resolves
 * the posted `runId` through OpenRegister's ObjectService under the caller's
 * ambient RBAC BEFORE any computation (the DocumentController no-admin-idor
 * pattern — an unknown or unauthorized runId never reaches the engine, and
 * both collapse to the same 404 so existence is never leaked), refuses
 * non-draft runs (400 — approved/posted/paid runs are booked truth consumed
 * by glpost/netpay), then delegates the actual recalculation to
 * `PayrollRunService::recalculateRun()`. ONE endpoint, no CRUD (ADR-022 —
 * the run pages read/write the register declaratively via the object store).
 *
 * @category Controller
 * @package  OCA\Hrmq\Controller
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
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-008
 */

declare(strict_types=1);

namespace OCA\Hrmq\Controller;

use OCA\Hrmq\AppInfo\Application;
use OCA\Hrmq\Service\PayrollRunService;
use OCA\Hrmq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guarded endpoint that (re)calculates one draft payroll run.
 */
class PayrollController extends Controller
{


    /**
     * @param IRequest           $request           The request object.
     * @param ContainerInterface $container         DI container for the RBAC-guarded ObjectService resolve.
     * @param PayrollRunService  $payrollRunService The draft-run generation service.
     * @param SettingsService    $settingsService   The register-slug source.
     * @param LoggerInterface    $logger            Logger.
     */
    public function __construct(
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly PayrollRunService $payrollRunService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()


    /**
     * `POST /api/payroll/calculate` — recalculate one draft PayrollRun. The
     * posted `runId` must resolve through ObjectService under the caller's
     * RBAC before anything computes (unknown/unauthorized -> 404); non-draft
     * runs are refused (400) before the service is invoked.
     *
     * @param string|null $runId The PayrollRun id (row-scoped, `@objectId` from the manifest action).
     *
     * @return JSONResponse The recalculation outcome, 400 on a missing runId or non-draft run, 404 when the run does not resolve.
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-008
     */
    #[NoAdminRequired]
    public function calculate(?string $runId=null): JSONResponse
    {
        $runId = trim((string) $runId);
        if ($runId === '') {
            return new JSONResponse(['error' => 'runId is verplicht.'], Http::STATUS_BAD_REQUEST);
        }

        // No-admin-idor guard (ADR-005 Rule 3): the run must resolve through
        // OpenRegister's ObjectService under the caller's RBAC before any
        // computation — an unresolvable/unauthorized id never reaches the
        // engine.
        $run = $this->authorizeRun($runId);
        if ($run === null) {
            return new JSONResponse(['error' => 'Loonrun niet gevonden.'], Http::STATUS_NOT_FOUND);
        }

        $status = (string) ($run['status'] ?? '');
        if ($status !== 'draft') {
            return new JSONResponse(
                ['error' => 'Loonrun heeft status "'.$status.'" — alleen concept-runs kunnen herberekend worden.'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $result = $this->payrollRunService->recalculateRun($runId);

        if ((string) $result['status'] === 'failed') {
            return new JSONResponse($result, Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($result);

    }//end calculate()


    /**
     * Resolve the posted runId through OpenRegister's ObjectService under the
     * caller's ambient RBAC (default $_rbac=true) — the no-admin-idor guard
     * for this endpoint (the DocumentController::authorizeContract pattern).
     * Returns null when the run does not exist OR the caller's RBAC denies it
     * (both collapse to the same 404 so existence is never leaked).
     *
     * @param string $runId The PayrollRun id.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-008
     */
    private function authorizeRun(string $runId): ?array
    {
        try {
            $run = $this->objectService()->find(
                id: $runId,
                register: $this->settingsService->getRegisterSlug(),
                schema: 'PayrollRun'
            );
        } catch (\Throwable $e) {
            $this->logger->info('PayrollController: loonrun '.$runId.' kon niet worden opgehaald: '.$e->getMessage());
            return null;
        }

        if ($run === null) {
            return null;
        }

        return $this->toArray($run);

    }//end authorizeRun()


    /**
     * @return mixed The OpenRegister ObjectService, resolved with the caller's ambient RBAC (default $_rbac=true).
     */
    private function objectService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()


    /**
     * Normalise an ObjectService row (entity or array) to an array.
     *
     * @param mixed $row The row.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            return (array) $row->jsonSerialize();
        }

        return [];

    }//end toArray()


}//end class
