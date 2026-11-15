<?php

/**
 * Formation Absence Sources
 *
 * The leave and sickness records a formation count reads, and the rules for which of them count as away.
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
 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Leave and sickness records plus the rules that make them count as away.
 *
 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-003
 */
final class FormationAbsenceSources {

	/**
	 * @param list<array<string, mixed>> $leaveRequests     LeaveRequest records.
	 * @param list<array<string, mixed>> $sickCases         SickLeaveCase records.
	 * @param array<int, string>         $netLeaveTypes     Leave type codes that count as away.
	 * @param int                        $longTermSickWeeks Weeks after which sickness counts.
	 */
	public function __construct(
		public readonly array $leaveRequests,
		public readonly array $sickCases,
		public readonly array $netLeaveTypes,
		public readonly int $longTermSickWeeks,
	) {

	}//end __construct()

}//end class
