<?php

/**
 * Humaniq EntryKind
 *
 * The kind one time entry counts as: its activity's kind, or, for an entry
 * written before activities existed, project work when it names a project and
 * non-project work (overhead) when it names nothing.
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
 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-non-project-work-shall-be-bookable-as-an-activity-req-tac-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Classifies time entries by the kinds of the known activities.
 *
 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-non-project-work-shall-be-bookable-as-an-activity-req-tac-001
 */
final class EntryKind {

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $kinds Kind by activity code, as {@see TimeActivityCatalog::kindsByCode()} answers it.
	 */
	public function __construct(
		private readonly array $kinds,
	) {

	}//end __construct()

	/**
	 * The kind one entry counts as.
	 *
	 * @param array<string, mixed> $entry The TimeEntry payload.
	 *
	 * @return string One of {@see TimeActivityCatalog::KINDS}.
	 *
	 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-non-project-work-shall-be-bookable-as-an-activity-req-tac-001
	 */
	public function kindOf(array $entry): string {
		$code = trim((string)($entry['activityCode'] ?? ''));
		if ($code !== '' && isset($this->kinds[$code]) === true) {
			return $this->kinds[$code];
		}

		if (trim((string)($entry['projectId'] ?? '')) !== '') {
			return TimeActivityCatalog::KIND_PROJECT;
		}

		return TimeActivityCatalog::KIND_NON_PROJECT;
	}//end kindOf()

}//end class
