<?php

/**
 * The daily team message (self-service-announcements-and-digest D3, D4).
 *
 * Composes, for one org unit on one day, who is away and whose birthday it
 * is. Leave shows the last day off, sickness shows no date, and neither shows
 * a type or a reason, so the message cannot tell leave from sickness. A
 * birthday is named only for an employee who agreed to share it, and never
 * with a year or an age.
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
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\IL10N;
use RuntimeException;

/**
 * Builds the away-and-birthday message of a team.
 *
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-003
 */
class TeamDigestComposer {

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway    Register reads, unscoped.
	 * @param UnitMembership       $membership Unit trees.
	 * @param OrgResolutionService $orgResolution Whether an assignment is active on a day.
	 * @param IL10N                $l10n       Translations.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly UnitMembership $membership,
		private readonly OrgResolutionService $orgResolution,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Who in a unit is away today and whose birthday it is, and the message.
	 *
	 * @param string $orgUnitId       The unit.
	 * @param bool   $includeChildren Whether the teams under it count.
	 * @param string $today           The day, `YYYY-MM-DD`.
	 *
	 * @return array{unit: string, away: array<int, array{name: string, until: ?string}>, birthdays: array<int, string>, message: string}
	 *
	 * @throws RuntimeException When the unit does not exist.
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-003
	 */
	public function compose(string $orgUnitId, bool $includeChildren, string $today): array {
		$units = $this->gateway->loadAll('OrgUnit');
		$unit = null;
		foreach ($units as $candidate) {
			if ($this->membership->rowId($candidate) === $orgUnitId) {
				$unit = $candidate;
			}
		}

		if ($unit === null) {
			throw new RuntimeException('Org unit ' . $orgUnitId . ' does not exist.');
		}

		$unitIds = ($includeChildren === true) ? $this->membership->subtree($orgUnitId, $units) : [$orgUnitId];
		$members = $this->members(unitIds: $unitIds, today: $today);

		$away = [];
		$birthdays = [];
		foreach ($members as $employeeId => $employee) {
			$absence = $this->absence(employeeId: (string)$employeeId, today: $today);
			if ($absence !== false) {
				$away[] = ['name' => $this->nameOf($employee), 'until' => $absence];
			}

			$born = trim((string)($employee['dateOfBirth'] ?? ''));
			if (($employee['shareBirthday'] ?? false) === true && strlen($born) >= 10 && substr($born, 5, 5) === substr($today, 5, 5)) {
				$birthdays[] = $this->nameOf($employee);
			}
		}

		$name = (string)($unit['name'] ?? '');

		return ['unit' => $name, 'away' => $away, 'birthdays' => $birthdays, 'message' => $this->message(unit: $name, away: $away, birthdays: $birthdays)];
	}//end compose()

	/**
	 * The employees with an assignment active today in one of the units,
	 * who have not left, by id, sorted by name.
	 *
	 * @param array<int, string> $unitIds The units.
	 * @param string             $today   The day.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function members(array $unitIds, string $today): array {
		$ids = [];
		foreach ($this->gateway->loadAll('OrgAssignment') as $assignment) {
			if (in_array((string)($assignment['orgUnitId'] ?? ''), $unitIds, true) === true && $this->orgResolution->isActiveOn($assignment, $today) === true) {
				$ids[(string)($assignment['employeeId'] ?? '')] = true;
			}
		}

		$members = [];
		foreach ($this->gateway->loadAll('Employee') as $employee) {
			$id = $this->membership->rowId($employee);
			$ended = trim((string)($employee['endDate'] ?? ''));
			if (isset($ids[$id]) === true && ($ended === '' || $ended >= $today)) {
				$members[$id] = $employee;
			}
		}

		uasort($members, fn (array $a, array $b): int => strcmp($this->nameOf($a), $this->nameOf($b)));

		return $members;
	}//end members()

	/**
	 * Whether an employee is away today: the last day of approved leave that
	 * covers today, null for open sickness, false when they are in.
	 *
	 * @param string $employeeId The employee.
	 * @param string $today      The day.
	 *
	 * @return string|null|false
	 */
	private function absence(string $employeeId, string $today): string|null|false {
		foreach ($this->gateway->findFiltered('LeaveRequest', ['employeeId' => $employeeId, 'status' => 'approved']) as $leave) {
			$start = (string)($leave['startDate'] ?? '');
			$end = (string)($leave['endDate'] ?? '');
			if ($start !== '' && $start <= $today && ($end === '' || $end >= $today)) {
				return ($end !== '' ? substr($end, 0, 10) : null);
			}
		}

		foreach ($this->gateway->findFiltered('SickLeaveCase', ['employeeId' => $employeeId, 'status' => 'gemeld']) as $case) {
			$first = (string)($case['firstSickDay'] ?? '');
			if ($first !== '' && $first <= $today) {
				return null;
			}
		}

		return false;
	}//end absence()

	/**
	 * The message text, or '' when there is nothing to say.
	 *
	 * @param string                                             $unit      The unit name.
	 * @param array<int, array{name: string, until: ?string}> $away      Who is away.
	 * @param array<int, string>                                 $birthdays Whose birthday it is.
	 *
	 * @return string
	 */
	private function message(string $unit, array $away, array $birthdays): string {
		if ($away === [] && $birthdays === []) {
			return '';
		}

		$lines = [$this->l10n->t('Today in %s', [$unit])];
		foreach ($away as $person) {
			$lines[] = ($person['until'] === null)
				? $this->l10n->t('Away: %s', [$person['name']])
				: $this->l10n->t('Away: %1$s, back after %2$s', [$person['name'], $this->day($person['until'])]);
		}

		foreach ($birthdays as $name) {
			$lines[] = $this->l10n->t('Birthday: %s', [$name]);
		}

		return implode("\n", $lines);
	}//end message()

	/**
	 * A date as day-month-year without leading zeros.
	 *
	 * @param string $date The date, `YYYY-MM-DD`.
	 *
	 * @return string
	 */
	private function day(string $date): string {
		return (int)substr($date, 8, 2) . '-' . (int)substr($date, 5, 2) . '-' . substr($date, 0, 4);
	}//end day()

	/**
	 * The display name of an employee.
	 *
	 * @param array<string, mixed> $employee The employee.
	 *
	 * @return string
	 */
	private function nameOf(array $employee): string {
		return trim((string)($employee['firstName'] ?? '') . ' ' . (string)($employee['lastName'] ?? ''));
	}//end nameOf()

}//end class
