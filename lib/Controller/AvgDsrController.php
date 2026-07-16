<?php

/**
 * AVG DSR Controller
 *
 * Backs the `DsrRequestDetail` manifest page actions "Exporteer"/"Voorbeeld
 * verwijdering"/"Bevestig verwijdering"/"Rectificeer" (avg-dsr design.md D3):
 * every method is `#[NoAdminRequired]` + a manual admin-only 403 gate BEFORE
 * any resolve (the `PayrollController::mutations()`/`wkrAssess()` two-gate
 * shape), then the posted `employeeId` is RBAC-resolved through
 * ObjectService (unknown/unauthorized -> 404, the no-admin-idor pattern)
 * before any `AvgDsrService`/`DsarService` call, and every `RuntimeException`
 * `DsarService::assertPrivileged()` could throw is caught and translated
 * into a 403 JSON response -- defense-in-depth, since the admin gate below
 * already guarantees `assertPrivileged()` passes in the normal flow
 * (REQ-DSR-004).
 *
 * design.md D3 hard constraint: this controller's admin gate MUST NOT widen
 * to admit a future dedicated "HR" Nextcloud group the way other
 * `isAdminOrHr()`-gated endpoints in this app may correctly do.
 * `DsarService::assertPrivileged()` hard-requires actual
 * `IGroupManager::isAdmin()`, not "admin or HR" -- widening this gate would
 * let a non-admin HR caller pass it and then hit the RuntimeException-to-403
 * translation instead of succeeding (a behaviour regression, not a security
 * hole, but a named trap for that future change to avoid).
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
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-004
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-005
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-006
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Controller;

use OCA\Hrmq\AppInfo\Application;
use OCA\Hrmq\Service\AvgDsrService;
use OCA\Hrmq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * Guarded endpoints for AVG data-subject-rights export/erase/rectify.
 */
class AvgDsrController extends Controller
{


    /**
     * @param IRequest            $request         The request object.
     * @param ContainerInterface  $container       DI container for the RBAC-guarded ObjectService resolve.
     * @param AvgDsrService       $avgDsrService    The DSR orchestration service.
     * @param SettingsService     $settingsService The register-slug source.
     * @param IUserSession        $userSession     The current user session (admin gate; ambient DsarService session).
     * @param IGroupManager       $groupManager    To check the caller's admin membership (admin gate).
     */
    public function __construct(
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly AvgDsrService $avgDsrService,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()


    /**
     * `POST /api/dsr/export` -- Art 15 inzage / Art 20 portabiliteit.
     *
     * @param string|null $employeeId   The Employee id.
     * @param string|null $right        `inzage` (default) or `portabiliteit`.
     * @param string|null $dsrRequestId Optional DsrRequest id to record this export outcome against.
     *
     * @return JSONResponse The export envelope, 400 on a missing/invalid input, 403 for a non-admin caller or a RuntimeException, 404 when the employee does not resolve.
     *
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-003
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-004
     */
    #[NoAdminRequired]
    public function export(?string $employeeId=null, ?string $right=null, ?string $dsrRequestId=null): JSONResponse
    {
        $guard = $this->guardAdminAndEmployee($employeeId);
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        $right = (string) ($right ?? 'inzage');
        if (in_array($right, ['inzage', 'portabiliteit'], true) === false) {
            return new JSONResponse(['error' => 'right moet "inzage" of "portabiliteit" zijn.'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->avgDsrService->exportForSubject($guard, $right, $this->nullableId($dsrRequestId));
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse($result);

    }//end export()


    /**
     * `POST /api/dsr/erase-preview` -- mandatory, zero-write erasure preview
     * (REQ-DSR-006). When `dsrRequestId` is given, the preview is recorded
     * onto that `DsrRequest`.
     *
     * @param string|null $employeeId   The Employee id.
     * @param string|null $dsrRequestId Optional DsrRequest id to record this preview against.
     *
     * @return JSONResponse The preview envelope, 400 on a missing employeeId, 403 for a non-admin caller or a RuntimeException, 404 when the employee does not resolve.
     *
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-005
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-006
     */
    #[NoAdminRequired]
    public function erasePreview(?string $employeeId=null, ?string $dsrRequestId=null): JSONResponse
    {
        $guard = $this->guardAdminAndEmployee($employeeId);
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        try {
            $preview = $this->avgDsrService->previewErasure($guard, $this->nullableId($dsrRequestId));
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse($preview);

    }//end erasePreview()


    /**
     * `POST /api/dsr/erase-confirm` -- executes the retention-guarded erase
     * for a `DsrRequest` whose preview already ran (REQ-DSR-005/-006).
     * Refused (400, controlled) when the precondition is unmet.
     *
     * @param string|null $employeeId   The Employee id.
     * @param string|null $dsrRequestId The DsrRequest id whose preview already ran.
     *
     * @return JSONResponse The erase outcome, 400 on a missing input or a refused precondition, 403 for a non-admin caller or a RuntimeException, 404 when the employee does not resolve.
     *
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-005
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-006
     */
    #[NoAdminRequired]
    public function eraseConfirm(?string $employeeId=null, ?string $dsrRequestId=null): JSONResponse
    {
        $guard = $this->guardAdminAndEmployee($employeeId);
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        $dsrRequestId = trim((string) $dsrRequestId);
        if ($dsrRequestId === '') {
            return new JSONResponse(['error' => 'dsrRequestId is verplicht.'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $outcome = $this->avgDsrService->eraseSubject($guard, $dsrRequestId);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        if ((string) ($outcome['status'] ?? '') === 'refused') {
            return new JSONResponse($outcome, Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($outcome);

    }//end eraseConfirm()


    /**
     * `POST /api/dsr/rectify` -- Art 16 rectificatie, direct pass-through, no
     * retention guard (REQ-DSR-007).
     *
     * @param string|null          $employeeId   The Employee id.
     * @param array<string,mixed>|null $changes      Property -> new value map.
     * @param string|null          $dsrRequestId The DsrRequest id this rectification is recorded against.
     *
     * @return JSONResponse The updated object, 400 on missing/invalid input or a failed rectification, 403 for a non-admin caller or a RuntimeException, 404 when the employee does not resolve.
     *
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-007
     */
    #[NoAdminRequired]
    public function rectify(?string $employeeId=null, mixed $changes=null, ?string $dsrRequestId=null): JSONResponse
    {
        if ($this->isAdmin() === false) {
            return $this->forbidden();
        }

        $validationError = $this->validateRectifyInput($employeeId, $changes, $dsrRequestId);
        if ($validationError !== null) {
            return $validationError;
        }

        // resolveEmployeeInternalId() IS the no-admin-idor guard here
        // (ObjectService::find() under the caller's ambient RBAC) -- an
        // unresolvable/unauthorized id never reaches rectifySubjectObject().
        $objectId = $this->resolveEmployeeInternalId(trim((string) $employeeId));
        if ($objectId === null) {
            return new JSONResponse(['error' => 'Werknemer niet gevonden.'], Http::STATUS_NOT_FOUND);
        }

        try {
            $result = $this->avgDsrService->rectifySubjectObject($objectId, $changes, trim((string) $dsrRequestId));
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        if ($result === null) {
            return new JSONResponse(['error' => 'Rectificatie mislukt.'], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($result);

    }//end rectify()


    /**
     * Input validation for `rectify()` -- extracted to keep `rectify()`
     * itself simple: employeeId/dsrRequestId required, `changes` a non-empty
     * array.
     *
     * @param string|null              $employeeId   The Employee id.
     * @param mixed                    $changes      Property -> new value map.
     * @param string|null              $dsrRequestId The DsrRequest id.
     *
     * @return JSONResponse|null A 400 response on invalid input, or null when valid.
     */
    private function validateRectifyInput(?string $employeeId, mixed $changes, ?string $dsrRequestId): ?JSONResponse
    {
        $employeeId   = trim((string) $employeeId);
        $dsrRequestId = trim((string) $dsrRequestId);
        if ($employeeId === '' || $dsrRequestId === '') {
            return new JSONResponse(['error' => 'employeeId en dsrRequestId zijn verplicht.'], Http::STATUS_BAD_REQUEST);
        }

        if (is_array($changes) === false || $changes === []) {
            return new JSONResponse(['error' => 'changes is verplicht en moet een niet-lege object zijn.'], Http::STATUS_BAD_REQUEST);
        }

        return null;

    }//end validateRectifyInput()


    /**
     * Whether the current caller is a Nextcloud admin -- the gate for every
     * method on this controller. MUST NOT widen to `isAdminOrHr()` even
     * after a future dedicated HR group ships elsewhere in this app
     * (design.md D3) -- `DsarService::assertPrivileged()` hard-requires
     * actual `IGroupManager::isAdmin()`.
     *
     * @return bool
     *
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-004
     */
    private function isAdmin(): bool
    {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null || $uid === '') {
            return false;
        }

        return $this->groupManager->isAdmin($uid);

    }//end isAdmin()


    /**
     * The shared 403 envelope for the admin gate.
     *
     * @return JSONResponse
     */
    private function forbidden(): JSONResponse
    {
        return new JSONResponse(['error' => 'Alleen beheerders mogen AVG data-subject-rights-verzoeken behandelen.'], Http::STATUS_FORBIDDEN);

    }//end forbidden()


    /**
     * Shared guard for `export()`/`erasePreview()`/`eraseConfirm()`: the
     * admin gate, a non-empty `employeeId`, and the no-admin-idor RBAC
     * resolve (ADR-005 Rule 3) -- an unresolvable/unauthorized id never
     * reaches any `AvgDsrService`/`DsarService` call. Extracted to keep each
     * caller method's own complexity small.
     *
     * @param string|null $employeeId The Employee id.
     *
     * @return JSONResponse|string A 403/400/404 response on failure, or the trimmed, RBAC-resolved employeeId on success.
     */
    private function guardAdminAndEmployee(?string $employeeId): JSONResponse|string
    {
        if ($this->isAdmin() === false) {
            return $this->forbidden();
        }

        $employeeId = trim((string) $employeeId);
        if ($employeeId === '') {
            return new JSONResponse(['error' => 'employeeId is verplicht.'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->authorizeEmployee($employeeId) === null) {
            return new JSONResponse(['error' => 'Werknemer niet gevonden.'], Http::STATUS_NOT_FOUND);
        }

        return $employeeId;

    }//end guardAdminAndEmployee()


    /**
     * Resolve the posted employeeId through OpenRegister's ObjectService
     * under the caller's ambient RBAC (default $_rbac=true) -- the
     * no-admin-idor guard (the `PayrollController::authorizeRun()`
     * precedent). Returns null when the employee does not exist OR the
     * caller's RBAC denies it (both collapse to the same 404 so existence is
     * never leaked).
     *
     * @param string $employeeId The Employee id.
     *
     * @return array<string, mixed>|null
     */
    private function authorizeEmployee(string $employeeId): ?array
    {
        try {
            $employee = $this->objectService()->find(
                id: $employeeId,
                register: $this->settingsService->getRegisterSlug(),
                schema: 'Employee'
            );
        } catch (\Throwable $e) {
            return null;
        }

        if ($employee === null) {
            return null;
        }

        return $this->toArray($employee);

    }//end authorizeEmployee()


    /**
     * RBAC-resolve the employee's real internal (int) object id
     * (`ObjectService::find()`'s entity `getId()`) -- the `int $objectId`
     * `DsarService::rectifyObjectForSubject()` requires.
     *
     * @param string $employeeId The Employee id.
     *
     * @return int|null
     */
    private function resolveEmployeeInternalId(string $employeeId): ?int
    {
        try {
            $entity = $this->objectService()->find(
                id: $employeeId,
                register: $this->settingsService->getRegisterSlug(),
                schema: 'Employee'
            );
        } catch (\Throwable $e) {
            return null;
        }

        if ($entity === null) {
            return null;
        }

        if (is_object($entity) === true && method_exists($entity, 'getId') === true) {
            $id = $entity->getId();
            return is_numeric($id) ? (int) $id : null;
        }

        return null;

    }//end resolveEmployeeInternalId()


    /**
     * @return mixed The OpenRegister ObjectService, resolved with the caller's ambient RBAC (default $_rbac=true).
     */
    private function objectService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()


    /**
     * A trimmed, non-empty string, or null.
     *
     * @param string|null $value The raw value.
     *
     * @return string|null
     */
    private function nullableId(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;

    }//end nullableId()


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
