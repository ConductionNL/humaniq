<?php

/**
 * Contract of DossierCompletenessService
 *
 * The public surface of DossierCompletenessService that other components call, so they depend
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
 * Contract of DossierCompletenessService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DossierCompletenessServiceInterface {
	public const PRESENT = 'aanwezig';

	/**
	 * The schemas a status is composed from, besides the employee itself.
	 */
	public const SOURCES = [
		'DossierRequirement',
		'EmploymentContract',
		'OrgAssignment',
		'PersonnelDocument',
		'HrGeneratedDocument',
		'EmployeeCompetence',
	];

	/**
	 * One status row per active requirement that applies to the employee.
	 *
	 * @param string $employeeId The employee.
	 * @param string $date The day to judge on (Y-m-d).
	 * @param array<string, mixed> $rows 'employee' => the Employee payload, and one list per SOURCES schema.
	 *
	 * @return array<int, array<string, mixed>> requirementCode, label, status, evidence, issuedOn, validUntil.
	 *
	 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-002
	 */
	public function statusFor(string $employeeId, string $date, array $rows): array;

	/**
	 * Whether any row is not present.
	 *
	 * @param array<int, array<string, mixed>> $status The statusFor() answer.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-002
	 */
	public function hasGap(array $status): bool;
}//end interface
