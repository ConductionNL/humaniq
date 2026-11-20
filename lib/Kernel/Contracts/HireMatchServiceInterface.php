<?php

/**
 * Contract of HireMatchService
 *
 * The public surface of HireMatchService that other components call, so they depend
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
 * Contract of HireMatchService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface HireMatchServiceInterface {
	/**
	 * The employees, active or former, this person may already be.
	 *
	 * @param array<string, mixed> $person The hire: bsn, lastName, dateOfBirth, privateEmail (each optional).
	 *
	 * @return list<array{employeeId: string, name: string, matchedOn: string, hasLeft: bool, endDate: ?string, startDate: ?string}>
	 *
	 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
	 */
	public function matches(array $person): array;
}//end interface
