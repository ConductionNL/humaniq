<?php

/**
 * Contract of FormationOccupancyService
 *
 * The public surface of FormationOccupancyService that other components call, so they depend
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

use OCA\Humaniq\Service\AbsencePeriod;
use OCA\Humaniq\Service\FormationAbsenceSources;

/**
 * Contract of FormationOccupancyService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FormationOccupancyServiceInterface {
	/**
	 * Occupancy per place and for the unit, averaged over the working days
	 * of the period.
	 *
	 * @param list<array<string, mixed>> $places    The unit's Formatieplaats records.
	 * @param list<array<string, mixed>> $contracts EmploymentContract records.
	 * @param FormationAbsenceSources    $absences  Leave and sickness records and the rules that make them count.
	 * @param AbsencePeriod              $period    First and last day, inclusive, and the hours of one FTE.
	 *
	 * @return array{places: list<array<string, mixed>>, total: array<string, mixed>}
	 *
	 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-002
	 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-003
	 */
	public function occupancy(
		array $places,
		array $contracts,
		FormationAbsenceSources $absences,
		AbsencePeriod $period,
	): array;
}//end interface
