<?php

/**
 * Contract of DepartmentFiguresService
 *
 * The public surface of DepartmentFiguresService that other components call, so they depend
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
 * Contract of DepartmentFiguresService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DepartmentFiguresServiceInterface {
	/**
	 * The units under `$parentUnitId` (or the top units) side by side, with
	 * the wage cost of people placed in none of them and the total.
	 *
	 * @param string      $administrationId The authorised administration.
	 * @param string      $period           `quarter`, `half-year`, `year` or one month `YYYY-MM`.
	 * @param string|null $parentUnitId     Compare this unit's children; when null, the top units, or the units under the top when there is one.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException When the period is not recognised.
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	public function compare(string $administrationId, string $period, ?string $parentUnitId): array;

	/**
	 * The figures of every unit the user manages, under the small-unit rule.
	 *
	 * @param string $userId           The Nextcloud user id.
	 * @param string $administrationId The caller's active administration.
	 * @param string $period           The window.
	 *
	 * @return array{period: string, units: list<array<string, mixed>>}
	 *
	 * @throws \InvalidArgumentException When the period is not recognised.
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
	 */
	public function managedUnits(string $userId, string $administrationId, string $period): array;

	/**
	 * One unit's figures, or null when the unit is not in the administration.
	 *
	 * @param string $administrationId The authorised administration.
	 * @param string $unitId           The unit.
	 * @param string $period           The window.
	 * @param int    $minimumMembers   The small-unit threshold (0 for none).
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws \InvalidArgumentException When the period is not recognised.
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
	 */
	public function unitFigures(string $administrationId, string $unitId, string $period, int $minimumMembers): ?array;
}//end interface
