<?php

/**
 * Contract of RightToWorkRecorder
 *
 * The public surface of RightToWorkRecorder that other components call, so they depend
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
 * Contract of RightToWorkRecorder.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RightToWorkRecorderInterface {
	/**
	 * The RightToWorkCheck schema slug, lower case.
	 */
	public const CHECK_SLUG = 'righttoworkcheck';

	/**
	 * Whether a schema id is the RightToWorkCheck schema.
	 *
	 * @param string $schemaId The entity's schema id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-003
	 */
	public function isCheckSchema(string $schemaId): bool;

	/**
	 * Whether this caller may record a check: HR, an administrator, or a system write with no user.
	 *
	 * @param string $userId The caller, '' for a system write.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-003
	 */
	public function mayRecord(string $userId): bool;

	/**
	 * The fields to stamp on a check before it is saved.
	 *
	 * @param array<string, mixed> $check What HR entered.
	 * @param string $userId The caller.
	 * @param string $today Today (Y-m-d).
	 *
	 * @return array<string, mixed> The fields to set.
	 *
	 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-003
	 */
	public function stamp(array $check, string $userId, string $today): array;

	/**
	 * After a passing check is saved: tick the WID check and file a residence document.
	 *
	 * Idempotent: a case already ticked and a document already filed are left alone.
	 *
	 * @param array<string, mixed> $check The saved check.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-003
	 */
	public function recordPass(array $check): void;
}//end interface
