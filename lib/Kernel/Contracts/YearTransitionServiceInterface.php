<?php

/**
 * Contract of YearTransitionService
 *
 * The public surface of YearTransitionService that other components call, so they depend
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
 * Contract of YearTransitionService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface YearTransitionServiceInterface {
	/**
	 * Which pack and tables January of a year resolves to.
	 *
	 * @param string $jurisdiction The ISO 3166-1 alpha-2 jurisdiction.
	 * @param int $year The tax year.
	 *
	 * @return array<string, mixed> `resolves`, and when it does: packId, packVersion, packOrigin, tablesId, tablesOrigin, selfTest {passed, message}, provenance; otherwise a message.
	 *
	 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-002
	 * @SuppressWarnings(PHPMD.StaticAccess) TaxTables is a pure value-object factory with static load/fromDocument/isBundled, the precedent PayrollRunService and NlPayrollChecks already use.
	 */
	public function resolution(string $jurisdiction, int $year): array;
}//end interface
