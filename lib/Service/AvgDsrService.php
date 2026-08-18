<?php

/**
 * AVG Data-Subject-Rights Service
 *
 * Thin hrmq orchestration layer over OpenRegister's RBAC/tenant-scoped
 * `OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService` (hrmq#99 --
 * consume-not-rebuild correction, superseding the original avg-dsr design):
 * maps a `DsrRequest.employeeId` to the subject value the guarded service
 * expects (`Employee.bsn`, resolved transiently, in memory, at call time,
 * never persisted or logged), renders `findSubjectData()` once for both
 * inzage/portabiliteit, and drives erasure (Art 17) directly through the
 * guarded service's own `erase()` -- which REFUSES a legal-hold or
 * immutable-archival-status object on its own, from OpenRegister's real
 * retention/legal-hold machinery (`RetentionService`), not a bespoke
 * per-object exclusion loop hrmq maintained itself.
 *
 * hrmq#99 background: the previous design duplicated OpenRegister's
 * retention/legal-hold machinery with its own `AvgDsrRetentionClassifier`
 * (a `retainedUntil`/`identityDocumentRetainedUntil` field plus a
 * hand-rolled AWR art. 52 lid 4 "period year + 7" derivation) and called the
 * *privileged, unguarded* `OCA\OpenRegister\Service\DsarService` directly,
 * structurally excluding retained objects itself before ever calling
 * `eraseObjectsForSubject()`/`rectifyObjectForSubject()`. That left two real
 * AVG retention holes (a generated payslip/jaaropgaaf PDF carried no
 * retention signal of its own, and nothing flagged a record still present
 * past its own retention ceiling -- see `PayrollRetentionGuardService` and
 * `NlDossierRetentionChecks`). This class now consumes the guarded,
 * RBAC-scoped `DataSubjectRequestService::erase()` instead: retention state
 * lives on the OBJECT (legal hold / archival status), maintained proactively
 * by `PayrollRetentionGuardService`, not recomputed ad hoc on every DSAR
 * call. `DataSubjectRequestService`'s guard does not itself gate on
 * `retention.archiefactiedatum` (openregister#475) -- only on an active
 * legal hold or an immutable archival status -- which is exactly why
 * `PayrollRetentionGuardService` translates a still-open statutory retention
 * window into a real legal hold rather than leaving it as a date field only
 * OpenRegister's destruction-eligibility listing would ever read.
 *
 * `DataSubjectRequestService` has no new call sites beyond its public
 * `findSubjectData()`/`erase()`/`rectify()` methods -- no reimplementation of
 * entity matching, soft-delete, pseudonymisation, or retention-guarding logic
 * lives in hrmq (ADR-022).
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-002
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-003
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-006
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates AVG data-subject-rights operations over OpenRegister's
 * guarded, RBAC/tenant-scoped `Gdpr\DataSubjectRequestService` -- retention
 * enforcement on erasure is OpenRegister's, not a bespoke hrmq classifier.
 */
class AvgDsrService {

	/**
	 * The guarded service's field-level erase mode (mirrors
	 * `DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE` -- inlined as a
	 * literal, not imported, per this codebase's no-compile-time-dependency
	 * convention for OpenRegister classes resolved by FQCN).
	 *
	 * @var string
	 */
	private const ERASE_MODE_PSEUDONYMISE = 'pseudonymise';

	/**
	 * The FQCN of OpenRegister's guarded, RBAC/tenant-scoped data-subject-
	 * request service (hrmq#99) -- resolved via the DI container only, never
	 * duck-typed or compile-time imported (ADR-022).
	 *
	 * @var string
	 */
	private const GUARDED_SERVICE_FQCN = 'OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService';

	/**
	 * The `DsrRequest` bookkeeping load/save mechanics, extracted to keep
	 * the DSAR composition logic in this class separate from plain
	 * OpenRegister CRUD.
	 *
	 * @var AvgDsrRequestStore
	 */
	private readonly AvgDsrRequestStore $requestStore;

	/**
	 * @param ContainerInterface $container DI container for the lazy DataSubjectRequestService/ObjectService resolve (OpenRegister is a hard dependency, resolved by FQCN, never duck-typed).
	 * @param SettingsService $settingsService The register-slug source.
	 * @param IUserSession $userSession The current session, forwarded to `AvgDsrRequestStore` only (`handledBy`), not retained as a property here. The guarded service itself uses the ambient session for RBAC/tenant scoping -- no privileged-session establishment is required for it (unlike the previous `DsarService`-based design).
	 * @param LoggerInterface $logger Logger. Never receives a raw bsn value (REQ-DSR-002).
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		$this->requestStore = new AvgDsrRequestStore($container, $settingsService, $userSession, $logger);

	}//end __construct()

	/**
	 * Resolve the guarded service's subject value for an employee (design.md
	 * D1): RBAC-resolves the `Employee` object and returns its `bsn` field
	 * transiently, in memory. The caller MUST NOT persist or log the return
	 * value (REQ-DSR-002).
	 *
	 * @param string $employeeId The Employee id (`DsrRequest.employeeId`).
	 *
	 * @return string The resolved bsn, or '' when the employee does not resolve or carries no bsn.
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-002
	 */
	public function resolveSubject(string $employeeId): string {
		try {
			$entity = $this->objectService()->find(
				id: $employeeId,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'Employee'
			);
		} catch (\Throwable $e) {
			$this->logger->info('AvgDsrService: werknemer ' . $employeeId . ' kon niet worden opgehaald: ' . $e->getMessage());
			return '';
		}

		if ($entity === null) {
			return '';
		}

		return (string)($this->toArray($entity)['bsn'] ?? '');
	}//end resolveSubject()

	/**
	 * Export the AVG overview for one employee -- Art 15 inzage / Art 20
	 * portabiliteit are the SAME `findSubjectData()` call, rendered two ways
	 * (design.md D2): grouped-by-object with `gdprEntities` annotated for
	 * `inzage`, flattened into a single structured document for
	 * `portabiliteit`. Exactly one guarded-service call regardless of `$right`.
	 *
	 * @param string $employeeId The Employee id.
	 * @param string $right `inzage` or `portabiliteit`.
	 * @param string|null $dsrRequestId Optional DsrRequest id to record this export outcome against.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-003
	 */
	public function exportForSubject(string $employeeId, string $right, ?string $dsrRequestId = null): array {
		$subject = $this->resolveSubject($employeeId);
		$envelopes = $this->guardedService()->findSubjectData($subject);
		$result = $this->renderExport($envelopes, $right);

		if ($dsrRequestId !== null && $dsrRequestId !== '') {
			$this->requestStore->recordExportOutcome($dsrRequestId, $right, count($envelopes));
		}

		return $result;
	}//end exportForSubject()

	/**
	 * Render the SAME `findSubjectData()` result set for either right
	 * (design.md D2) -- `inzage`: grouped-by-object with `gdprEntities`
	 * annotated; `portabiliteit`: the same objects flattened into a single
	 * structured document.
	 *
	 * @param array<int, array<string, mixed>> $envelopes `findSubjectData()`'s return value.
	 * @param string $right `inzage` or `portabiliteit`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-003
	 */
	private function renderExport(array $envelopes, string $right): array {
		if ($right === 'portabiliteit') {
			$objects = array_map(static fn (array $envelope): array => (array)($envelope['object'] ?? []), $envelopes);
			return [
				'right' => 'portabiliteit',
				'generated' => gmdate('c'),
				'count' => count($objects),
				'objects' => $objects,
			];
		}

		return [
			'right' => 'inzage',
			'count' => count($envelopes),
			'objects' => $envelopes,
		];

	}//end renderExport()

	/**
	 * Zero-write erasure preview (design.md D5, hrmq#99): calls the guarded
	 * service's own `erase(..., dryRun: true)` -- the SAME retention guard
	 * (`RetentionService::hasActiveLegalHold()` + `validateNotImmutable()`)
	 * `eraseSubject()` would hit, never a bespoke hrmq classification. When
	 * `$dsrRequestId` is given, the preview is RECORDED onto that
	 * `DsrRequest` -- the evidence `eraseSubject()`'s precondition checks
	 * for. That write touches only the `DsrRequest` bookkeeping record,
	 * never a subject's data object (a dry run performs no object writes).
	 *
	 * @param string $employeeId The Employee id.
	 * @param string|null $dsrRequestId Optional DsrRequest id to record this preview against.
	 *
	 * @return array{wouldErase: array<int, array<string, mixed>>, retained: array<int, array<string, mixed>>, failed: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-006
	 */
	public function previewErasure(string $employeeId, ?string $dsrRequestId = null): array {
		$subject = $this->resolveSubject($employeeId);
		$summary = $this->guardedService()->erase($subject, null, self::ERASE_MODE_PSEUDONYMISE, true);

		$preview = [
			'wouldErase' => (array)($summary['erased'] ?? []),
			'retained' => (array)($summary['held'] ?? []),
			'failed' => (array)($summary['failed'] ?? []),
		];

		if ($dsrRequestId !== null && $dsrRequestId !== '') {
			$this->requestStore->recordPreview($dsrRequestId, $preview);
		}

		return $preview;
	}//end previewErasure()

	/**
	 * Execute the retention-guarded erase (design.md D5, hrmq#99) for a
	 * `DsrRequest` whose preview already ran (`status: in_behandeling` and a
	 * recorded `retainedObjectRefs` -- the preview marker `previewErasure()`
	 * writes). Calls the guarded service's `erase()` directly (dryRun:
	 * false) -- it refuses a legal-hold or immutable-archival-status object
	 * itself; hrmq no longer excludes objects via its own classification
	 * before the call.
	 *
	 * @param string $employeeId The Employee id.
	 * @param string $dsrRequestId The DsrRequest id whose preview already ran.
	 *
	 * @return array<string, mixed> {status, erased, retained, failed} on a run, or {status: 'refused', message} when the precondition is unmet -- a controlled refusal, never a write.
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-006
	 */
	public function eraseSubject(string $employeeId, string $dsrRequestId): array {
		$dsrRequest = $this->requestStore->load($dsrRequestId);
		if ($dsrRequest === null) {
			return [
				'status' => 'refused',
				'message' => 'DsrRequest niet gevonden.',
			];
		}

		if ($this->hasRecordedPreview($dsrRequest) === false) {
			return [
				'status' => 'refused',
				'message' => 'Geen geregistreerd voorbeeld gevonden voor dit verzoek — voer eerst een voorbeeldverwijdering uit.',
			];
		}

		$subject = $this->resolveSubject($employeeId);
		$summary = $this->guardedService()->erase($subject, null, self::ERASE_MODE_PSEUDONYMISE, false);

		$erased = (array)($summary['erased'] ?? []);
		$retained = (array)($summary['held'] ?? []);
		$failed = (array)($summary['failed'] ?? []);

		$newStatus = $this->requestStore->recordEraseOutcome($dsrRequest, $erased, $retained, $failed);

		return [
			'status' => $newStatus,
			'erased' => $erased,
			'retained' => $retained,
			'failed' => $failed,
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
	private function hasRecordedPreview(array $dsrRequest): bool {
		$status = (string)($dsrRequest['status'] ?? '');
		return ($status === 'in_behandeling' && ($dsrRequest['retainedObjectRefs'] ?? null) !== null);
	}//end hasRecordedPreview()

	/**
	 * Direct rectification pass-through (design.md D6, Art 16) via the
	 * guarded service's own `rectify()`, which takes the object's id/uuid
	 * directly (hrmq#99 -- no int-id resolution workaround is needed
	 * anymore; the previous `DsarService::rectifyObjectForSubject(int
	 * $objectId, ...)` contract required one). Only an immutable archival
	 * status blocks a rectification (a correction does not remove data, so
	 * no legal-hold guard applies here either -- matching the guarded
	 * service's own `rectify()` semantics). Records only the changed field
	 * NAMES on `DsrRequest.outcomeSummary`, never before/after values.
	 *
	 * @param string $objectIdentifier The RBAC-resolved object id/uuid to update (the caller resolves and authorises it first).
	 * @param array<string, mixed> $changes Property -> new value map.
	 * @param string $dsrRequestId The DsrRequest id this rectification is recorded against.
	 *
	 * @return array<string, mixed>|null The updated object envelope, or null when `rectify()` could not load/update the object.
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-007
	 */
	public function rectifySubjectObject(string $objectIdentifier, array $changes, string $dsrRequestId): ?array {
		$dsrRequest = $this->requestStore->load($dsrRequestId);
		$result = $this->guardedService()->rectify($objectIdentifier, $changes);

		if ($dsrRequest !== null) {
			$this->requestStore->recordRectifyOutcome($dsrRequest, $result, $changes);
		}

		return $result;
	}//end rectifySubjectObject()

	/**
	 * @return mixed The OpenRegister ObjectService, resolved with the caller's ambient RBAC (default $_rbac=true).
	 */
	private function objectService(): mixed {
		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * @return mixed OpenRegister's guarded, RBAC/tenant-scoped
	 *               `Gdpr\DataSubjectRequestService` (hrmq#99) -- unlike the
	 *               previous `DsarService`, every public method runs under the
	 *               caller's ambient RBAC/tenant scope; no privileged-session
	 *               establishment is required.
	 */
	private function guardedService(): mixed {
		return $this->container->get(self::GUARDED_SERVICE_FQCN);
	}//end guardedService()

	/**
	 * Normalise an ObjectService row (entity or array) to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()

}//end class
