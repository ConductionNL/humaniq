<?php

/**
 * Interview Sync Run
 *
 * The calendar target, lookup indexes and bound of one interview calendar
 * sync, and the outcome rows it collects.
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
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One interview calendar sync: what it writes to, what it looks names up
 * in, how far it reaches, and what it reports.
 *
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
 */
final class InterviewSyncRun {

	/**
	 * The outcome rows, in the order the interviews were synced.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $results = [];

	/**
	 * @param InterviewCalendarTarget              $target           The resolved sync target.
	 * @param array<string, array<string, string>> $applicationsById Application index (candidateName/vacancyId).
	 * @param array<string, string>                $vacanciesById    Vacancy id -> title index.
	 * @param string|null                          $from             Optional bound on scheduledStart (Y-m-d).
	 */
	public function __construct(
		public readonly InterviewCalendarTarget $target,
		public readonly array $applicationsById,
		public readonly array $vacanciesById,
		public readonly ?string $from = null,
	) {

	}//end __construct()

}//end class
