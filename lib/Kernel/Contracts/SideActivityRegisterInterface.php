<?php

/**
 * Contract of SideActivityRegister
 *
 * The public surface of SideActivityRegister that other components call, so they depend
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
 * Contract of SideActivityRegister.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SideActivityRegisterInterface {
	public const ACTIVITY_SLUG = 'sideactivity';

	public const EMPLOYEE_SLUG = 'employee';

	public const FLAG = 'nevenwerkzaamhedenGemeld';

	/**
	 * The lower-case slug of a schema id.
	 *
	 * @param string $schemaId The entity's schema id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
	 */
	public function slugOf(string $schemaId): string;

	/**
	 * The fields to stamp on a new report, or the reason it is refused.
	 *
	 * @param array<string, mixed> $report What was entered.
	 * @param string $userId The caller, '' for a system write.
	 * @param string $today Today (Y-m-d).
	 *
	 * @return array{stamps: array<string, mixed>, error: string|null}
	 *
	 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
	 */
	public function place(array $report, string $userId, string $today): array;

	/**
	 * Bring the employee's attestation in step with the register.
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
	 */
	public function attest(string $employeeId): void;

	/**
	 * The register's value when an update changes the attestation by hand, else null.
	 *
	 * @param array<string, mixed> $new The employee as it will be saved.
	 * @param array<string, mixed> $old The employee as it was.
	 * @param string $employeeId The employee.
	 *
	 * @return bool|null
	 *
	 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
	 */
	public function correction(array $new, array $old, string $employeeId): ?bool;
}//end interface
