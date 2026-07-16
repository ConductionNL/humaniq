<?php

/**
 * AVG Data-Subject-Rights Service
 *
 * Thin hrmq orchestration layer over OpenRegister's `DsarService` (avg-dsr
 * design.md): maps a `DsrRequest.employeeId` to the subject value
 * `DsarService` expects (design.md D1 -- `Employee.bsn`, resolved
 * transiently, in memory, at call time, never persisted or logged), renders
 * `findObjectsForSubject()` once for both inzage/portabiliteit (design.md
 * D2), classifies every matched object as retention-locked or
 * erase-eligible against the statutory fiscal retention duty
 * (`AvgDsrRetentionClassifier`, design.md D4), and drives the two-path
 * erase (design.md D5): a fast wholesale `eraseObjectsForSubject()` call
 * when nothing is retention-locked, or a per-object
 * `rectifyObjectForSubject()` anonymisation loop over ONLY the eligible
 * objects when something is retained -- a retention-locked object is NEVER
 * passed into either DsarService write call, the structural guarantee
 * `eraseObjectsForSubject()`'s lack of a per-object exclusion parameter
 * otherwise could not provide. Rectification (design.md D6) is a direct
 * pass-through with no retention guard. Every operation's outcome is
 * recorded onto the `DsrRequest` bookkeeping record via
 * `AvgDsrRequestStore`.
 *
 * `DsarService` has zero new call sites beyond its three existing public
 * methods -- no reimplementation of entity matching, soft-delete, or
 * anonymisation logic lives in hrmq (ADR-022).
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
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-002
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-003
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-005
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-006
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCP\IUserSession;

/**
 * Orchestrates AVG data-subject-rights operations over OpenRegister's
 * DsarService, with a structural statutory-retention guard on erasure.
 */
class AvgDsrService
{

    /**
     * Candidate PII field names (drawn from the Employee schema, design.md
     * D5 risk note) nulled by the guarded per-object anonymisation path when
     * present on a matched, erase-eligible object. Deliberately small and
     * reviewed against real schema fields rather than an exhaustive assumed
     * list (design.md Risks).
     *
     * @var array<int, string>
     */
    private const ANONYMISATION_FIELD_CANDIDATES = [
        'firstName',
        'lastName',
        'bsn',
        'iban',
        'tenaamstelling',
        'dateOfBirth',
        'email',
    ];

    /**
     * The pure retention-classification predicate (design.md D4), extracted
     * to keep it small, testable in isolation, and independent of the
     * OpenRegister I/O the rest of this service performs.
     *
     * @var AvgDsrRetentionClassifier
     */
    private readonly AvgDsrRetentionClassifier $retentionClassifier;

    /**
     * The `DsrRequest` bookkeeping load/save mechanics, extracted to keep
     * the DSAR composition logic in this class separate from plain
     * OpenRegister CRUD.
     *
     * @var AvgDsrRequestStore
     */
    private readonly AvgDsrRequestStore $requestStore;


    /**
     * @param ContainerInterface $container       DI container for the lazy DsarService/ObjectService resolve (OpenRegister is a hard dependency, resolved by FQCN, never duck-typed).
     * @param SettingsService    $settingsService The register-slug source.
     * @param IUserSession       $userSession     The current (by now privileged) session -- the privileged session itself is established BEFORE this service is called (design.md D3); forwarded to `AvgDsrRequestStore` only (`handledBy`), not retained as a property here.
     * @param LoggerInterface    $logger          Logger. Never receives a raw bsn value (REQ-DSR-002).
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        $this->retentionClassifier = new AvgDsrRetentionClassifier();
        $this->requestStore        = new AvgDsrRequestStore($container, $settingsService, $userSession, $logger);

    }//end __construct()


    /**
     * Resolve the `DsarService` subject value for an employee (design.md
     * D1): RBAC-resolves the `Employee` object and returns its `bsn` field
     * transiently, in memory. The caller MUST NOT persist or log the return
     * value (REQ-DSR-002).
     *
     * @param string $employeeId The Employee id (`DsrRequest.employeeId`).
     *
     * @return string The resolved bsn, or '' when the employee does not resolve or carries no bsn.
     *
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-002
     */
    public function resolveSubject(string $employeeId): string
    {
        try {
            $entity = $this->objectService()->find(
                id: $employeeId,
                register: $this->settingsService->getRegisterSlug(),
                schema: 'Employee'
            );
        } catch (\Throwable $e) {
            $this->logger->info('AvgDsrService: werknemer '.$employeeId.' kon niet worden opgehaald: '.$e->getMessage());
            return '';
        }

        if ($entity === null) {
            return '';
        }

        return (string) ($this->toArray($entity)['bsn'] ?? '');

    }//end resolveSubject()


    /**
     * Export the AVG overview for one employee -- Art 15 inzage / Art 20
     * portabiliteit are the SAME `findObjectsForSubject()` call, rendered two
     * ways (design.md D2): grouped-by-object with `gdprEntities` annotated
     * for `inzage`, flattened into a single structured document for
     * `portabiliteit`. Exactly one `DsarService` call regardless of `$right`.
     *
     * @param string      $employeeId   The Employee id.
     * @param string      $right        `inzage` or `portabiliteit`.
     * @param string|null $dsrRequestId Optional DsrRequest id to record this export outcome against.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-003
     */
    public function exportForSubject(string $employeeId, string $right, ?string $dsrRequestId=null): array
    {
        $subject   = $this->resolveSubject($employeeId);
        $envelopes = $this->dsarService()->findObjectsForSubject($subject);
        $result    = $this->renderExport($envelopes, $right);

        if ($dsrRequestId !== null && $dsrRequestId !== '') {
            $this->requestStore->recordExportOutcome($dsrRequestId, $right, count($envelopes));
        }

        return $result;

    }//end exportForSubject()


    /**
     * Render the SAME `findObjectsForSubject()` result set for either right
     * (design.md D2) -- `inzage`: grouped-by-object with `gdprEntities`
     * annotated; `portabiliteit`: the same objects flattened into a single
     * structured document.
     *
     * @param array<int, array<string, mixed>> $envelopes `findObjectsForSubject()`'s return value.
     * @param string                            $right     `inzage` or `portabiliteit`.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-003
     */
    private function renderExport(array $envelopes, string $right): array
    {
        if ($right === 'portabiliteit') {
            $objects = array_map(static fn(array $envelope): array => (array) ($envelope['object'] ?? []), $envelopes);
            return [
                'right'     => 'portabiliteit',
                'generated' => gmdate('c'),
                'count'     => count($objects),
                'objects'   => $objects,
            ];
        }

        return [
            'right'   => 'inzage',
            'count'   => count($envelopes),
            'objects' => $envelopes,
        ];

    }//end renderExport()


    /**
     * Classify every object `findObjectsForSubject()` returns for one
     * employee as retention-locked (`retained`) or erase-eligible
     * (`eligible`) -- the D4 predicate. The single function both the preview
     * and execute paths call -- never duplicated between them.
     *
     * @param string $employeeId The Employee id.
     *
     * @return array{retained: array<int, array<string, mixed>>, eligible: array<int, array<string, mixed>>}
     *
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-005
     */
    public function classifyForErasure(string $employeeId): array
    {
        $subject   = $this->resolveSubject($employeeId);
        $envelopes = $this->dsarService()->findObjectsForSubject($subject);

        return $this->retentionClassifier->classify($envelopes);

    }//end classifyForErasure()


    /**
     * Zero-write erasure preview (design.md D5) -- the same classification
     * `eraseSubject()` would use, relabelled `wouldErase`/`retained`/
     * `failed`. Neither `eraseObjectsForSubject(dryRun:false)` nor
     * `rectifyObjectForSubject()` is called. When `$dsrRequestId` is given,
     * the preview is RECORDED onto that `DsrRequest` -- the evidence
     * `eraseSubject()`'s precondition checks for. That write touches only
     * the `DsrRequest` bookkeeping record, never a subject's data object.
     *
     * @param string      $employeeId   The Employee id.
     * @param string|null $dsrRequestId Optional DsrRequest id to record this preview against.
     *
     * @return array{wouldErase: array<int, array<string, mixed>>, retained: array<int, array<string, mixed>>, failed: array<int, array<string, mixed>>}
     *
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-005
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-006
     */
    public function previewErasure(string $employeeId, ?string $dsrRequestId=null): array
    {
        $classification = $this->classifyForErasure($employeeId);

        $preview = [
            'wouldErase' => $classification['eligible'],
            'retained'   => $classification['retained'],
            'failed'     => [],
        ];

        if ($dsrRequestId !== null && $dsrRequestId !== '') {
            $this->requestStore->recordPreview($dsrRequestId, $preview);
        }

        return $preview;

    }//end previewErasure()


    /**
     * Execute the retention-guarded erase (design.md D5) for a `DsrRequest`
     * whose preview already ran (`status: in_behandeling` and a recorded
     * `retainedObjectRefs` -- the preview marker `previewErasure()` writes).
     * Re-derives `classifyForErasure()` fresh (idempotency, design.md D5):
     *
     *   - `retained` empty  -> ONE wholesale `eraseObjectsForSubject()` call.
     *   - `retained` non-empty -> `eraseObjectsForSubject()` is NEVER called
     *     for this subject; instead a per-object `rectifyObjectForSubject()`
     *     anonymisation loop runs over `eligible` ONLY. A retention-locked
     *     object is referenced in NEITHER DsarService write call.
     *
     * @param string $employeeId   The Employee id.
     * @param string $dsrRequestId The DsrRequest id whose preview already ran.
     *
     * @return array<string, mixed> {status, erased, retained, failed} on a run, or {status: 'refused', message} when the precondition is unmet -- a controlled refusal, never a write.
     *
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-005
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-006
     */
    public function eraseSubject(string $employeeId, string $dsrRequestId): array
    {
        $dsrRequest = $this->requestStore->load($dsrRequestId);
        if ($dsrRequest === null) {
            return [
                'status'  => 'refused',
                'message' => 'DsrRequest niet gevonden.',
            ];
        }

        if ($this->hasRecordedPreview($dsrRequest) === false) {
            return [
                'status'  => 'refused',
                'message' => 'Geen geregistreerd voorbeeld gevonden voor dit verzoek — voer eerst een voorbeeldverwijdering uit.',
            ];
        }

        $classification = $this->classifyForErasure($employeeId);
        $subject        = $this->resolveSubject($employeeId);

        // Fast path (design.md D5) when nothing is retention-locked: the
        // whole subject is safe to erase in one wholesale sweep. Guarded path
        // otherwise: eraseObjectsForSubject() is NEVER called for this
        // subject -- it would sweep the retained objects too -- a per-object
        // anonymisation loop runs over `eligible` ONLY instead.
        [$erased, $failed] = ($classification['retained'] === [])
            ? $this->eraseWholesale($subject)
            : $this->eraseGuarded($classification['eligible']);

        $newStatus = $this->requestStore->recordEraseOutcome($dsrRequest, $erased, $classification['retained'], $failed);

        return [
            'status'   => $newStatus,
            'erased'   => $erased,
            'retained' => $classification['retained'],
            'failed'   => $failed,
        ];

    }//end eraseSubject()


    /**
     * Whether a `DsrRequest` has a recorded preview -- `in_behandeling` and a
     * non-null `retainedObjectRefs` (the marker `previewErasure()` always
     * sets, even to an empty list).
     *
     * @param array<string, mixed> $dsrRequest The DsrRequest.
     *
     * @return bool
     */
    private function hasRecordedPreview(array $dsrRequest): bool
    {
        $status = (string) ($dsrRequest['status'] ?? '');
        return ($status === 'in_behandeling' && ($dsrRequest['retainedObjectRefs'] ?? null) !== null);

    }//end hasRecordedPreview()


    /**
     * The fast path (design.md D5): ONE wholesale `eraseObjectsForSubject()`
     * call, safe because `classifyForErasure()` found nothing retained.
     *
     * @param string $subject The resolved DsarService subject value.
     *
     * @return array{0: array<int, array<string,mixed>>, 1: array<int, array<string,mixed>>} [erased, failed].
     */
    private function eraseWholesale(string $subject): array
    {
        $summary = $this->dsarService()->eraseObjectsForSubject($subject, null, false);

        return [
            (array) ($summary['erased'] ?? []),
            (array) ($summary['failed'] ?? []),
        ];

    }//end eraseWholesale()


    /**
     * The guarded path (design.md D5): `eraseObjectsForSubject()` is NEVER
     * called here -- a per-object `rectifyObjectForSubject()` anonymisation
     * loop runs over `$eligible` ONLY, never over a retention-locked object.
     *
     * @param array<int, array<string, mixed>> $eligible The `eligible` refs from classifyForErasure().
     *
     * @return array{0: array<int, array<string,mixed>>, 1: array<int, array<string,mixed>>} [erased, failed].
     */
    private function eraseGuarded(array $eligible): array
    {
        $erased = [];
        $failed = [];

        foreach ($eligible as $ref) {
            $this->anonymiseEligibleObject($ref, $erased, $failed);
        }

        return [$erased, $failed];

    }//end eraseGuarded()


    /**
     * Direct rectification pass-through (design.md D6, Art 16) -- NO
     * retention classification applies (a correction does not remove data).
     * Records only the changed field NAMES on `DsrRequest.outcomeSummary`,
     * never before/after values.
     *
     * @param int                  $objectId     Internal id of the object to update (the caller RBAC-resolves it first).
     * @param array<string, mixed> $changes      Property -> new value map.
     * @param string               $dsrRequestId The DsrRequest id this rectification is recorded against.
     *
     * @return array<string, mixed>|null The updated object envelope, or null when `rectifyObjectForSubject()` could not load/update the object.
     *
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-007
     */
    public function rectifySubjectObject(int $objectId, array $changes, string $dsrRequestId): ?array
    {
        $dsrRequest = $this->requestStore->load($dsrRequestId);
        $result     = $this->dsarService()->rectifyObjectForSubject($objectId, $changes);

        if ($dsrRequest !== null) {
            $this->requestStore->recordRectifyOutcome($dsrRequest, $result, $changes);
        }

        return $result;

    }//end rectifySubjectObject()


    /**
     * Resolve one eligible object's internal id and anonymise it via
     * `rectifyObjectForSubject()`, appending to `$erased`/`$failed` in place.
     * Never called for a retention-locked object (REQ-DSR-005).
     *
     * @param array<string, mixed>            $ref    {uuid, schema, register} from classifyForErasure().
     * @param array<int, array<string,mixed>> $erased Accumulator, by reference.
     * @param array<int, array<string,mixed>> $failed Accumulator, by reference.
     *
     * @return void
     */
    private function anonymiseEligibleObject(array $ref, array &$erased, array &$failed): void
    {
        try {
            $entity = $this->objectService()->find(
                id: (string) $ref['uuid'],
                register: (string) $ref['register'],
                schema: (string) $ref['schema']
            );
        } catch (\Throwable $e) {
            $entity = null;
        }

        if ($entity === null) {
            $failed[] = ['object' => $ref, 'error' => 'Object kon niet worden opgehaald voor anonimisatie.'];
            return;
        }

        $internalId = $this->extractInternalId($entity);
        if ($internalId === null) {
            $failed[] = ['object' => $ref, 'error' => 'Object-id kon niet worden bepaald voor anonimisatie.'];
            return;
        }

        $changes = $this->anonymisationChangesFor($this->toArray($entity));

        $result = $this->dsarService()->rectifyObjectForSubject($internalId, $changes);
        if ($result === null) {
            $failed[] = ['object' => $ref, 'error' => 'Anonimisatie mislukt.'];
            return;
        }

        $erased[] = $ref;

    }//end anonymiseEligibleObject()


    /**
     * The per-object anonymisation change set (design.md D5 step 3): null
     * every candidate PII field actually present (and non-null) on the
     * object. Small and reviewed against real schema fields, not an
     * exhaustive assumed list (design.md Risks).
     *
     * @param array<string, mixed> $object The object data.
     *
     * @return array<string, mixed>
     */
    private function anonymisationChangesFor(array $object): array
    {
        $changes = [];
        foreach (self::ANONYMISATION_FIELD_CANDIDATES as $field) {
            if (array_key_exists($field, $object) === true && $object[$field] !== null) {
                $changes[$field] = null;
            }
        }

        return $changes;

    }//end anonymisationChangesFor()


    /**
     * The real internal (int) primary key of an object, resolved via
     * `ObjectService::find()`'s returned entity's `getId()` -- distinct from
     * the string uuid `jsonSerialize()` exposes under `id`.
     * `DsarService::rectifyObjectForSubject()` requires this real int id
     * (`declare(strict_types=1)` in openregister forbids passing a uuid
     * string to its `int $objectId` parameter).
     *
     * @param mixed $entity The resolved entity (an OpenRegister ObjectEntity in production, or a fake test double exposing the same `getId(): int` contract).
     *
     * @return int|null
     */
    private function extractInternalId(mixed $entity): ?int
    {
        if (is_object($entity) === true && method_exists($entity, 'getId') === true) {
            $id = $entity->getId();
            if (is_int($id) === true) {
                return $id;
            }

            if (is_numeric($id) === true) {
                return (int) $id;
            }
        }

        return null;

    }//end extractInternalId()


    /**
     * @return mixed The OpenRegister ObjectService, resolved with the caller's ambient RBAC (default $_rbac=true).
     */
    private function objectService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()


    /**
     * @return mixed OpenRegister's DsarService -- every public method
     *               throws `RuntimeException` unless the active session is a
     *               privileged (admin) one (design.md Context/D3).
     */
    private function dsarService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\DsarService');

    }//end dsarService()


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
