<?php

/**
 * Document Controller
 *
 * Backs the `EmploymentContractDetail` manifest page action "Genereer
 * arbeidsovereenkomst" AND, since payslip-pdf-docudesk, the `PayslipDetail`
 * page action "Genereer PDF" (hrmq-docudesk-documents design.md D7,
 * payslip-pdf-docudesk design.md D6): a single POST endpoint that resolves
 * the posted subject (contract OR payslip) through OpenRegister's
 * ObjectService under the caller's RBAC BEFORE any docudesk call (no-admin-idor
 * guard -- an unknown or unauthorized contractId/payslipId never reaches
 * docudesk and never creates a GeneratedDocument), then delegates the actual
 * render/store to `HrDocumentService::generate()` /
 * `HrDocumentService::generateLoonstrook()`. `jaaropgaaf` is NOT accepted on
 * this endpoint -- the aggregate-then-render flow is occ-only in MVP
 * (design.md D6).
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
 * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-008
 * @spec openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
 */

declare(strict_types=1);

namespace OCA\Hrmq\Controller;

use OCA\Hrmq\AppInfo\Application;
use OCA\Hrmq\Service\HrDocumentService;
use OCA\Hrmq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guarded endpoint that triggers a single HR-document generation attempt.
 */
class DocumentController extends Controller
{


    /**
     * @param IRequest          $request         The request object.
     * @param ContainerInterface $container      DI container for the RBAC-guarded ObjectService resolve.
     * @param HrDocumentService  $hrDocumentService The document-generation service.
     * @param SettingsService    $settingsService The register-slug source.
     * @param IUserSession       $userSession    The current user session (acting userId).
     * @param LoggerInterface    $logger         Logger.
     */
    public function __construct(
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly HrDocumentService $hrDocumentService,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()


    /**
     * `POST /api/documents/generate` -- dispatches on `documentType`. The
     * four letter types resolve the posted `contractId` under the caller's
     * RBAC (unknown/unauthorized -> 404, no docudesk call) then trigger a
     * single generation attempt for it (hrmq-docudesk-documents design.md
     * D8). `loonstrook` resolves the posted `payslipId` the identical way via
     * `authorizePayslip()` (payslip-pdf-docudesk design.md D6) -- the
     * employeeId is taken from the resolved payslip, `contractId` stays
     * null. A missing subject param for the requested type -> 400.
     * `jaaropgaaf` is not accepted on this endpoint (occ-only in MVP).
     *
     * @param string|null $contractId   The EmploymentContract id (row-scoped, `@objectId` from the manifest action) -- required for the letter types.
     * @param string      $documentType The document type (defaults to arbeidsovereenkomst).
     * @param string|null $payslipId    The Payslip id (row-scoped, `@objectId` from the PayslipDetail action) -- required for `loonstrook`.
     *
     * @return JSONResponse The generation outcome, 400 on a missing subject param, or 404 when the subject does not resolve.
     *
     * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-008
     * @spec openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
     */
    #[NoAdminRequired]
    public function generate(?string $contractId=null, string $documentType='arbeidsovereenkomst', ?string $payslipId=null): JSONResponse
    {
        $documentType = trim($documentType);
        $userId       = $this->userSession->getUser()?->getUID();

        if ($documentType === 'jaaropgaaf') {
            return new JSONResponse(
                ['error' => 'Jaaropgaaf genereren is niet beschikbaar via dit endpoint (alleen via occ hrmq:documents:generate).'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if ($documentType === 'loonstrook') {
            $payslipId = trim((string) $payslipId);
            if ($payslipId === '') {
                return new JSONResponse(['error' => 'payslipId is verplicht.'], Http::STATUS_BAD_REQUEST);
            }

            // No-admin-idor guard (ADR-005 Rule 3): the payslip must resolve
            // through OpenRegister's ObjectService under the caller's RBAC
            // before any docudesk call -- an unresolvable/unauthorized id
            // never reaches it.
            $payslipArray = $this->authorizePayslip($payslipId);
            if ($payslipArray === null) {
                return new JSONResponse(['error' => 'Loonstrook niet gevonden.'], Http::STATUS_NOT_FOUND);
            }

            $result = $this->hrDocumentService->generateLoonstrook($payslipId, $userId);
            return new JSONResponse($result);
        }

        $contractId = trim((string) $contractId);
        if ($contractId === '') {
            return new JSONResponse(['error' => 'contractId is verplicht.'], Http::STATUS_BAD_REQUEST);
        }

        // No-admin-idor guard (ADR-005 Rule 3): the contract must resolve
        // through OpenRegister's ObjectService under the caller's RBAC before
        // any docudesk call -- an unresolvable/unauthorized id never reaches it.
        $contractArray = $this->authorizeContract($contractId);
        if ($contractArray === null) {
            return new JSONResponse(['error' => 'Contract niet gevonden.'], Http::STATUS_NOT_FOUND);
        }

        $employeeId = trim((string) ($contractArray['employeeId'] ?? ''));
        if ($employeeId === '') {
            return new JSONResponse(['error' => 'Contract heeft geen gekoppelde medewerker.'], Http::STATUS_NOT_FOUND);
        }

        $result = $this->hrDocumentService->generate($employeeId, $contractId, $documentType, $userId);

        return new JSONResponse($result);

    }//end generate()


    /**
     * Resolve the posted contractId through OpenRegister's ObjectService
     * under the caller's ambient RBAC (default $_rbac=true) -- the
     * no-admin-idor guard for this endpoint. Returns null when the contract
     * does not exist OR the caller's RBAC denies it (both collapse to the
     * same 404 so existence is never leaked to an unauthorized caller).
     *
     * @param string $contractId The EmploymentContract id.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-008
     */
    private function authorizeContract(string $contractId): ?array
    {
        try {
            $contract = $this->objectService()->find(
                id: $contractId,
                register: $this->settingsService->getRegisterSlug(),
                schema: 'EmploymentContract'
            );
        } catch (\Throwable $e) {
            $this->logger->info('DocumentController: contract '.$contractId.' kon niet worden opgehaald: '.$e->getMessage());
            return null;
        }

        if ($contract === null) {
            return null;
        }

        return $this->toArray($contract);

    }//end authorizeContract()


    /**
     * Resolve the posted payslipId through OpenRegister's ObjectService
     * under the caller's ambient RBAC (default $_rbac=true) -- the
     * no-admin-idor guard mirroring `authorizeContract()` for the loonstrook
     * variant (payslip-pdf-docudesk design.md D6). Returns null when the
     * payslip does not exist OR the caller's RBAC denies it (both collapse
     * to the same 404 so existence is never leaked to an unauthorized caller).
     *
     * @param string $payslipId The Payslip id.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
     */
    private function authorizePayslip(string $payslipId): ?array
    {
        try {
            $payslip = $this->objectService()->find(
                id: $payslipId,
                register: $this->settingsService->getRegisterSlug(),
                schema: 'Payslip'
            );
        } catch (\Throwable $e) {
            $this->logger->info('DocumentController: payslip '.$payslipId.' kon niet worden opgehaald: '.$e->getMessage());
            return null;
        }

        if ($payslip === null) {
            return null;
        }

        return $this->toArray($payslip);

    }//end authorizePayslip()


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
