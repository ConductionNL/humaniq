<?php

/**
 * Contract of HireService
 *
 * The public surface of HireService that other components call, so they depend
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
 * Contract of HireService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface HireServiceInterface {
	/**
	 * The proposed first and last name, split on the first space so a Dutch
	 * prefix stays with the last name. HR corrects it in the dialog.
	 *
	 * @param string $candidateName The name on the application.
	 *
	 * @return array{firstName: string, lastName: string}
	 *
	 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
	 */
	public function proposedName(string $candidateName): array;

	/**
	 * Hire: create or attach, start the case, link the application.
	 *
	 * @param string               $applicationId The application.
	 * @param array<string, mixed> $input         startDate, firstName, lastName, bsn, dateOfBirth, attachToEmployeeId, createNew.
	 *
	 * @return array<string, mixed> outcome created, attached, already-linked, matches, not-found, not-hired or invalid.
	 *
	 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
	 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
	 */
	public function hire(string $applicationId, array $input): array;
}//end interface
