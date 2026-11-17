<?php

/**
 * Contract of ManagerAccessStamps
 *
 * The public surface of ManagerAccessStamps that other components call, so they depend
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
 * Contract of ManagerAccessStamps.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ManagerAccessStampsInterface {
	/**
	 * The stamps one record should carry today. The record's `id` names an
	 * Employee record's own employee.
	 *
	 * @param array<string, mixed> $record The record as it would be saved.
	 * @param string $schema The schema name, one of SCHEMAS.
	 *
	 * @return array{managerUserId: string|null, deputyUserIds: list<string>}
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function stampsFor(array $record, string $schema): array;

	/**
	 * The schema name of a stamped schema id, or null for any other schema.
	 *
	 * @param string $schemaId The schema id an object carries.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function schemaFor(string $schemaId): ?string;

	/**
	 * Run the pass for today and log a pass that could not run.
	 *
	 * @param string $today The day, YYYY-MM-DD.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function refresh(string $today): void;

	/**
	 * Restamp every record whose stamps differ, or only count them on a dry run.
	 *
	 * @param bool $dryRun True to count without writing.
	 * @param string $today The day, YYYY-MM-DD.
	 *
	 * @return array<string, array{inspected: int, changed: int, failed: int}> Counts per schema.
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function run(bool $dryRun, string $today): array;
}//end interface
