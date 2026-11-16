<?php

/**
 * Contract of AvgDsrService
 *
 * The public surface of AvgDsrService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
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
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Kernel\Contracts;

/**
 * Contract of AvgDsrService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AvgDsrServiceInterface {
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
	public function exportForSubject(string $employeeId, string $right, ?string $dsrRequestId = null): array;

	/**
	 * Zero-write erasure preview (design.md D5, hrmq#99): calls the guarded
	 * service's own `erase(..., dryRun: true)` -- the SAME retention guard
	 * (`RetentionService::hasActiveLegalHold()` + `validateNotImmutable()`)
	 * `eraseSubject()` would hit, never a bespoke humaniq classification. When
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
	public function previewErasure(string $employeeId, ?string $dsrRequestId = null): array;

	/**
	 * Execute the retention-guarded erase (design.md D5, hrmq#99) for a
	 * `DsrRequest` whose preview already ran (`status: in_behandeling` and a
	 * recorded `retainedObjectRefs` -- the preview marker `previewErasure()`
	 * writes). Calls the guarded service's `erase()` directly (dryRun:
	 * false) -- it refuses a legal-hold or immutable-archival-status object
	 * itself; humaniq no longer excludes objects via its own classification
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
	public function eraseSubject(string $employeeId, string $dsrRequestId): array;

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
	public function rectifySubjectObject(string $objectIdentifier, array $changes, string $dsrRequestId): ?array;
}//end interface
