<?php

/**
 * Roster Cross Checks
 *
 * The two cross-checks a roster runs besides the working-time rules: whether
 * each assigned employee holds the competences a shift asks for, and whether
 * an assignment falls on approved leave.
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
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The competence and leave cross-checks of {@see RosterCheckService}.
 *
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-005
 */
final class RosterCrossChecks {

	/**
	 * @param CompetenceCheckService $competences The competence cross-check (design D3).
	 * @param LeaveConflictCheckService $leave The leave cross-check (design D10).
	 */
	public function __construct(
		public readonly CompetenceCheckService $competences = new CompetenceCheckService(),
		public readonly LeaveConflictCheckService $leave = new LeaveConflictCheckService(),
	) {

	}//end __construct()
}//end class
