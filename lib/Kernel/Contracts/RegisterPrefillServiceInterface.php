<?php

/**
 * Contract of RegisterPrefillService
 *
 * The public surface of RegisterPrefillService that other components call, so they depend
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
 * Contract of RegisterPrefillService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RegisterPrefillServiceInterface {
	/**
	 * Fill a vehicle asset from the RDW by its licence plate.
	 *
	 * @param array<string, mixed> $asset The stored asset.
	 *
	 * @return array{filled: array<string, mixed>, differs: list<array{field: string, stored: mixed, register: mixed}>}
	 *
	 * @throws \OCA\Humaniq\Service\RegisterPrefillUnavailableException When nothing can be looked up.
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
	 */
	public function vehicle(array $asset): array;

	/**
	 * Fill an employee from the BRP by BSN.
	 *
	 * @param array<string, mixed> $employee The stored employee.
	 *
	 * @return array{filled: array<string, mixed>, differs: list<array{field: string, stored: mixed, register: mixed}>}
	 *
	 * @throws \OCA\Humaniq\Service\RegisterPrefillUnavailableException When nothing can be looked up.
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-002
	 */
	public function employee(array $employee): array;
}//end interface
