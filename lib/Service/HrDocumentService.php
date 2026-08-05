<?php

/**
 * HR Document Service
 *
 * Generates the standard Dutch HR documents (arbeidsovereenkomst,
 * aanbiedingsbrief, werkgeversverklaring, getuigschrift) plus, since
 * payslip-pdf-docudesk, the loonstrook (per-Payslip) and jaaropgaaf
 * (per-employee-year aggregate) PDFs, by invoking docudesk's template/rendering
 * engine same-instance and duck-typed (hrmq-docudesk-documents design.md D1).
 * hrmq holds no Twig, mPDF, template, or versioning machinery of its own: the
 * only artefact this service writes on the hrmq side is a `GeneratedDocument`
 * record logging the handoff -- which template, for whom, the outcome, and
 * where the file landed -- and, for jaaropgaaf, a `Jaaropgaaf` aggregate record
 * upserted from the employee's Payslips before rendering (payslip-pdf-docudesk
 * design.md D2/D4). Rendering itself happens inside docudesk's sandbox
 * (`OCA\DocuDesk\Service\DocumentService::generateDocument()`), resolved
 * exclusively by string FQCN through the DI container -- no compile-time
 * import, no composer/info.xml dependency on docudesk.
 *
 * Availability is duck-typed (ADR-046 philosophy, mirroring
 * `OCA\Hrmq\Service\PayrollGLPostService`): when docudesk is not installed, or
 * its services cannot be resolved, the attempt is recorded
 * `skipped-no-docudesk` and is retryable by a later `occ hrmq:documents:generate`
 * or the EmploymentContractDetail/PayslipDetail page action (design.md D5).
 * hrmq carries zero composer/info.xml dependency on docudesk.
 *
 * Template selection is config-first, discovery-second, and fails closed
 * (design.md D3): a configured docudesk template UUID always wins; otherwise
 * exactly one `namespace: "hrmq"` template whose `category` matches the
 * documentType is used -- zero or multiple matches record the attempt `failed`
 * with a diagnostic, and nothing renders (never guess between templates that
 * produce legal paper).
 *
 * Idempotency (design.md D6, widened by payslip-pdf-docudesk design.md D4):
 * at most one GeneratedDocument in `{pending, generated}` per idempotency key,
 * where the key is per documentType family -- the four letter types key on
 * (contractId, documentType), or (employeeId, documentType) when contractId is
 * null; loonstrook keys on (payslipId, documentType); jaaropgaaf keys on
 * (jaaropgaafId, documentType). An existing `generated` record is a no-op; a
 * stale `pending` record is superseded (marked `failed`) before a fresh
 * attempt starts. `failed`/`skipped-no-docudesk` never block a retry.
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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
 * hrmq#99 (consume-not-rebuild correction, hole #1): a generated loonstrook/
 * jaaropgaaf PDF is its own `GeneratedDocument` object and, once matched by
 * OpenRegister's PII index, is otherwise erase-eligible on its own -- even
 * when the Payslip it renders is correctly retention-locked. `generateLoonstrook()`/
 * `generateJaaropgaaf()` now check whether their SOURCE (the resolved
 * Payslip, or -- for jaaropgaaf -- any Payslip the aggregate covers) is
 * currently under active OpenRegister retention (`PayrollRetentionGuardService
 * ::isUnderActiveRetention()`) and, if so, place the SAME legal hold on the
 * newly created `GeneratedDocument` (`PayrollRetentionGuardService
 * ::inheritLegalHold()`) -- never a bespoke `retainedUntil` field on
 * `GeneratedDocument` (that was the original, since-revised
 * `document-dossier-avg` proposal's shape).
 *
 * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md
 * @spec openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders standard HR documents via docudesk and stores the result.
 */
class HrDocumentService
{


    /**
     * The app id docudesk registers under (IAppManager::isInstalled probe).
     *
     * @var string
     */
    private const DOCUDESK_APP_ID = 'docudesk';

    /**
     * docudesk's rendering service, resolved by string FQCN only (no
     * compile-time import -- design.md D2).
     *
     * @var string
     */
    private const DOCUMENT_SERVICE_FQCN = 'OCA\DocuDesk\Service\DocumentService';

    /**
     * docudesk's template lookup service, resolved by string FQCN only.
     *
     * @var string
     */
    private const TEMPLATE_SERVICE_FQCN = 'OCA\DocuDesk\Service\TemplateService';

    /**
     * OpenRegister's file-storage service (read-only reuse, not docudesk).
     *
     * @var string
     */
    private const OBJECT_FILE_SERVICE_FQCN = 'OCA\OpenRegister\Service\FileService';

    /**
     * The docudesk template namespace hrmq's own templates live under
     * (design.md Context: TemplateService validates it as a lowercase NC app
     * id; "multiple apps maintain their own template collections").
     *
     * @var string
     */
    private const TEMPLATE_NAMESPACE = 'hrmq';

    /**
     * This app's own generation-log schema.
     *
     * @var string
     */
    private const GENERATED_DOCUMENT_SCHEMA = 'GeneratedDocument';

    /**
     * @var string
     */
    private const EMPLOYEE_SCHEMA = 'Employee';

    /**
     * @var string
     */
    private const CONTRACT_SCHEMA = 'EmploymentContract';

    /**
     * The Payslip schema -- a loonstrook's subject (payslip-pdf-docudesk).
     *
     * @var string
     */
    private const PAYSLIP_SCHEMA = 'Payslip';

    /**
     * The Jaaropgaaf schema -- a jaaropgaaf's subject (payslip-pdf-docudesk).
     *
     * @var string
     */
    private const JAAROPGAAF_SCHEMA = 'Jaaropgaaf';

    /**
     * The documentType the occ backlog processes by default (design.md D7).
     *
     * @var string
     */
    private const BACKLOG_DOCUMENT_TYPE = 'arbeidsovereenkomst';

    /**
     * The loonstrook documentType (payslip-pdf-docudesk design.md D1).
     *
     * @var string
     */
    private const LOONSTROOK_DOCUMENT_TYPE = 'loonstrook';

    /**
     * The jaaropgaaf documentType (payslip-pdf-docudesk design.md D1).
     *
     * @var string
     */
    private const JAAROPGAAF_DOCUMENT_TYPE = 'jaaropgaaf';

    /**
     * GeneratedDocument statuses that count as "active" for the
     * at-most-one-per-(contract|employee, documentType) invariant (design.md D6).
     *
     * @var string[]
     */
    private const ACTIVE_STATUSES = ['pending', 'generated'];

    /**
     * Max rows loaded per register scan.
     *
     * @var int
     */
    private const LIMIT = 10000;


    /**
     * @param ContainerInterface           $container       DI container for lazy ObjectService/docudesk/FileService resolution.
     * @param IAppManager                  $appManager      To duck-type-probe docudesk's presence.
     * @param SettingsService              $settingsService Register slug, template config, and employer block.
     * @param PayrollRetentionGuardService $retentionGuard  Reads a source's already-known retention ceiling and inherits a legal hold onto a generated PDF (hrmq#99 hole #1).
     * @param LoggerInterface              $logger          Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly SettingsService $settingsService,
        private readonly PayrollRetentionGuardService $retentionGuard,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * The occ backlog trigger (design.md D7, widened by payslip-pdf-docudesk
     * design.md D5): with no options, every permanent written
     * EmploymentContract is processed for `arbeidsovereenkomst`
     * (already-documented contracts simply no-op via the idempotency
     * pre-check). A non-default letter documentType has no backlog semantics
     * and requires `$employeeId`. `loonstrook` has real backlog semantics --
     * every Payslip lacking an active loonstrook document, optionally
     * narrowed by `$period` and/or `$employeeId`. `jaaropgaaf` REQUIRES
     * `$year` and processes every employee with at least one payslip in that
     * year (or just `$employeeId`), aggregating then rendering per employee.
     * Option misuse -- `$period` with any type but loonstrook, `$year` with
     * any type but jaaropgaaf, `jaaropgaaf` without `$year`, or a non-backlog
     * letter type without `$employeeId` -- returns a single `usage-error`
     * outcome and generates nothing.
     *
     * @param string|null $documentType Restrict/switch the documentType, or null for the default backlog.
     * @param string|null $employeeId   Restrict to one Employee id.
     * @param string|null $period       Restrict the loonstrook backlog to one YYYY-MM period.
     * @param string|null $year         The year to aggregate/backlog for the jaaropgaaf documentType.
     *
     * @return array<int, array<string, mixed>> One outcome array per attempt.
     *
     * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-007
     * @spec openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003
     */
    public function generateBacklog(
        ?string $documentType=null,
        ?string $employeeId=null,
        ?string $period=null,
        ?string $year=null
    ): array {
        $type       = ($documentType !== null && trim($documentType) !== '') ? trim($documentType) : self::BACKLOG_DOCUMENT_TYPE;
        $employeeId = ($employeeId !== null && trim($employeeId) !== '') ? trim($employeeId) : null;
        $period     = ($period !== null && trim($period) !== '') ? trim($period) : null;
        $yearInt    = ($year !== null && trim($year) !== '') ? (int) trim($year) : null;

        if ($period !== null && $type !== self::LOONSTROOK_DOCUMENT_TYPE) {
            return [$this->outcome('', null, $type, 'usage-error', '--period is alleen geldig bij --type loonstrook.')];
        }

        if ($yearInt !== null && $type !== self::JAAROPGAAF_DOCUMENT_TYPE) {
            return [$this->outcome('', null, $type, 'usage-error', '--year is alleen geldig bij --type jaaropgaaf.')];
        }

        if ($type === self::JAAROPGAAF_DOCUMENT_TYPE && $yearInt === null) {
            return [$this->outcome('', null, $type, 'usage-error', 'Documenttype "jaaropgaaf" vereist --year.')];
        }

        if ($type === self::LOONSTROOK_DOCUMENT_TYPE) {
            return $this->loonstrookBacklog($employeeId, $period);
        }

        if ($type === self::JAAROPGAAF_DOCUMENT_TYPE) {
            return $this->jaaropgaafBacklog($employeeId, (int) $yearInt);
        }

        if ($type !== self::BACKLOG_DOCUMENT_TYPE && $employeeId === null) {
            return [
                $this->outcome(
                    '',
                    null,
                    $type,
                    'usage-error',
                    sprintf('Documenttype "%s" heeft geen backlog-semantiek en vereist --employee.', $type)
                ),
            ];
        }

        if ($type !== self::BACKLOG_DOCUMENT_TYPE) {
            return [$this->generate($employeeId, null, $type, null)];
        }

        $results = [];
        foreach ($this->eligibleBacklogContracts($employeeId) as $contract) {
            $contractEmployeeId = trim((string) ($contract['employeeId'] ?? ''));
            $contractId          = (string) ($contract['id'] ?? $contract['@self']['id'] ?? '');
            if ($contractEmployeeId === '' || $contractId === '') {
                continue;
            }

            $results[] = $this->generate($contractEmployeeId, $contractId, self::BACKLOG_DOCUMENT_TYPE, null);
        }

        return $results;

    }//end generateBacklog()


    /**
     * The loonstrook backlog (payslip-pdf-docudesk design.md D5): every
     * Payslip lacking an active loonstrook document, optionally narrowed by
     * employee and/or period. Does NOT pre-filter already-documented
     * payslips -- `generateLoonstrook()`'s own idempotency pre-check turns
     * those into a no-op outcome, so there is a single source of truth for
     * "already generated".
     *
     * @param string|null $employeeId Restrict to one Employee id, or null for all.
     * @param string|null $period     Restrict to one YYYY-MM period, or null for all.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loonstrookBacklog(?string $employeeId, ?string $period): array
    {
        $results = [];
        foreach ($this->loadAll(self::PAYSLIP_SCHEMA) as $payslip) {
            if ($employeeId !== null && trim((string) ($payslip['employeeId'] ?? '')) !== $employeeId) {
                continue;
            }

            if ($period !== null && (string) ($payslip['period'] ?? '') !== $period) {
                continue;
            }

            $payslipId = (string) ($payslip['id'] ?? $payslip['@self']['id'] ?? '');
            if ($payslipId === '') {
                continue;
            }

            $results[] = $this->generateLoonstrook($payslipId, null);
        }

        return $results;

    }//end loonstrookBacklog()


    /**
     * The jaaropgaaf backlog (payslip-pdf-docudesk design.md D5): every
     * employee with at least one Payslip in `$year`, optionally narrowed to
     * one employee -- per employee: aggregate (upsert) then render.
     *
     * @param string|null $employeeId Restrict to one Employee id, or null for all.
     * @param int         $year       The year to aggregate/render.
     *
     * @return array<int, array<string, mixed>>
     */
    private function jaaropgaafBacklog(?string $employeeId, int $year): array
    {
        $employeeIds = [];
        foreach ($this->loadAll(self::PAYSLIP_SCHEMA) as $payslip) {
            $payslipEmployeeId = trim((string) ($payslip['employeeId'] ?? ''));
            if ($payslipEmployeeId === '') {
                continue;
            }

            if ($employeeId !== null && $payslipEmployeeId !== $employeeId) {
                continue;
            }

            if (str_starts_with((string) ($payslip['period'] ?? ''), $year.'-') === false) {
                continue;
            }

            $employeeIds[$payslipEmployeeId] = true;
        }

        $results = [];
        foreach (array_keys($employeeIds) as $eachEmployeeId) {
            $results[] = $this->generateJaaropgaaf($eachEmployeeId, $year, null);
        }

        return $results;

    }//end jaaropgaafBacklog()


    /**
     * Generate one document: idempotency pre-check, duck-typed availability
     * probe, template selection (config-first/discovery-second/fail-closed),
     * the docudesk render call, and storing the returned binary via
     * OpenRegister's FileService -- recording the outcome on a
     * `GeneratedDocument` at every step (design.md D2-D6).
     *
     * @param string      $employeeId   The Employee this document is for.
     * @param string|null $contractId   The EmploymentContract it evidences, or null for employee-level types.
     * @param string      $documentType One of arbeidsovereenkomst/aanbiedingsbrief/werkgeversverklaring/getuigschrift.
     * @param string|null $userId       The acting Nextcloud user id, or null for 'system' (occ context).
     *
     * @return array<string, mixed> Outcome: {employeeId, contractId, documentType, status, message, generatedDocumentId}.
     *
     * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-002
     * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-003
     * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-004
     * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-005
     * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-006
     */
    public function generate(string $employeeId, ?string $contractId, string $documentType, ?string $userId=null): array
    {
        return $this->generateInternal($employeeId, $contractId, null, null, $documentType, $userId);

    }//end generate()


    /**
     * Generate a `documentType: loonstrook` document for one Payslip
     * (payslip-pdf-docudesk design.md D3/D6): resolves the Payslip, takes its
     * `employeeId`, and renders with `dataRefs = [Employee, Payslip]`
     * (`contractId` stays null); idempotency keys on (payslipId,
     * documentType).
     *
     * @param string      $payslipId The Payslip id.
     * @param string|null $userId    The acting Nextcloud user id, or null for 'system'.
     *
     * @return array<string, mixed> Outcome: {employeeId, contractId, documentType, status, message, generatedDocumentId}.
     *
     * @spec openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
     */
    public function generateLoonstrook(string $payslipId, ?string $userId=null): array
    {
        $payslipId = trim($payslipId);
        if ($payslipId === '') {
            return $this->outcome('', null, self::LOONSTROOK_DOCUMENT_TYPE, 'failed', 'Geen payslipId opgegeven; genereren is geweigerd.');
        }

        $payslip = $this->findPayslip($payslipId);
        if ($payslip === null) {
            return $this->outcome('', null, self::LOONSTROOK_DOCUMENT_TYPE, 'failed', 'Payslip niet gevonden: '.$payslipId);
        }

        $employeeId = trim((string) ($payslip['employeeId'] ?? ''));
        if ($employeeId === '') {
            return $this->outcome('', null, self::LOONSTROOK_DOCUMENT_TYPE, 'failed', 'Payslip heeft geen gekoppelde medewerker: '.$payslipId);
        }

        $outcome = $this->generateInternal($employeeId, null, $payslipId, null, self::LOONSTROOK_DOCUMENT_TYPE, $userId);
        $this->inheritRetentionIfSourceHeld($outcome, self::PAYSLIP_SCHEMA, $payslipId, 'Payslip '.$payslipId);

        return $outcome;

    }//end generateLoonstrook()


    /**
     * Generate a `documentType: jaaropgaaf` document for one employee-year
     * (payslip-pdf-docudesk design.md D3/D4/D6): first upserts the employee's
     * `Jaaropgaaf` aggregate for `$year` (idempotent per employee+year), then
     * renders with `dataRefs = [Employee, Jaaropgaaf]`; idempotency keys on
     * (jaaropgaafId, documentType). Zero payslips in the year yields a
     * `failed` outcome with no aggregate write.
     *
     * @param string      $employeeId The Employee id.
     * @param int         $year       The kalenderjaar to aggregate/render.
     * @param string|null $userId     The acting Nextcloud user id, or null for 'system'.
     *
     * @return array<string, mixed> Outcome: {employeeId, contractId, documentType, status, message, generatedDocumentId}.
     *
     * @spec openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003
     */
    public function generateJaaropgaaf(string $employeeId, int $year, ?string $userId=null): array
    {
        $employeeId = trim($employeeId);
        if ($employeeId === '') {
            return $this->outcome('', null, self::JAAROPGAAF_DOCUMENT_TYPE, 'failed', 'Geen employeeId opgegeven; genereren is geweigerd.');
        }

        $aggregate = $this->upsertJaaropgaaf($employeeId, $year);
        if ($aggregate === null) {
            return $this->outcome(
                $employeeId,
                null,
                self::JAAROPGAAF_DOCUMENT_TYPE,
                'failed',
                sprintf('Geen payslips gevonden voor medewerker %s in %d; jaaropgaaf is niet aangemaakt.', $employeeId, $year)
            );
        }

        $jaaropgaafId = (string) ($aggregate['id'] ?? $aggregate['@self']['id'] ?? '');
        if ($jaaropgaafId === '') {
            return $this->outcome($employeeId, null, self::JAAROPGAAF_DOCUMENT_TYPE, 'failed', 'De aangemaakte jaaropgaaf heeft geen id.');
        }

        $outcome = $this->generateInternal($employeeId, null, null, $jaaropgaafId, self::JAAROPGAAF_DOCUMENT_TYPE, $userId);
        $this->inheritRetentionIfAnyPayslipHeld($outcome, $employeeId, $year);

        return $outcome;

    }//end generateJaaropgaaf()


    /**
     * The shared generation pipeline for every documentType (design.md
     * D2-D6, payslip-pdf-docudesk design.md D3/D6): idempotency pre-check
     * (keyed per documentType family), duck-typed availability probe,
     * template selection (config-first/discovery-second/fail-closed), the
     * docudesk render call, and storing the returned binary via
     * OpenRegister's FileService -- recording the outcome on a
     * `GeneratedDocument` at every step.
     *
     * @param string      $employeeId   The Employee this document is for.
     * @param string|null $contractId   The EmploymentContract it evidences, or null.
     * @param string|null $payslipId    The Payslip a loonstrook renders, or null.
     * @param string|null $jaaropgaafId The Jaaropgaaf a jaaropgaaf renders, or null.
     * @param string      $documentType The document type.
     * @param string|null $userId       The acting Nextcloud user id, or null for 'system' (occ context).
     *
     * @return array<string, mixed> Outcome: {employeeId, contractId, documentType, status, message, generatedDocumentId}.
     *
     * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-002
     * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-003
     * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-004
     * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-005
     * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-006
     * @spec openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
     * @spec openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003
     */
    private function generateInternal(
        string $employeeId,
        ?string $contractId,
        ?string $payslipId,
        ?string $jaaropgaafId,
        string $documentType,
        ?string $userId
    ): array {
        $employeeId = trim($employeeId);
        $contractId = ($contractId !== null && trim($contractId) !== '') ? trim($contractId) : null;

        if ($employeeId === '') {
            return $this->outcome($employeeId, $contractId, $documentType, 'failed', 'Geen employeeId opgegeven; genereren is geweigerd.');
        }

        $active = $this->activeDocumentFor($employeeId, $contractId, $payslipId, $jaaropgaafId, $documentType);
        if ($active !== null) {
            if ((string) ($active['status'] ?? '') === 'generated') {
                return $this->outcome(
                    $employeeId,
                    $contractId,
                    $documentType,
                    'already-generated',
                    'Er bestaat al een gegenereerd document; geen nieuwe poging nodig.',
                    $active
                );
            }

            // Stale pending -- supersede, then fall through to a fresh attempt (design.md D6).
            $this->saveGeneratedDocument(
                $active,
                ['status' => 'failed', 'errorMessage' => 'Verlopen pending-poging vervangen door een nieuwe poging.']
            );
        }

        if ($this->docudeskAvailable() === false) {
            $doc = $this->createGeneratedDocument(
                [
                    'documentType' => $documentType,
                    'employeeId'   => $employeeId,
                    'contractId'   => $contractId,
                    'payslipId'    => $payslipId,
                    'jaaropgaafId' => $jaaropgaafId,
                    'status'       => 'skipped-no-docudesk',
                    'errorMessage' => 'Docudesk is niet geïnstalleerd of de renderer kon niet worden geladen; deze poging kan later opnieuw worden uitgevoerd.',
                ]
            );

            return $this->outcome($employeeId, $contractId, $documentType, 'skipped-no-docudesk', (string) $doc['errorMessage'], $doc);
        }

        $selected = $this->selectTemplate($documentType);
        if ($selected['error'] !== null) {
            $doc = $this->createGeneratedDocument(
                [
                    'documentType' => $documentType,
                    'employeeId'   => $employeeId,
                    'contractId'   => $contractId,
                    'payslipId'    => $payslipId,
                    'jaaropgaafId' => $jaaropgaafId,
                    'status'       => 'failed',
                    'errorMessage' => $selected['error'],
                ]
            );

            return $this->outcome($employeeId, $contractId, $documentType, 'failed', (string) $selected['error'], $doc);
        }

        $doc = $this->createGeneratedDocument(
            [
                'documentType' => $documentType,
                'employeeId'   => $employeeId,
                'contractId'   => $contractId,
                'payslipId'    => $payslipId,
                'jaaropgaafId' => $jaaropgaafId,
                'templateRef'  => $selected['templateId'],
                'status'       => 'pending',
            ]
        );

        $dataRefs = $this->buildDataRefs($employeeId, $contractId, $payslipId, $jaaropgaafId);
        $options  = $this->buildOptions($documentType, $userId);

        try {
            $rendered = $this->documentService()->generateDocument($selected['templateId'], $dataRefs, $options);
        } catch (\Throwable $e) {
            $doc = $this->saveGeneratedDocument($doc, ['status' => 'failed', 'errorMessage' => 'Genereren via docudesk is mislukt: '.$e->getMessage()]);
            return $this->outcome($employeeId, $contractId, $documentType, 'failed', (string) $doc['errorMessage'], $doc);
        }

        $content = (string) ($rendered['content'] ?? '');
        if ($content === '') {
            $doc = $this->saveGeneratedDocument($doc, ['status' => 'failed', 'errorMessage' => 'Docudesk leverde geen documentinhoud terug.']);
            return $this->outcome($employeeId, $contractId, $documentType, 'failed', (string) $doc['errorMessage'], $doc);
        }

        $fileName = $this->fileName($documentType, $employeeId);
        $docId    = (string) ($doc['id'] ?? $doc['@self']['id'] ?? '');

        try {
            $file     = $this->fileService()->addFile($docId, $fileName, $content);
            $filePath = (is_object($file) === true && method_exists($file, 'getPath') === true) ? (string) $file->getPath() : $fileName;
        } catch (\Throwable $e) {
            $doc = $this->saveGeneratedDocument($doc, ['status' => 'failed', 'errorMessage' => 'Opslaan van het gegenereerde bestand is mislukt: '.$e->getMessage()]);
            return $this->outcome($employeeId, $contractId, $documentType, 'failed', (string) $doc['errorMessage'], $doc);
        }

        $doc = $this->saveGeneratedDocument(
            $doc,
            [
                'status'       => 'generated',
                'filePath'     => $filePath,
                'generatedAt'  => gmdate('Y-m-d\TH:i:s\Z'),
                'errorMessage' => null,
            ]
        );

        return $this->outcome($employeeId, $contractId, $documentType, 'generated', 'Document gegenereerd en opgeslagen.', $doc);

    }//end generateInternal()


    /**
     * The permanent, written EmploymentContracts eligible for the default
     * backlog (design.md D7), optionally restricted to one employee. Does NOT
     * pre-filter already-documented contracts -- `generate()`'s own
     * idempotency pre-check turns those into a no-op outcome, so there is a
     * single source of truth for "already generated".
     *
     * @param string|null $employeeId Restrict to one Employee id, or null for all.
     *
     * @return array<int, array<string, mixed>>
     */
    private function eligibleBacklogContracts(?string $employeeId): array
    {
        $out = [];
        foreach ($this->loadAll(self::CONTRACT_SCHEMA) as $contract) {
            if ((string) ($contract['type'] ?? '') !== 'permanent') {
                continue;
            }

            if (($contract['writtenContract'] ?? false) !== true) {
                continue;
            }

            if ($employeeId !== null && trim((string) ($contract['employeeId'] ?? '')) !== $employeeId) {
                continue;
            }

            $out[] = $contract;
        }

        return $out;

    }//end eligibleBacklogContracts()


    /**
     * Resolve one Payslip by id (payslip-pdf-docudesk).
     *
     * @param string $payslipId The Payslip id.
     *
     * @return array<string, mixed>|null
     */
    private function findPayslip(string $payslipId): ?array
    {
        foreach ($this->loadAll(self::PAYSLIP_SCHEMA) as $payslip) {
            $id = (string) ($payslip['id'] ?? $payslip['@self']['id'] ?? '');
            if ($id === $payslipId) {
                return $payslip;
            }
        }

        return null;

    }//end findPayslip()


    /**
     * hrmq#99 hole #1: when the single named source object (a Payslip) is
     * currently under active OpenRegister retention, inherit the SAME legal
     * hold onto the just-created `GeneratedDocument` -- never a bespoke
     * `retainedUntil` field on the document itself. A no-op when generation
     * did not produce a `generatedDocumentId` (failed/skipped outcome) or the
     * source cannot be resolved.
     *
     * @param array<string, mixed> $outcome           The outcome `generateInternal()` returned.
     * @param string                $sourceSchema      The source object's schema (e.g. `Payslip`).
     * @param string                $sourceId          The source object's id.
     * @param string                $sourceDescription A short, non-PII description of the source for the hold reason.
     *
     * @return void
     *
     * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
     */
    private function inheritRetentionIfSourceHeld(array $outcome, string $sourceSchema, string $sourceId, string $sourceDescription): void
    {
        $docId = (string) ($outcome['generatedDocumentId'] ?? '');
        if ($docId === '') {
            return;
        }

        $sourceEntity = $this->findEntity($sourceSchema, $sourceId);
        if ($sourceEntity === null || $this->retentionGuard->isUnderActiveRetention($sourceEntity) === false) {
            return;
        }

        $docEntity = $this->findEntity(self::GENERATED_DOCUMENT_SCHEMA, $docId);
        if ($docEntity === null) {
            return;
        }

        $this->retentionGuard->inheritLegalHold($docEntity, self::GENERATED_DOCUMENT_SCHEMA, $sourceDescription);

    }//end inheritRetentionIfSourceHeld()


    /**
     * hrmq#99 hole #1, jaaropgaaf variant: a jaaropgaaf aggregates every
     * Payslip in one (employeeId, year) window, so its `GeneratedDocument`
     * inherits a legal hold when ANY of those Payslips is currently under
     * active OpenRegister retention.
     *
     * @param array<string, mixed> $outcome    The outcome `generateInternal()` returned.
     * @param string                $employeeId The Employee id.
     * @param int                   $year       The kalenderjaar aggregated.
     *
     * @return void
     *
     * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
     */
    private function inheritRetentionIfAnyPayslipHeld(array $outcome, string $employeeId, int $year): void
    {
        $docId = (string) ($outcome['generatedDocumentId'] ?? '');
        if ($docId === '') {
            return;
        }

        $heldPayslipId = $this->findHeldPayslipIdForYear($employeeId, $year);
        if ($heldPayslipId === null) {
            return;
        }

        $docEntity = $this->findEntity(self::GENERATED_DOCUMENT_SCHEMA, $docId);
        if ($docEntity === null) {
            return;
        }

        $this->retentionGuard->inheritLegalHold(
            $docEntity,
            self::GENERATED_DOCUMENT_SCHEMA,
            'Payslip '.$heldPayslipId.' (jaaropgaaf '.$year.')'
        );

    }//end inheritRetentionIfAnyPayslipHeld()


    /**
     * The id of the first Payslip in one (employeeId, year) window that is
     * currently under active OpenRegister retention, or null when none is.
     * Extracted from `inheritRetentionIfAnyPayslipHeld()` to keep that
     * method's own complexity small.
     *
     * @param string $employeeId The Employee id.
     * @param int    $year       The kalenderjaar aggregated.
     *
     * @return string|null
     */
    private function findHeldPayslipIdForYear(string $employeeId, int $year): ?string
    {
        foreach ($this->loadAll(self::PAYSLIP_SCHEMA) as $payslip) {
            $payslipId = $this->payslipIdIfInScope($payslip, $employeeId, $year);
            if ($payslipId === null) {
                continue;
            }

            $entity = $this->findEntity(self::PAYSLIP_SCHEMA, $payslipId);
            if ($entity !== null && $this->retentionGuard->isUnderActiveRetention($entity) === true) {
                return $payslipId;
            }
        }

        return null;

    }//end findHeldPayslipIdForYear()


    /**
     * The Payslip's id when it belongs to `$employeeId` and falls within
     * `$year`, or null otherwise.
     *
     * @param array<string, mixed> $payslip    The Payslip row.
     * @param string               $employeeId The Employee id.
     * @param int                  $year       The kalenderjaar.
     *
     * @return string|null
     */
    private function payslipIdIfInScope(array $payslip, string $employeeId, int $year): ?string
    {
        if (trim((string) ($payslip['employeeId'] ?? '')) !== $employeeId) {
            return null;
        }

        if (str_starts_with((string) ($payslip['period'] ?? ''), $year.'-') === false) {
            return null;
        }

        $payslipId = (string) ($payslip['id'] ?? $payslip['@self']['id'] ?? '');
        return ($payslipId === '') ? null : $payslipId;

    }//end payslipIdIfInScope()


    /**
     * Resolve one object as a real OpenRegister entity (not the array shape
     * `loadAll()`/`toArray()` return) -- needed so `PayrollRetentionGuardService`
     * can read/write `retention` directly. Internal-service resolve (`_rbac:
     * false`, `_multitenancy: false`), matching this service's other
     * internal reads/writes.
     *
     * @param string $schema The schema name.
     * @param string $id     The object id.
     *
     * @return mixed The resolved ObjectEntity, or null when it cannot be loaded.
     */
    private function findEntity(string $schema, string $id): mixed
    {
        try {
            return $this->objectService()->find(id: $id, register: $this->register(), schema: $schema, _rbac: false, _multitenancy: false);
        } catch (\Throwable $e) {
            $this->logger->debug('HrDocumentService: kon '.$schema.' '.$id.' niet opnieuw ophalen als entity: '.$e->getMessage());
            return null;
        }

    }//end findEntity()


    /**
     * Resolve the existing Jaaropgaaf for one (employeeId, year) key, if any
     * (payslip-pdf-docudesk design.md D4 -- the upsert-per-employee-year key).
     *
     * @param string $employeeId The Employee id.
     * @param int    $year       The kalenderjaar.
     *
     * @return array<string, mixed>|null
     */
    private function findJaaropgaaf(string $employeeId, int $year): ?array
    {
        foreach ($this->loadAll(self::JAAROPGAAF_SCHEMA) as $jaaropgaaf) {
            if (trim((string) ($jaaropgaaf['employeeId'] ?? '')) === $employeeId && (int) ($jaaropgaaf['year'] ?? 0) === $year) {
                return $jaaropgaaf;
            }
        }

        return null;

    }//end findJaaropgaaf()


    /**
     * Aggregate one employee's Payslips for `$year` into their `Jaaropgaaf`
     * (payslip-pdf-docudesk design.md D2/D4): sums only the fields honestly
     * derivable from Payslip (`totalZvwWithheld` filtered to
     * `zvwMode === 'inhouding'` only -- the employer levy is never a
     * werknemersaandeel); `verrekendeArbeidskorting` stays null (no payroll
     * engine computes it yet -- never fabricated). Upserts the EXISTING
     * (employeeId, year) object when one exists, never duplicating. Returns
     * null (no write) when the employee has zero payslips in the year.
     *
     * @param string $employeeId The Employee id.
     * @param int    $year       The kalenderjaar to aggregate.
     *
     * @return array<string, mixed>|null The upserted Jaaropgaaf, or null when there is nothing to aggregate.
     *
     * @spec openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-001
     * @spec openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003
     */
    private function upsertJaaropgaaf(string $employeeId, int $year): ?array
    {
        $totalGrossPay              = 0.0;
        $totalLoonheffing           = 0.0;
        $totalNettoPay              = 0.0;
        $totalZvwWithheld           = 0.0;
        $totalPensionContribution   = 0.0;
        $totalVakantiegeldReserved  = 0.0;
        $payPeriodCount             = 0;

        foreach ($this->loadAll(self::PAYSLIP_SCHEMA) as $payslip) {
            if (trim((string) ($payslip['employeeId'] ?? '')) !== $employeeId) {
                continue;
            }

            if (str_starts_with((string) ($payslip['period'] ?? ''), $year.'-') === false) {
                continue;
            }

            $totalGrossPay             += (float) ($payslip['grossPay'] ?? 0);
            $totalLoonheffing          += (float) ($payslip['loonheffing'] ?? 0);
            $totalNettoPay             += (float) ($payslip['nettoPay'] ?? 0);
            $totalPensionContribution  += (float) ($payslip['pensionContribution'] ?? 0);
            $totalVakantiegeldReserved += (float) ($payslip['vakantiegeldReserved'] ?? 0);

            if ((string) ($payslip['zvwMode'] ?? '') === 'inhouding') {
                $totalZvwWithheld += (float) ($payslip['zvw'] ?? 0);
            }

            $payPeriodCount++;
        }//end foreach

        if ($payPeriodCount === 0) {
            return null;
        }

        $fields = [
            'employeeId'                => $employeeId,
            'year'                      => $year,
            'totalGrossPay'             => $totalGrossPay,
            'totalLoonheffing'          => $totalLoonheffing,
            'totalNettoPay'             => $totalNettoPay,
            'totalZvwWithheld'          => $totalZvwWithheld,
            'totalPensionContribution'  => $totalPensionContribution,
            'totalVakantiegeldReserved' => $totalVakantiegeldReserved,
            'payPeriodCount'            => $payPeriodCount,
            // Never fabricated (design.md D2): no Payslip field carries
            // arbeidskorting today, so this stays null on every aggregation.
            'verrekendeArbeidskorting'  => null,
            'loonheffingennummer'       => $this->settingsService->getDocumentsEmployerLoonheffingennummer(),
            'aggregatedAt'              => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $existing = $this->findJaaropgaaf($employeeId, $year);
        if ($existing !== null) {
            return $this->saveJaaropgaaf($existing, $fields);
        }

        return $this->createJaaropgaaf($fields);

    }//end upsertJaaropgaaf()


    /**
     * The active (pending/generated) GeneratedDocument for one idempotency
     * key, if any -- the at-most-one-active invariant (design.md D6, widened
     * by payslip-pdf-docudesk design.md D4): the four letter types key on
     * (contractId|employeeId, documentType); loonstrook keys on (payslipId,
     * documentType); jaaropgaaf keys on (jaaropgaafId, documentType).
     *
     * @param string      $employeeId   The Employee id.
     * @param string|null $contractId   The EmploymentContract id, or null.
     * @param string|null $payslipId    The Payslip id (loonstrook key), or null.
     * @param string|null $jaaropgaafId The Jaaropgaaf id (jaaropgaaf key), or null.
     * @param string      $documentType The document type.
     *
     * @return array<string, mixed>|null
     */
    private function activeDocumentFor(
        string $employeeId,
        ?string $contractId,
        ?string $payslipId,
        ?string $jaaropgaafId,
        string $documentType
    ): ?array {
        foreach ($this->loadAll(self::GENERATED_DOCUMENT_SCHEMA) as $row) {
            if ((string) ($row['documentType'] ?? '') !== $documentType) {
                continue;
            }

            if ($this->rowMatchesKey($row, $employeeId, $contractId, $payslipId, $jaaropgaafId) === false) {
                continue;
            }

            if (in_array((string) ($row['status'] ?? ''), self::ACTIVE_STATUSES, true) === true) {
                return $row;
            }
        }//end foreach

        return null;

    }//end activeDocumentFor()


    /**
     * Whether one GeneratedDocument row carries the idempotency key we are
     * looking for (design.md D6).
     *
     * Extracted from {@see self::activeDocumentFor()}: the key precedence
     * (payslip, then jaaropgaaf, then contract, then bare employee) reads as a
     * sequence of guards instead of nested if/else branches.
     *
     * @param array<string, mixed> $row          The GeneratedDocument row.
     * @param string               $employeeId   The Employee id.
     * @param string|null          $contractId   The EmploymentContract id, or null.
     * @param string|null          $payslipId    The Payslip id (loonstrook key), or null.
     * @param string|null          $jaaropgaafId The Jaaropgaaf id (jaaropgaaf key), or null.
     *
     * @return bool
     */
    private function rowMatchesKey(
        array $row,
        string $employeeId,
        ?string $contractId,
        ?string $payslipId,
        ?string $jaaropgaafId
    ): bool {
        if ($payslipId !== null) {
            return trim((string) ($row['payslipId'] ?? '')) === $payslipId;
        }

        if ($jaaropgaafId !== null) {
            return trim((string) ($row['jaaropgaafId'] ?? '')) === $jaaropgaafId;
        }

        $rowContractId = trim((string) ($row['contractId'] ?? ''));
        if ($contractId !== null) {
            return $rowContractId === $contractId;
        }

        return $rowContractId === '' && trim((string) ($row['employeeId'] ?? '')) === $employeeId;

    }//end rowMatchesKey()


    /**
     * Duck-typed docudesk availability probe (design.md D5): docudesk must be
     * installed AND both service FQCNs must resolve from the container.
     *
     * @return bool
     */
    private function docudeskAvailable(): bool
    {
        if ($this->appManager->isInstalled(self::DOCUDESK_APP_ID) === false) {
            return false;
        }

        try {
            $this->documentService();
            $this->templateService();
        } catch (\Throwable $e) {
            return false;
        }

        return true;

    }//end docudeskAvailable()


    /**
     * Template selection: config-UUID first, then namespace/category
     * discovery, fail closed on zero/multiple matches (design.md D3).
     *
     * @param string $documentType The document type.
     *
     * @return array{templateId: string|null, error: string|null}
     */
    private function selectTemplate(string $documentType): array
    {
        $configured = trim($this->settingsService->getDocumentsTemplateId($documentType));
        if ($configured !== '') {
            return ['templateId' => $configured, 'error' => null];
        }

        try {
            $templates = $this->templateService()->getTemplatesByNamespace(self::TEMPLATE_NAMESPACE);
        } catch (\Throwable $e) {
            return ['templateId' => null, 'error' => 'Kon docudesk-sjablonen niet opzoeken: '.$e->getMessage()];
        }

        $matches = [];
        foreach ($this->normaliseRows($templates) as $template) {
            if ((string) ($template['category'] ?? '') === $documentType) {
                $matches[] = $template;
            }
        }

        if (count($matches) === 0) {
            return [
                'templateId' => null,
                'error'      => sprintf(
                    'Geen docudesk-sjabloon gevonden voor "%s" in namespace "hrmq"; genereren is geweigerd.',
                    $documentType
                ),
            ];
        }

        if (count($matches) > 1) {
            $names = array_map(
                static fn(array $t): string => (string) ($t['name'] ?? ($t['id'] ?? ($t['@self']['id'] ?? '?'))),
                $matches
            );

            return [
                'templateId' => null,
                'error'      => sprintf(
                    'Meerdere docudesk-sjablonen (%s) voor "%s" in namespace "hrmq"; genereren is geweigerd (nooit gokken tussen sjablonen die officiële documenten opleveren).',
                    implode(', ', $names),
                    $documentType
                ),
            ];
        }

        $id = (string) ($matches[0]['id'] ?? $matches[0]['@self']['id'] ?? '');
        if ($id === '') {
            return ['templateId' => null, 'error' => 'Het gevonden docudesk-sjabloon heeft geen id.'];
        }

        return ['templateId' => $id, 'error' => null];

    }//end selectTemplate()


    /**
     * The `dataRefs` payload for the docudesk render call (design.md D2,
     * payslip-pdf-docudesk design.md D3): exactly the hrmq Employee plus one
     * subject ref -- EmploymentContract for the four letter types, Payslip
     * for loonstrook, Jaaropgaaf for jaaropgaaf (at most one of the three is
     * ever set) -- docudesk re-resolves the objects itself via OpenRegister,
     * so no object field values are copied here.
     *
     * @param string      $employeeId   The Employee id.
     * @param string|null $contractId   The EmploymentContract id, or null.
     * @param string|null $payslipId    The Payslip id, or null.
     * @param string|null $jaaropgaafId The Jaaropgaaf id, or null.
     *
     * @return array<int, array<string, string>>
     */
    private function buildDataRefs(string $employeeId, ?string $contractId, ?string $payslipId=null, ?string $jaaropgaafId=null): array
    {
        $refs = [
            ['register' => $this->register(), 'schema' => self::EMPLOYEE_SCHEMA, 'id' => $employeeId],
        ];

        if ($contractId !== null) {
            $refs[] = ['register' => $this->register(), 'schema' => self::CONTRACT_SCHEMA, 'id' => $contractId];
        }

        if ($payslipId !== null) {
            $refs[] = ['register' => $this->register(), 'schema' => self::PAYSLIP_SCHEMA, 'id' => $payslipId];
        }

        if ($jaaropgaafId !== null) {
            $refs[] = ['register' => $this->register(), 'schema' => self::JAAROPGAAF_SCHEMA, 'id' => $jaaropgaafId];
        }

        return $refs;

    }//end buildDataRefs()


    /**
     * The `options` payload for the docudesk render call (design.md D2):
     * `adHocData` carries only the configured employer block and generation
     * metadata -- never flattened Employee/Contract field values.
     *
     * @param string      $documentType The document type.
     * @param string|null $userId       The acting user id, or null for 'system'.
     *
     * @return array<string, mixed>
     */
    private function buildOptions(string $documentType, ?string $userId): array
    {
        return [
            'format'    => 'pdf',
            'userId'    => ($userId !== null && trim($userId) !== '') ? $userId : 'system',
            'adHocData' => [
                'employer' => $this->settingsService->getDocumentsEmployerBlock(),
                'document' => [
                    'type'        => $documentType,
                    'requestedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                ],
            ],
        ];

    }//end buildOptions()


    /**
     * The stored PDF's file name (design.md D4): `{documentType}-{employeeNumber}-{YYYY-MM-DD}.pdf`.
     *
     * @param string $documentType The document type.
     * @param string $employeeId   The Employee id (resolved to its employeeNumber).
     *
     * @return string
     */
    private function fileName(string $documentType, string $employeeId): string
    {
        return sprintf('%s-%s-%s.pdf', $documentType, $this->employeeNumber($employeeId), gmdate('Y-m-d'));

    }//end fileName()


    /**
     * The employeeNumber for an Employee id, falling back to the id itself
     * when the Employee cannot be resolved (defensive -- filename readability
     * only, never a hard failure).
     *
     * @param string $employeeId The Employee id.
     *
     * @return string
     */
    private function employeeNumber(string $employeeId): string
    {
        foreach ($this->loadAll(self::EMPLOYEE_SCHEMA) as $employee) {
            $id = (string) ($employee['id'] ?? $employee['@self']['id'] ?? '');
            if ($id === $employeeId) {
                $number = trim((string) ($employee['employeeNumber'] ?? ''));
                return $number === '' ? $employeeId : $number;
            }
        }

        return $employeeId;

    }//end employeeNumber()


    /**
     * Create a new GeneratedDocument.
     *
     * @param array<string, mixed> $fields The object fields.
     *
     * @return array<string, mixed> The created object, normalised to an array.
     */
    private function createGeneratedDocument(array $fields): array
    {
        $created = $this->objectService()->saveObject(
            object: $fields,
            register: $this->register(),
            schema: self::GENERATED_DOCUMENT_SCHEMA,
            _rbac: false,
            _multitenancy: false
        );

        return $this->toArray($created);

    }//end createGeneratedDocument()


    /**
     * Update an existing GeneratedDocument by merging $fields onto it.
     *
     * @param array<string, mixed> $existing The current GeneratedDocument.
     * @param array<string, mixed> $fields   The fields to overwrite.
     *
     * @return array<string, mixed> The saved object, normalised to an array.
     */
    private function saveGeneratedDocument(array $existing, array $fields): array
    {
        $id = (string) ($existing['id'] ?? $existing['@self']['id'] ?? '');

        $payload = array_merge($existing, $fields);
        unset($payload['@self']);

        $saved = $this->objectService()->saveObject(
            object: $payload,
            register: $this->register(),
            schema: self::GENERATED_DOCUMENT_SCHEMA,
            uuid: ($id === '' ? null : $id),
            _rbac: false,
            _multitenancy: false
        );

        return $this->toArray($saved);

    }//end saveGeneratedDocument()


    /**
     * Create a new Jaaropgaaf (payslip-pdf-docudesk).
     *
     * @param array<string, mixed> $fields The object fields.
     *
     * @return array<string, mixed> The created object, normalised to an array.
     */
    private function createJaaropgaaf(array $fields): array
    {
        $created = $this->objectService()->saveObject(
            object: $fields,
            register: $this->register(),
            schema: self::JAAROPGAAF_SCHEMA,
            _rbac: false,
            _multitenancy: false
        );

        return $this->toArray($created);

    }//end createJaaropgaaf()


    /**
     * Update an existing Jaaropgaaf by merging $fields onto it -- the
     * upsert-per-(employeeId, year) invariant (payslip-pdf-docudesk design.md D4).
     *
     * @param array<string, mixed> $existing The current Jaaropgaaf.
     * @param array<string, mixed> $fields   The fields to overwrite.
     *
     * @return array<string, mixed> The saved object, normalised to an array.
     */
    private function saveJaaropgaaf(array $existing, array $fields): array
    {
        $id = (string) ($existing['id'] ?? $existing['@self']['id'] ?? '');

        $payload = array_merge($existing, $fields);
        unset($payload['@self']);

        $saved = $this->objectService()->saveObject(
            object: $payload,
            register: $this->register(),
            schema: self::JAAROPGAAF_SCHEMA,
            uuid: ($id === '' ? null : $id),
            _rbac: false,
            _multitenancy: false
        );

        return $this->toArray($saved);

    }//end saveJaaropgaaf()


    /**
     * Build the outcome array returned by generate()/generateBacklog().
     *
     * @param string                     $employeeId   The Employee id.
     * @param string|null                $contractId   The EmploymentContract id, or null.
     * @param string                     $documentType The document type.
     * @param string                     $status       The outcome status.
     * @param string                     $message      A human-readable outcome message.
     * @param array<string, mixed>|null  $document     The GeneratedDocument record, if any.
     *
     * @return array<string, mixed>
     */
    private function outcome(
        string $employeeId,
        ?string $contractId,
        string $documentType,
        string $status,
        string $message,
        ?array $document=null
    ): array {
        $documentId = null;
        if ($document !== null) {
            $documentId = (string) ($document['id'] ?? $document['@self']['id'] ?? '');
            $documentId = ($documentId === '' ? null : $documentId);
        }

        return [
            'employeeId'          => $employeeId,
            'contractId'          => $contractId,
            'documentType'        => $documentType,
            'status'              => $status,
            'message'             => $message,
            'generatedDocumentId' => $documentId,
        ];

    }//end outcome()


    /**
     * Load all objects of a schema (capped), as plain arrays.
     *
     * @param string $schema The schema name.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadAll(string $schema): array
    {
        try {
            $rows = $this->objectService()->setRegister($this->register())->setSchema($schema)->findAll(['limit' => self::LIMIT]);
        } catch (\Throwable $e) {
            $this->logger->warning('HrDocumentService: kon '.$schema.' niet laden: '.$e->getMessage());
            return [];
        }

        return $this->normaliseRows($rows);

    }//end loadAll()


    /**
     * Normalise a list of ObjectService/docudesk rows (entities or arrays) to arrays.
     *
     * @param mixed $rows Raw rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normaliseRows(mixed $rows): array
    {
        $out = [];
        foreach ((is_array($rows) === true ? $rows : []) as $row) {
            $out[] = $this->toArray($row);
        }

        return $out;

    }//end normaliseRows()


    /**
     * Normalise a single ObjectService/docudesk row (entity or array) to an array.
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


    /**
     * @return mixed The OpenRegister ObjectService.
     */
    private function objectService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()


    /**
     * @return mixed The OpenRegister FileService (read-only reuse -- never docudesk).
     */
    private function fileService(): mixed
    {
        return $this->container->get(self::OBJECT_FILE_SERVICE_FQCN);

    }//end fileService()


    /**
     * @return mixed docudesk's DocumentService, resolved by string FQCN only (design.md D2).
     */
    private function documentService(): mixed
    {
        return $this->container->get(self::DOCUMENT_SERVICE_FQCN);

    }//end documentService()


    /**
     * @return mixed docudesk's TemplateService, resolved by string FQCN only (design.md D2).
     */
    private function templateService(): mixed
    {
        return $this->container->get(self::TEMPLATE_SERVICE_FQCN);

    }//end templateService()


    /**
     * @return string The configured hrmq register slug.
     */
    private function register(): string
    {
        return $this->settingsService->getRegisterSlug();

    }//end register()


}//end class
