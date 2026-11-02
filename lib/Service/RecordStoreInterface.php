<?php

/**
 * Humaniq RecordStoreInterface
 *
 * Reads and writes humaniq register objects: find one, load a schema,
 * filter, save and delete.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Object reads and writes against the humaniq register.
 *
 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */
interface RecordStoreInterface {
	/**
	 * Load one object's payload by uuid, or null when unresolvable. The
	 * entity's uuid is echoed into `id`.
	 *
	 * @param string $uuid   The object uuid.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null The payload, or null.
	 *
	 * @spec openspec/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
	 */
	public function findObjectData(string $uuid, string $schema): ?array;

	/**
	 * Every row of a schema, as arrays.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	public function loadAll(string $schema): array;

	/**
	 * Rows of a schema matching top-level equality filters. With `$asCaller`
	 * only rows the signed-in caller may see come back.
	 *
	 * @param string               $schema   The schema slug.
	 * @param array<string, mixed> $filters  Top-level equality filters.
	 * @param bool                 $asCaller Whether the read is limited to what the caller may see.
	 *
	 * @return array<int, array<string, mixed>> The matching rows.
	 *
	 * @spec openspec/specs/time-entry-capture/spec.md#Requirement:-humaniq-captures-time-entries-under-a-submit→approve-lifecycle-(REQ-TEC-001)
	 */
	public function findFiltered(string $schema, array $filters, bool $asCaller = false): array;

	/**
	 * Create or update one object.
	 *
	 * @param array<string, mixed> $payload The object payload.
	 * @param string               $schema  The schema slug.
	 * @param string|null          $uuid    The uuid (null creates).
	 *
	 * @return object The saved ObjectEntity.
	 *
	 * @spec openspec/specs/time-entry-capture/spec.md#Requirement:-humaniq-captures-time-entries-under-a-submit→approve-lifecycle-(REQ-TEC-001)
	 */
	public function save(array $payload, string $schema, ?string $uuid = null): object;

	/**
	 * Delete one object.
	 *
	 * @param string $uuid   The object.
	 * @param string $schema The schema slug.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-001
	 */
	public function delete(string $uuid, string $schema): void;
}//end interface
