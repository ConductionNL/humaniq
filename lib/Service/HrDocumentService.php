<?php

/**
 * HR Document Service
 *
 * Generates the standard Dutch HR documents (arbeidsovereenkomst,
 * aanbiedingsbrief, werkgeversverklaring, getuigschrift) by invoking docudesk's
 * template/rendering engine same-instance and duck-typed (hrmq-docudesk-documents
 * design.md D1). hrmq holds no Twig, mPDF, template, or versioning machinery of
 * its own: the only artefact this service writes on the hrmq side is a
 * `GeneratedDocument` record logging the handoff -- which template, for whom,
 * the outcome, and where the file landed. Rendering itself happens inside
 * docudesk's sandbox (`OCA\DocuDesk\Service\DocumentService::generateDocument()`),
 * resolved exclusively by string FQCN through the DI container -- no
 * compile-time import, no composer/info.xml dependency on docudesk.
 *
 * Availability is duck-typed (ADR-046 philosophy, mirroring
 * `OCA\Hrmq\Service\PayrollGLPostService`): when docudesk is not installed, or
 * its services cannot be resolved, the attempt is recorded
 * `skipped-no-docudesk` and is retryable by a later `occ hrmq:documents:generate`
 * or the EmploymentContractDetail page action (design.md D5). hrmq carries zero
 * composer/info.xml dependency on docudesk.
 *
 * Template selection is config-first, discovery-second, and fails closed
 * (design.md D3): a configured docudesk template UUID always wins; otherwise
 * exactly one `namespace: "hrmq"` template whose `category` matches the
 * documentType is used -- zero or multiple matches record the attempt `failed`
 * with a diagnostic, and nothing renders (never guess between templates that
 * produce legal paper).
 *
 * Idempotency (design.md D6): at most one GeneratedDocument in
 * `{pending, generated}` per (contractId, documentType) -- or per
 * (employeeId, documentType) when contractId is null. An existing `generated`
 * record is a no-op; a stale `pending` record is superseded (marked `failed`)
 * before a fresh attempt starts. `failed`/`skipped-no-docudesk` never block a
 * retry.
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
 * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md
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
     * The documentType the occ backlog processes by default (design.md D7).
     *
     * @var string
     */
    private const BACKLOG_DOCUMENT_TYPE = 'arbeidsovereenkomst';

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
     * @param ContainerInterface $container       DI container for lazy ObjectService/docudesk/FileService resolution.
     * @param IAppManager        $appManager      To duck-type-probe docudesk's presence.
     * @param SettingsService    $settingsService Register slug, template config, and employer block.
     * @param LoggerInterface    $logger          Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * The occ backlog trigger (design.md D7): with no options, every permanent
     * written EmploymentContract is processed for `arbeidsovereenkomst`
     * (already-documented contracts simply no-op via the idempotency
     * pre-check). A non-default documentType has no backlog semantics and
     * requires `$employeeId`; when it is missing this returns a single
     * `usage-error` outcome and generates nothing.
     *
     * @param string|null $documentType Restrict/switch the documentType, or null for the default backlog.
     * @param string|null $employeeId   Restrict to one Employee id.
     *
     * @return array<int, array<string, mixed>> One outcome array per attempt.
     *
     * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-007
     */
    public function generateBacklog(?string $documentType=null, ?string $employeeId=null): array
    {
        $type = ($documentType !== null && trim($documentType) !== '') ? trim($documentType) : self::BACKLOG_DOCUMENT_TYPE;
        $employeeId = ($employeeId !== null && trim($employeeId) !== '') ? trim($employeeId) : null;

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
        $employeeId = trim($employeeId);
        $contractId = ($contractId !== null && trim($contractId) !== '') ? trim($contractId) : null;

        if ($employeeId === '') {
            return $this->outcome($employeeId, $contractId, $documentType, 'failed', 'Geen employeeId opgegeven; genereren is geweigerd.');
        }

        $active = $this->activeDocumentFor($employeeId, $contractId, $documentType);
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
                'templateRef'  => $selected['templateId'],
                'status'       => 'pending',
            ]
        );

        $dataRefs = $this->buildDataRefs($employeeId, $contractId);
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

    }//end generate()


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
     * The active (pending/generated) GeneratedDocument for a (contract|employee,
     * documentType) key, if any -- the at-most-one-active invariant (design.md D6).
     *
     * @param string      $employeeId   The Employee id.
     * @param string|null $contractId   The EmploymentContract id, or null.
     * @param string      $documentType The document type.
     *
     * @return array<string, mixed>|null
     */
    private function activeDocumentFor(string $employeeId, ?string $contractId, string $documentType): ?array
    {
        foreach ($this->loadAll(self::GENERATED_DOCUMENT_SCHEMA) as $row) {
            if ((string) ($row['documentType'] ?? '') !== $documentType) {
                continue;
            }

            $rowContractId = trim((string) ($row['contractId'] ?? ''));
            if ($contractId !== null) {
                if ($rowContractId !== $contractId) {
                    continue;
                }
            } elseif ($rowContractId !== '' || trim((string) ($row['employeeId'] ?? '')) !== $employeeId) {
                continue;
            }

            if (in_array((string) ($row['status'] ?? ''), self::ACTIVE_STATUSES, true) === true) {
                return $row;
            }
        }

        return null;

    }//end activeDocumentFor()


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
     * The `dataRefs` payload for the docudesk render call (design.md D2):
     * exactly the hrmq Employee (and, when present, EmploymentContract)
     * object refs -- docudesk re-resolves the objects itself via OpenRegister,
     * so no object field values are copied here.
     *
     * @param string      $employeeId The Employee id.
     * @param string|null $contractId The EmploymentContract id, or null.
     *
     * @return array<int, array<string, string>>
     */
    private function buildDataRefs(string $employeeId, ?string $contractId): array
    {
        $refs = [
            ['register' => $this->register(), 'schema' => self::EMPLOYEE_SCHEMA, 'id' => $employeeId],
        ];

        if ($contractId !== null) {
            $refs[] = ['register' => $this->register(), 'schema' => self::CONTRACT_SCHEMA, 'id' => $contractId];
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
