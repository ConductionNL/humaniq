<?php

/**
 * People Feed Subject
 *
 * One employee of a people feed with their current placement and its unit.
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
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One employee, their current placement and the placement's unit.
 *
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-002
 */
final class PeopleFeedSubject {

	/**
	 * @param string                    $employeeId The employee.
	 * @param array<string, mixed>      $employee   The employee record.
	 * @param array<string, mixed>|null $placement  The current placement.
	 * @param array<string, mixed>|null $unit       The placement's unit.
	 */
	public function __construct(
		public readonly string $employeeId,
		public readonly array $employee,
		public readonly ?array $placement,
		public readonly ?array $unit,
	) {

	}//end __construct()

	/**
	 * The employee, placement and unit, for the latest-change lookup.
	 *
	 * @return array<int, array<string, mixed>|null>
	 *
	 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-002
	 */
	public function records(): array {
		return [$this->employee, $this->placement, $this->unit];
	}//end records()

}//end class
