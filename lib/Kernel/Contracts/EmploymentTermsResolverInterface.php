<?php

/**
 * Contract of EmploymentTermsResolver
 *
 * The public surface of EmploymentTermsResolver that other components call, so they depend
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
 * Contract of EmploymentTermsResolver.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmploymentTermsResolverInterface {
	/**
	 * The CAO components a contract names, resolved (payroll-cao-components
	 * D2); see CaoComponentTerms.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract as an array.
	 *
	 * @return array{components: list<array<string, mixed>>, unresolved: list<string>, unknown: list<string>}
	 *
	 * @throws \InvalidArgumentException When an override has no reason or is below the agreement.
	 *
	 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-002
	 */
	public function resolveComponents(array $contract): array;
}//end interface
