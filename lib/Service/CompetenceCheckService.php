<?php

/**
 * Competence Check Service
 *
 * Whether the person on a shift is qualified to work it, on the date they are
 * planned for.
 *
 * WHY THE DATE IS THE WHOLE POINT
 * -------------------------------
 * A BOA pas runs out. A heftruckcertificaat runs out. The roster has to refuse
 * the day after the qualification expires, not the day somebody notices it did,
 * and not the day the record is finally edited. So a competence is held on a
 * DATE: `issuedOn` on or before it, and `validUntil` absent or on or after it.
 * Nobody has to touch the record for it to stop counting.
 *
 * WHY THE FINDING HAS ITS OWN KIND
 * --------------------------------
 * "Jan is not a BOA" and "Jan has eleven hours of rest, not twelve" need
 * different actions from different people. Reported as one undifferentiated
 * list, the planner reads the first and re-plans for the second, or the other
 * way round. A finding therefore carries `kind`, and this service produces only
 * `competence` (design D3).
 *
 * Deliberately dependency-free: the caller supplies the already-fetched
 * assignments, shifts and competences, which is what makes every branch
 * reachable from a unit test without a Nextcloud bootstrap.
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
 * @spec openspec/specs/rostering/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Cross-checks rostered assignments against the competences their shifts need.
 *
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C02
 */
class CompetenceCheckService {

	/**
	 * The finding kind this service produces, and no other.
	 *
	 * @var string
	 */
	public const FINDING_KIND = 'competence';

	/**
	 * The kind the working-time rules produce, named here so both halves of a
	 * report read from one place.
	 *
	 * @var string
	 */
	public const WORKING_TIME_KIND = 'working-time';

	/**
	 * Findings for a set of assignments.
	 *
	 * @param array<array<string, mixed>> $assignments RosterAssignment rows.
	 * @param array<string, array<string, mixed>> $shiftsById Shift rows keyed by id.
	 * @param array<array<string, mixed>> $competences EmployeeCompetence rows, any employee.
	 *
	 * @return array<int, array<string, mixed>> One finding per missing competence, per assignment.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C02
	 */
	public function findings(array $assignments, array $shiftsById, array $competences): array {
		$findings = [];
		foreach ($assignments as $assignment) {
			$shift = ($shiftsById[trim((string)($assignment['shiftId'] ?? ''))] ?? null);
			if (is_array($shift) === false) {
				continue;
			}

			$required = $this->requiredCompetences($shift);
			if ($required === []) {
				continue;
			}

			$employeeId = trim((string)($assignment['employeeId'] ?? ''));
			$date = substr(trim((string)($assignment['date'] ?? '')), 0, 10);
			if ($employeeId === '' || $date === '') {
				continue;
			}

			foreach ($required as $code) {
				if ($this->holds(competences: $competences, employeeId: $employeeId, code: $code, date: $date) === true) {
					continue;
				}

				$findings[] = [
					'kind' => self::FINDING_KIND,
					'objectType' => 'RosterAssignment',
					'objectId' => trim((string)($assignment['id'] ?? ($assignment['@self']['id'] ?? ''))),
					'employeeId' => $employeeId,
					'date' => $date,
					'competence' => $code,
					'severity' => 'mandatory',
					'statement' => 'Medewerker ' . $employeeId . ' heeft op ' . $date
						. ' geen geldige bevoegdheid ' . $code . ' voor deze dienst.',
				];
			}
		}

		return $findings;
	}//end findings()

	/**
	 * Whether one employee holds one competence on one date.
	 *
	 * @param array<array<string, mixed>> $competences EmployeeCompetence rows.
	 * @param string $employeeId The employee.
	 * @param string $code The competence code.
	 * @param string $date The ISO date it must be held on.
	 *
	 * @return bool True when held.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C01
	 */
	public function holds(array $competences, string $employeeId, string $code, string $date): bool {
		foreach ($competences as $competence) {
			if (trim((string)($competence['employeeId'] ?? '')) !== $employeeId) {
				continue;
			}

			if (trim((string)($competence['competenceCode'] ?? '')) !== $code) {
				continue;
			}

			$issuedOn = substr(trim((string)($competence['issuedOn'] ?? '')), 0, 10);
			if ($issuedOn !== '' && $issuedOn > $date) {
				continue;
			}

			$validUntil = substr(trim((string)($competence['validUntil'] ?? '')), 0, 10);
			if ($validUntil !== '' && $validUntil < $date) {
				// Expired on its own date. Nobody edits a record for this to
				// happen, which is the point.
				continue;
			}

			return true;
		}

		return false;
	}//end holds()

	/**
	 * The competence codes one employee holds on one date.
	 *
	 * @param array<array<string, mixed>> $competences EmployeeCompetence rows.
	 * @param string $employeeId The employee.
	 * @param string $date The ISO date.
	 *
	 * @return array<int, string> The codes.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C01
	 */
	public function heldOn(array $competences, string $employeeId, string $date): array {
		$held = [];
		foreach ($competences as $competence) {
			$code = trim((string)($competence['competenceCode'] ?? ''));
			if ($code === '' || isset($held[$code]) === true) {
				continue;
			}

			if ($this->holds(competences: $competences, employeeId: $employeeId, code: $code, date: $date) === true) {
				$held[$code] = true;
			}
		}

		return array_keys($held);
	}//end heldOn()

	/**
	 * The competence codes one shift needs.
	 *
	 * @param array<string, mixed> $shift The Shift row.
	 *
	 * @return array<int, string> The codes.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C01
	 */
	public function requiredCompetences(array $shift): array {
		$raw = ($shift['requiredCompetences'] ?? []);
		if (is_array($raw) === false) {
			return [];
		}

		$codes = [];
		foreach ($raw as $value) {
			if (is_string($value) === false || trim($value) === '') {
				continue;
			}

			$codes[trim($value)] = true;
		}

		return array_keys($codes);
	}//end requiredCompetences()
}//end class
