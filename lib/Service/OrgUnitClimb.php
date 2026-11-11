<?php

/**
 * Humaniq OrgUnitClimb
 *
 * The units from a placement unit up to the root of the unit tree, as
 * {@see OrgResolutionService} climbs them to find a manager.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The climb from a placement unit up the unit tree.
 *
 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */
final class OrgUnitClimb {

	/**
	 * The units from the placement unit up to the root, stopping before a
	 * unit that cannot be read and before a unit already passed (a loop).
	 *
	 * @param string                              $unitId    The placement unit.
	 * @param array<string, array<string, mixed>> $unitsById The units by id.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	public function unitsFrom(string $unitId, array $unitsById): array {
		$units = [];
		while ($unitId !== '' && isset($units[$unitId]) === false) {
			$unit = ($unitsById[$unitId] ?? null);
			if (is_array($unit) === false) {
				break;
			}

			$units[$unitId] = $unit;
			$unitId         = trim((string)($unit['parentUnitId'] ?? ''));
		}

		return array_values($units);
	}//end unitsFrom()

}//end class
