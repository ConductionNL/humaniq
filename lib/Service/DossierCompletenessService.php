<?php

/**
 * Humaniq DossierCompletenessService
 *
 * Answers, for one employee on one date, whether each mandatory document of
 * their personnel file is present, missing, expired, expiring or too old at
 * the start of employment (people-dossier-completeness D1, D3).
 *
 * A requirement names its evidence: a supplied PersonnelDocument with the
 * requirement's code, a generated document of a type (an arbeidsovereenkomst
 * humaniq generated), or a current EmployeeCompetence with a code (a BIG
 * registration). Computed on read from the rows the caller passes, because
 * the answer is a pure function of records and the date (ADR-031); the
 * controller decides which rows the caller may see.
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
 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Computes the completeness of one personnel file.
 *
 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-002
 */
class DossierCompletenessService {

	/**
	 * The schemas a status is composed from, besides the employee itself.
	 */
	public const SOURCES = [
		'DossierRequirement',
		'EmploymentContract',
		'OrgAssignment',
		'PersonnelDocument',
		'HrGeneratedDocument',
		'EmployeeCompetence',
	];

	public const PRESENT = 'aanwezig';

	/**
	 * Constructor.
	 *
	 * @param AbsenceProgression $progression The shared date parser.
	 */
	public function __construct(
		private readonly AbsenceProgression $progression,
	) {

	}//end __construct()

	/**
	 * One status row per active requirement that applies to the employee.
	 *
	 * @param string $employeeId The employee.
	 * @param string $date The day to judge on (Y-m-d).
	 * @param array<string, mixed> $rows 'employee' => the Employee payload, and one list per SOURCES schema.
	 *
	 * @return array<int, array<string, mixed>> requirementCode, label, status, evidence, issuedOn, validUntil.
	 *
	 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-002
	 */
	public function statusFor(string $employeeId, string $date, array $rows): array {
		$day = ($this->progression->date(value: $date) ?? new DateTimeImmutable('today'))->setTime(0, 0);
		$mine = [];
		foreach (self::SOURCES as $schema) {
			$mine[$schema] = array_values(
				array_filter(
					(array)($rows[$schema] ?? []),
					static fn (mixed $row): bool => is_array($row) === true && ($schema === 'DossierRequirement' || (string)($row['employeeId'] ?? '') === $employeeId)
				)
			);
		}

		$start = $this->startOf(employee: (array)($rows['employee'] ?? []), contracts: $mine['EmploymentContract']);
		$out = [];
		foreach ($mine['DossierRequirement'] as $requirement) {
			if (($requirement['active'] ?? true) === false || $this->applies(requirement: $requirement, rows: $mine, day: $day) === false) {
				continue;
			}

			$out[] = $this->judge(requirement: $requirement, evidence: $this->evidence(requirement: $requirement, rows: $mine), day: $day, start: $start);
		}

		return $out;
	}//end statusFor()

	/**
	 * Whether any row is not present.
	 *
	 * @param array<int, array<string, mixed>> $status The statusFor() answer.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-002
	 */
	public function hasGap(array $status): bool {
		foreach ($status as $row) {
			if (($row['status'] ?? '') !== self::PRESENT) {
				return true;
			}
		}

		return false;
	}//end hasGap()

	/**
	 * Whether a requirement applies to the employee on the day.
	 *
	 * @param array<string, mixed> $requirement The requirement.
	 * @param array<string, array<int, array<string, mixed>>> $rows The employee's rows.
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return bool
	 */
	private function applies(array $requirement, array $rows, DateTimeImmutable $day): bool {
		$scope = (string)($requirement['scope'] ?? 'all');
		$values = array_map('strval', (array)($requirement['scopeValues'] ?? []));

		return match ($scope) {
			'all' => true,
			'contractType' => $this->anyRunning(rows: $rows['EmploymentContract'], field: 'type', values: $values, day: $day),
			'normfunctie' => $this->anyRunning(rows: $rows['EmploymentContract'], field: 'normfunctieId', values: $values, day: $day),
			'orgUnit' => $this->anyRunning(rows: $rows['OrgAssignment'], field: 'orgUnitId', values: $values, day: $day),
			default => false,
		};
	}//end applies()

	/**
	 * Whether a row running on the day carries one of the values.
	 *
	 * @param array<int, array<string, mixed>> $rows Contracts or placements.
	 * @param string $field The field to match.
	 * @param array<int, string> $values The values.
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return bool
	 */
	private function anyRunning(array $rows, string $field, array $values, DateTimeImmutable $day): bool {
		foreach ($rows as $row) {
			$start = $this->progression->date(value: ($row['startDate'] ?? null));
			$end = $this->progression->date(value: ($row['endDate'] ?? null));
			$running = ($start === null || $start <= $day) && ($end === null || $end >= $day);
			if ($running === true && in_array((string)($row[$field] ?? ''), $values, true) === true) {
				return true;
			}
		}

		return false;
	}//end anyRunning()

	/**
	 * The newest evidence for a requirement, normalised.
	 *
	 * @param array<string, mixed> $requirement The requirement.
	 * @param array<string, array<int, array<string, mixed>>> $rows The employee's rows.
	 *
	 * @return array{schema: string, id: string, issuedOn: string|null, validUntil: string|null}|null
	 */
	private function evidence(array $requirement, array $rows): ?array {
		$kind = (string)($requirement['evidenceKind'] ?? 'personnel-document');
		$candidates = [];
		foreach ($this->candidates(kind: $kind, evidence: $requirement, code: (string)($requirement['code'] ?? ''), rows: $rows) as [$schema, $row, $issued]) {
			$candidates[] = [
				'schema' => $schema,
				'id' => (string)($row['id'] ?? ($row['uuid'] ?? '')),
				'issuedOn' => $this->day(value: $issued),
				'validUntil' => $this->day(value: ($row['validUntil'] ?? null)),
			];
		}

		if ($candidates === []) {
			return null;
		}

		// Newest first: the latest expiry, then the latest issue; an open expiry is the newest.
		usort(
			$candidates,
			static fn (array $a, array $b): int => [($b['validUntil'] ?? '9999-12-31'), (string)$b['issuedOn']] <=> [($a['validUntil'] ?? '9999-12-31'), (string)$a['issuedOn']]
		);

		return $candidates[0];
	}//end evidence()

	/**
	 * The rows that could be evidence for one requirement, with their issue date.
	 *
	 * @param string $kind personnel-document, generated-document or competence.
	 * @param array<string, mixed> $evidence The requirement, whose documentType or competenceCode names the evidence.
	 * @param string $code The requirement code.
	 * @param array<string, array<int, array<string, mixed>>> $rows The employee's rows.
	 *
	 * @return array<int, array{0: string, 1: array<string, mixed>, 2: mixed}>
	 */
	private function candidates(string $kind, array $evidence, string $code, array $rows): array {
		$out = [];
		if ($kind === 'generated-document') {
			foreach ($rows['HrGeneratedDocument'] as $row) {
				if ((string)($row['documentType'] ?? '') === (string)($evidence['documentType'] ?? '') && ($row['status'] ?? '') === 'generated') {
					$out[] = ['HrGeneratedDocument', $row, ($row['generatedAt'] ?? null)];
				}
			}

			return $out;
		}

		if ($kind === 'competence') {
			foreach ($rows['EmployeeCompetence'] as $row) {
				if ((string)($row['competenceCode'] ?? '') === (string)($evidence['competenceCode'] ?? '')) {
					$out[] = ['EmployeeCompetence', $row, ($row['issuedOn'] ?? null)];
				}
			}

			return $out;
		}

		foreach ($rows['PersonnelDocument'] as $row) {
			if ((string)($row['requirementCode'] ?? '') === $code) {
				$out[] = ['PersonnelDocument', $row, ($row['issuedOn'] ?? null)];
			}
		}

		return $out;
	}//end candidates()

	/**
	 * The status row for one requirement.
	 *
	 * @param array<string, mixed> $requirement The requirement.
	 * @param array{schema: string, id: string, issuedOn: string|null, validUntil: string|null}|null $evidence The newest evidence.
	 * @param DateTimeImmutable $day The day.
	 * @param DateTimeImmutable|null $start The employment start.
	 *
	 * @return array<string, mixed>
	 */
	private function judge(array $requirement, ?array $evidence, DateTimeImmutable $day, ?DateTimeImmutable $start): array {
		$row = [
			'requirementCode' => (string)($requirement['code'] ?? ''),
			'label' => (string)($requirement['label'] ?? ($requirement['code'] ?? '')),
			'status' => self::PRESENT,
			'evidence' => null,
			'issuedOn' => null,
			'validUntil' => null,
		];
		if ($evidence === null) {
			$row['status'] = 'ontbreekt';
			return $row;
		}

		$row['evidence'] = ['schema' => $evidence['schema'], 'id' => $evidence['id']];
		$row['issuedOn'] = $evidence['issuedOn'];
		$row['validUntil'] = $evidence['validUntil'];
		$row['status'] = $this->statusOf(requirement: $requirement, evidence: $evidence, day: $day, start: $start);

		return $row;
	}//end judge()

	/**
	 * Verlopen, te-oud-bij-start, verloopt-binnenkort or aanwezig, in that order.
	 *
	 * @param array<string, mixed> $requirement The requirement.
	 * @param array{schema: string, id: string, issuedOn: string|null, validUntil: string|null} $evidence The evidence.
	 * @param DateTimeImmutable $day The day.
	 * @param DateTimeImmutable|null $start The employment start.
	 *
	 * @return string
	 */
	private function statusOf(array $requirement, array $evidence, DateTimeImmutable $day, ?DateTimeImmutable $start): string {
		$validUntil = $this->progression->date(value: $evidence['validUntil']);
		if ($validUntil !== null && $validUntil < $day) {
			return 'verlopen';
		}

		$maxAge = $requirement['maxAgeDaysAtStart'] ?? null;
		$issued = $this->progression->date(value: $evidence['issuedOn']);
		if (is_numeric($maxAge) === true && $start !== null && $issued !== null && $issued < $start->modify('-' . (int)$maxAge . ' days')) {
			return 'te-oud-bij-start';
		}

		$warn = $requirement['warnDaysBefore'] ?? null;
		if ($validUntil !== null && is_numeric($warn) === true && $validUntil <= $day->modify('+' . (int)$warn . ' days')) {
			return 'verloopt-binnenkort';
		}

		return self::PRESENT;
	}//end statusOf()

	/**
	 * The employment start: the employee's startDate, else the earliest contract start.
	 *
	 * @param array<string, mixed> $employee The employee.
	 * @param array<int, array<string, mixed>> $contracts The employee's contracts.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function startOf(array $employee, array $contracts): ?DateTimeImmutable {
		$start = $this->progression->date(value: ($employee['startDate'] ?? null));
		if ($start !== null) {
			return $start;
		}

		foreach ($contracts as $contract) {
			$candidate = $this->progression->date(value: ($contract['startDate'] ?? null));
			if ($candidate !== null && ($start === null || $candidate < $start)) {
				$start = $candidate;
			}
		}

		return $start;
	}//end startOf()

	/**
	 * A Y-m-d string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function day(mixed $value): ?string {
		return $this->progression->date(value: $value)?->format('Y-m-d');
	}//end day()

}//end class
