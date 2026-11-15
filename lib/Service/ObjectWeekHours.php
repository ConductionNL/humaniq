<?php
/**
 * Humaniq ObjectWeekHours
 *
 * The hours booked on one object in one week, per person, as far as the
 * caller may see them.
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
 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use OCP\IUserManager;

/**
 * Sums the visible time entries on an object over one ISO week, per person.
 *
 * Only the entries OpenRegister hands the caller are counted
 * ({@see HoursRegisterGateway::findVisible()}), so a person whose bookings the
 * caller may not read does not appear. An entry outside the week, or one
 * without hours (a timer still running), adds nothing.
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
 */
class ObjectWeekHours {

	/**
	 * Unit names by id, read once per request.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $units = null;

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface  $gateway      The register reads.
	 * @param IUserManager          $users        Display names.
	 * @param OrgResolutionService  $orgResolution Whether a placement is active on a date.
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
		private readonly IUserManager $users,
		private readonly OrgResolutionService $orgResolution,
	) {
	}//end __construct()

	/**
	 * The week holding `$day`, Monday to Sunday, on one object.
	 *
	 * @param string            $domainObjectType The `<app>:<schema>` literal of the object.
	 * @param string            $domainObjectRef  The object's uuid.
	 * @param DateTimeImmutable $day              Any day of the week.
	 *
	 * @return array<string, mixed> `week`, `weekStart`, `weekEnd`, `total` and `people` (most hours first).
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
	 */
	public function forObject(string $domainObjectType, string $domainObjectRef, DateTimeImmutable $day): array {
		$monday = $day->setTime(0, 0)->modify('monday this week');
		$start  = $monday->format('Y-m-d');
		$end    = $monday->modify('+6 days')->format('Y-m-d');

		$entries = $this->gateway->findFiltered(
			'TimeEntry',
			['domainObjectType' => $domainObjectType, 'domainObjectRef' => $domainObjectRef],
			true
		);
		$tally   = self::tallied(entries: $entries, week: [$start, $end]);

		return [
			'week' => (int)$monday->format('W'),
			'weekStart' => $start,
			'weekEnd' => $end,
			'total' => round(array_sum($tally['hours']), 2),
			'people' => $this->people(tally: $tally, onDate: $end),
		];
	}//end forObject()

	/**
	 * The hours per person inside the week, most first, and each person's
	 * first non-empty employee link.
	 *
	 * @param array<int, array<string, mixed>> $entries The entries on the object.
	 * @param array{string, string}            $week    The first and last day, Y-m-d.
	 *
	 * @return array{hours: array<string, float>, employees: array<string, string>}
	 */
	private static function tallied(array $entries, array $week): array {
		[$start, $end] = $week;
		$hours         = [];
		$employees     = [];
		foreach ($entries as $entry) {
			$uid  = trim((string)($entry['userId'] ?? ''));
			$date = substr((string)($entry['date'] ?? ''), 0, 10);
			if ($uid === '' || $date < $start || $date > $end || is_numeric($entry['hours'] ?? null) === false) {
				continue;
			}

			$hours[$uid] = (($hours[$uid] ?? 0.0) + (float)$entry['hours']);
			if (($employees[$uid] ?? '') === '') {
				$employees[$uid] = trim((string)($entry['employeeId'] ?? ''));
			}
		}

		arsort($hours);

		return ['hours' => $hours, 'employees' => $employees];
	}//end tallied()

	/**
	 * One line per person, in the tally's order, with their name and team on a date.
	 *
	 * @param array{hours: array<string, float>, employees: array<string, string>} $tally  The week's tally.
	 * @param string                                                               $onDate The day the team is read on.
	 *
	 * @return array<int, array{userId: string, displayName: string, team: string, hours: float}>
	 */
	private function people(array $tally, string $onDate): array {
		$people = [];
		foreach ($tally['hours'] as $uid => $sum) {
			$uid      = (string)$uid;
			$people[] = [
				'userId' => $uid,
				'displayName' => ($this->users->getDisplayName($uid) ?? $uid),
				'team' => $this->teamOf(employeeId: ($tally['employees'][$uid] ?? ''), onDate: $onDate),
				'hours' => round($sum, 2),
			];
		}

		return $people;
	}//end people()

	/**
	 * The name of the organisation unit the employee is placed in on a date,
	 * or '' when there is no placement or more than one.
	 *
	 * @param string $employeeId The employee id.
	 * @param string $onDate     The reference date, `YYYY-MM-DD`.
	 *
	 * @return string The unit's name, or ''.
	 */
	private function teamOf(string $employeeId, string $onDate): string {
		if ($employeeId === '') {
			return '';
		}

		if ($this->units === null) {
			$this->units = [];
			foreach ($this->gateway->loadAll('OrgUnit') as $unit) {
				$this->units[trim((string)($unit['id'] ?? $unit['@self']['id'] ?? ''))] = trim((string)($unit['name'] ?? ''));
			}
		}

		$names = [];
		foreach ($this->gateway->findFiltered('OrgAssignment', ['employeeId' => $employeeId]) as $assignment) {
			$name = ($this->units[trim((string)($assignment['orgUnitId'] ?? ''))] ?? '');
			if ($name !== '' && $this->orgResolution->isActiveOn($assignment, $onDate) === true) {
				$names[] = $name;
			}
		}

		return ($this->orgResolution->uniqueOrNull(array_values(array_unique($names))) ?? '');
	}//end teamOf()
}//end class
