<?php

/**
 * Contract of AssetDialectMigrationService
 *
 * The public surface of AssetDialectMigrationService that other components call, so they depend
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
 * Contract of AssetDialectMigrationService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AssetDialectMigrationServiceInterface {
	/**
	 * Rewrite every existing `Asset`/`AssetAssignment` object from the old
	 * dialect to the new one. Idempotent; safe to call repeatedly.
	 *
	 * @return array<string, array<string, mixed>> Per-schema report: schema => {
	 *                                             inspected: int, rewritten: int, alreadyCurrent: int, skipped: int,
	 *                                             skipReasons: array<int, array{id: string, reason: string}>
	 *                                             }. `rewritten` and `skipped` are NOT mutually exclusive for a row: a
	 *                                             row can have its category/fields rewritten AND have its status
	 *                                             skipped-with-reason on the same run (Asset.status, see class docblock).
	 *
	 * @spec openspec/specs/asset-management/spec.md#REQ-AST-008
	 */
	public function migrate(): array;
}//end interface
