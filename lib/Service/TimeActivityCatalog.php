<?php

/**
 * Humaniq TimeActivityCatalog
 *
 * Answers what kind of work an activity is: project, non-project or
 * absence. The kinds come from the administered `TimeActivity` objects, so an
 * employer adds its own kinds by writing an object. {@see EntryKind} applies
 * them to a time entry.
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

use OCA\Humaniq\Listener\HoursWriteRefusedException;

/**
 * Reads the administered time activities and their kinds.
 *
 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-non-project-work-shall-be-bookable-as-an-activity-req-tac-001
 */
class TimeActivityCatalog {

	/**
	 * Kind of an activity for project work.
	 *
	 * @var string
	 */
	public const KIND_PROJECT = 'project';

	/**
	 * Kind of an activity for work outside a project.
	 *
	 * @var string
	 */
	public const KIND_NON_PROJECT = 'non-project';

	/**
	 * Kind of an activity that stands for leave or sickness.
	 *
	 * @var string
	 */
	public const KIND_ABSENCE = 'absence';

	/**
	 * Every kind an activity may have.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = [self::KIND_PROJECT, self::KIND_NON_PROJECT, self::KIND_ABSENCE];

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface $store The register reads.
	 */
	public function __construct(
		private readonly RecordStoreInterface $store,
	) {

	}//end __construct()

	/**
	 * The kind of every known activity, by code.
	 *
	 * An activity with an unknown kind is left out, so it classifies like an
	 * entry without an activity rather than as something it does not say.
	 *
	 * @return array<string, string> Kind by activity code.
	 *
	 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-non-project-work-shall-be-bookable-as-an-activity-req-tac-001
	 */
	public function kindsByCode(): array {
		$kinds = [];
		foreach ($this->store->loadAll('TimeActivity') as $activity) {
			$code = trim((string)($activity['code'] ?? ''));
			$kind = trim((string)($activity['kind'] ?? ''));
			if ($code === '' || in_array($kind, self::KINDS, true) === false) {
				continue;
			}

			$kinds[$code] = $kind;
		}

		return $kinds;
	}//end kindsByCode()

	/**
	 * The kind of one activity, or null when no activity has that code.
	 *
	 * @param string $code The activity code.
	 *
	 * @return string|null The kind, or null.
	 *
	 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-non-project-work-shall-be-bookable-as-an-activity-req-tac-001
	 */
	public function kindOf(string $code): ?string {
		$code = trim($code);
		if ($code === '') {
			return null;
		}

		$kind = trim((string)($this->store->findFiltered('TimeActivity', ['code' => $code])[0]['kind'] ?? ''));

		return in_array($kind, self::KINDS, true) === true ? $kind : null;
	}//end kindOf()

	/**
	 * Refuse a booking on an absence activity.
	 *
	 * Leave and sickness reach the timesheet from their own records; a booked
	 * copy of the same hours would count them twice and drift from the record
	 * it copies.
	 *
	 * @param array<string, mixed> $incoming The incoming TimeEntry payload.
	 *
	 * @return void
	 *
	 * @throws HoursWriteRefusedException When the activity is of kind absence.
	 *
	 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-non-project-work-shall-be-bookable-as-an-activity-req-tac-001
	 */
	public function assertBookable(array $incoming): void {
		if ($this->kindOf((string)($incoming['activityCode'] ?? '')) === self::KIND_ABSENCE) {
			throw new HoursWriteRefusedException(
				'Verlof en ziekte boek je niet als uren: ze komen uit je verlofaanvraag of ziekmelding en staan al op je urenstaat.'
			);
		}
	}//end assertBookable()

}//end class
