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
 * payroll-mutation-reports (2026-07-14) adds `mutations()`, backing the
 * `PayrollRunDetail` "Mutatieoverzicht" action (design.md D6): the SAME
 * no-admin-idor RBAC-resolve-first pattern as `calculate()`, PLUS an
 * explicit admin/HR authorization check (payroll figures are sensitive —
 * a non-admin caller gets 403 before any RBAC resolve, so an unauthorized
 * principal cannot even probe for run existence), then delegates to
 * `PayrollMutationService::diff()` + `persist()`.
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
 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-008
 */

declare(strict_types=1);

namespace OCA\Hrmq\Controller;

use OCA\Hrmq\AppInfo\Application;
use OCA\Hrmq\Service\PayrollMutationService;
use OCA\Hrmq\Service\PayrollRunService;
use OCA\Hrmq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guarded endpoint that (re)calculates one draft payroll run.
 */
class PayrollController extends Controller
{


    /**
     * @param IRequest                $request                The request object.
     * @param ContainerInterface      $container              DI container for the RBAC-guarded ObjectService resolve.
     * @param PayrollRunService       $payrollRunService       The draft-run generation service.
     * @param PayrollMutationService  $payrollMutationService  The run-to-run diff service.
     * @param SettingsService         $settingsService         The register-slug source.
     * @param IUserSession            $userSession             The current user session (admin/HR check).
     * @param IGroupManager           $groupManager            To check the caller's admin membership (admin/HR gate).
     * @param LoggerInterface         $logger                  Logger.
     */
    public function __construct(
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly PayrollRunService $payrollRunService,
        private readonly PayrollMutationService $payrollMutationService,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
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
     * `POST /api/payroll/mutations` — generate + persist the run-to-run
     * payroll mutation report (payroll-mutation-reports design.md D6).
     * Non-admin/HR callers are refused with 403 BEFORE any RBAC resolve (an
     * unauthorized principal cannot even probe run existence via this
     * endpoint — payroll figures are sensitive). `toRunId` (and `fromRunId`
     * if given) must then resolve through ObjectService under the caller's
     * ambient RBAC (unknown/unauthorized -> 404, the `calculate()`
     * no-admin-idor pattern) before the service runs; a cross-administration
     * pair is refused with 400.
     *
     * @param string|null $toRunId   The PayrollRun being reviewed (row-scoped, `@objectId` from the manifest action).
     * @param string|null $fromRunId The baseline PayrollRun, or null to auto-resolve the prior period.
     *
     * @return JSONResponse {reportId, report} on success; 400 on a missing/invalid toRunId or cross-administration pair, 403 for a non-admin caller, 404 when a run does not resolve.
     *
     * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-008
     */
    #[NoAdminRequired]
    public function mutations(?string $toRunId=null, ?string $fromRunId=null): JSONResponse
    {
        if ($this->isAdminOrHr() === false) {
            return new JSONResponse(['error' => 'Alleen beheerders/HR mogen mutatierapporten genereren.'], Http::STATUS_FORBIDDEN);
        }

        $toRunId = trim((string) $toRunId);
        if ($toRunId === '') {
            return new JSONResponse(['error' => 'toRunId is verplicht.'], Http::STATUS_BAD_REQUEST);
        }

        // No-admin-idor guard (ADR-005 Rule 3): both runs must resolve
        // through ObjectService under the caller's RBAC before any diff runs.
        if ($this->authorizeRun($toRunId) === null) {
            return new JSONResponse(['error' => 'Loonrun niet gevonden.'], Http::STATUS_NOT_FOUND);
        }

        $fromRunId = trim((string) $fromRunId);
        if ($fromRunId !== '' && $this->authorizeRun($fromRunId) === null) {
            return new JSONResponse(['error' => 'Loonrun niet gevonden.'], Http::STATUS_NOT_FOUND);
        }

        $outcome = $this->payrollMutationService->diff($toRunId, ($fromRunId === '' ? null : $fromRunId));
        if ((string) $outcome['status'] !== 'ok') {
            return new JSONResponse(['error' => (string) $outcome['message']], Http::STATUS_BAD_REQUEST);
        }

        $persisted = $this->payrollMutationService->persist($outcome['report']);
        if ((string) $persisted['status'] !== 'ok') {
            return new JSONResponse(['error' => (string) $persisted['message']], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['reportId' => $persisted['reportId'], 'report' => $outcome['report']]);

    }//end mutations()


    /**
     * Whether the current caller is a Nextcloud admin — the admin/HR gate for
     * `mutations()` (design.md D6: payroll figures are sensitive, so this
     * endpoint additionally requires the caller be an admin/HR principal,
     * unlike `calculate()`). No dedicated "HR" Nextcloud group exists in this
     * app yet, so the gate is the standard admin-group check; introducing a
     * separate HR group is a named fast-follow, not a blocker for this
     * change.
     *
     * @return bool
     *
     * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-008
     */
    private function isAdminOrHr(): bool
    {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null || $uid === '') {
            return false;
        }

        return $this->groupManager->isAdmin($uid);

    }//end isAdminOrHr()


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
