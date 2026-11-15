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
 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Computes the completeness of one personnel file.
 *
 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-002
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
	 * Per kind of evidence: its schema, the field matched, the requirement field it must equal, and its issue date.
	 */
	private const EVIDENCE = [
		'personnel-document' => ['PersonnelDocument', 'requirementCode', 'code', 'issuedOn'],
		'generated-document' => ['HrGeneratedDocument', 'documentType', 'documentType', 'generatedAt'],
		'competence' => ['EmployeeCompetence', 'competenceCode', 'competenceCode', 'issuedOn'],
	];

	/**
	 * Per scope a requirement may have besides `all`: the schema of the rows
	 * it reads and the field that must carry one of its scope values.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const SCOPED_BY = [
		'contractType' => ['EmploymentContract', 'type'],
		'normfunctie' => ['EmploymentContract', 'normfunctieId'],
		'orgUnit' => ['OrgAssignment', 'orgUnitId'],
	];

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
	 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-002
	 */
	public function statusFor(string $employeeId, string $date, array $rows): array {
		$check = new DossierCheck();
		$check->day = ($this->progression->date(value: $date) ?? new DateTimeImmutable('today'))->setTime(0, 0);
		$check->rows = $this->employeeRows(employeeId: $employeeId, rows: $rows);
		$check->start = $this->startOf(employee: (array)($rows['employee'] ?? []), contracts: $check->rows['EmploymentContract']);

		return $this->statusRows(check: $check);
	}//end statusFor()

	/**
	 * The rows of one employee per source schema; every requirement.
	 *
	 * @param string               $employeeId The employee.
	 * @param array<string, mixed> $rows       One list per SOURCES schema.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function employeeRows(string $employeeId, array $rows): array {
		$mine = [];
		foreach (self::SOURCES as $schema) {
			$mine[$schema] = array_values(
				array_filter(
					(array)($rows[$schema] ?? []),
					static fn (mixed $row): bool => is_array($row) === true && ($schema === 'DossierRequirement' || (string)($row['employeeId'] ?? '') === $employeeId)
				)
			);
		}

		return $mine;
	}//end employeeRows()

	/**
	 * One status row per active requirement that applies.
	 *
	 * @param DossierCheck $check The dossier being judged.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function statusRows(DossierCheck $check): array {
		$out = [];
		foreach ($check->rows['DossierRequirement'] as $requirement) {
			if (($requirement['active'] ?? true) === false || $this->applies(requirement: $requirement, check: $check) === false) {
				continue;
			}

			$out[] = $this->judge(requirement: $requirement, check: $check);
		}

		return $out;
	}//end statusRows()

	/**
	 * Whether any row is not present.
	 *
	 * @param array<int, array<string, mixed>> $status The statusFor() answer.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-002
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
	 * @param DossierCheck         $check       The dossier: the employee's rows and the day.
	 *
	 * @return bool
	 */
	private function applies(array $requirement, DossierCheck $check): bool {
		$scope = (string)($requirement['scope'] ?? 'all');
		if ($scope === 'all') {
			return true;
		}

		return (array_key_exists($scope, self::SCOPED_BY) === true && $this->anyRunning(requirement: $requirement, check: $check) === true);
	}//end applies()

	/**
	 * Whether a contract or placement running on the day carries one of the
	 * requirement's scope values.
	 *
	 * @param array<string, mixed> $requirement The requirement, its scope one of SCOPED_BY.
	 * @param DossierCheck         $check       The dossier: the employee's rows and the day.
	 *
	 * @return bool
	 */
	private function anyRunning(array $requirement, DossierCheck $check): bool {
		[$schema, $field] = self::SCOPED_BY[(string)$requirement['scope']];
		$values = array_map('strval', (array)($requirement['scopeValues'] ?? []));
		foreach ($check->rows[$schema] as $row) {
			if ($this->runsOn(row: $row, day: $check->day) === true && in_array((string)($row[$field] ?? ''), $values, true) === true) {
				return true;
			}
		}

		return false;
	}//end anyRunning()

	/**
	 * Whether a contract or placement runs on the day.
	 *
	 * @param array<string, mixed> $row The contract or placement.
	 * @param DateTimeImmutable    $day The day.
	 *
	 * @return bool
	 */
	private function runsOn(array $row, DateTimeImmutable $day): bool {
		$start = $this->progression->date(value: ($row['startDate'] ?? null));
		$end = $this->progression->date(value: ($row['endDate'] ?? null));

		return ($start === null || $start <= $day) && ($end === null || $end >= $day);
	}//end runsOn()

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
		foreach ($this->candidates(kind: $kind, requirement: $requirement, rows: $rows) as [$schema, $row, $issued]) {
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
	 * @param array<string, mixed> $requirement The requirement, whose code, documentType or competenceCode names the evidence.
	 * @param array<string, array<int, array<string, mixed>>> $rows The employee's rows.
	 *
	 * @return array<int, array{0: string, 1: array<string, mixed>, 2: mixed}>
	 */
	private function candidates(string $kind, array $requirement, array $rows): array {
		[$schema, $rowField, $requirementField, $issuedField] = (self::EVIDENCE[$kind] ?? self::EVIDENCE['personnel-document']);
		$wanted = (string)($requirement[$requirementField] ?? '');
		$out = [];
		foreach ($rows[$schema] as $row) {
			$generatedOnly = ($schema === 'HrGeneratedDocument' && ($row['status'] ?? '') !== 'generated');
			if ($generatedOnly === false && (string)($row[$rowField] ?? '') === $wanted) {
				$out[] = [$schema, $row, ($row[$issuedField] ?? null)];
			}
		}

		return $out;
	}//end candidates()

	/**
	 * The status row for one requirement.
	 *
	 * @param array<string, mixed> $requirement The requirement.
	 * @param DossierCheck         $check       The dossier; the requirement and its newest evidence are set on it.
	 *
	 * @return array<string, mixed>
	 */
	private function judge(array $requirement, DossierCheck $check): array {
		$check->requirement = $requirement;
		$check->evidence = $this->evidence(requirement: $requirement, rows: $check->rows);
		$row = $this->blankRow(requirement: $requirement);
		if ($check->evidence === null) {
			$row['status'] = 'ontbreekt';
			return $row;
		}

		$row['evidence'] = ['schema' => $check->evidence['schema'], 'id' => $check->evidence['id']];
		$row['issuedOn'] = $check->evidence['issuedOn'];
		$row['validUntil'] = $check->evidence['validUntil'];
		$row['status'] = $this->statusOf(check: $check);

		return $row;
	}//end judge()

	/**
	 * A requirement's status row before its evidence is read.
	 *
	 * @param array<string, mixed> $requirement The requirement.
	 *
	 * @return array<string, mixed>
	 */
	private function blankRow(array $requirement): array {
		return [
			'requirementCode' => (string)($requirement['code'] ?? ''),
			'label' => (string)($requirement['label'] ?? ($requirement['code'] ?? '')),
			'status' => self::PRESENT,
			'evidence' => null,
			'issuedOn' => null,
			'validUntil' => null,
		];
	}//end blankRow()

	/**
	 * Verlopen, te-oud-bij-start, verloopt-binnenkort or aanwezig, in that order.
	 *
	 * @param DossierCheck $check The dossier: the requirement, its evidence, the day and the employment start.
	 *
	 * @return string
	 */
	private function statusOf(DossierCheck $check): string {
		$validUntil = $this->progression->date(value: $check->evidence['validUntil']);
		if ($validUntil !== null && $validUntil < $check->day) {
			return 'verlopen';
		}

		if ($this->tooOldAtStart(check: $check) === true) {
			return 'te-oud-bij-start';
		}

		return ($this->expiresSoon(check: $check, validUntil: $validUntil) === true ? 'verloopt-binnenkort' : self::PRESENT);
	}//end statusOf()

	/**
	 * Whether the evidence expires within the requirement's warning days.
	 *
	 * @param DossierCheck           $check      The dossier: the requirement and the day.
	 * @param DateTimeImmutable|null $validUntil The evidence's expiry.
	 *
	 * @return bool
	 */
	private function expiresSoon(DossierCheck $check, ?DateTimeImmutable $validUntil): bool {
		$warn = $check->requirement['warnDaysBefore'] ?? null;

		return ($validUntil !== null && is_numeric($warn) === true && $validUntil <= $check->day->modify('+' . (int)$warn . ' days'));
	}//end expiresSoon()

	/**
	 * Whether the evidence was issued longer before the start than the requirement allows.
	 *
	 * @param DossierCheck $check The dossier: the requirement, its evidence and the employment start.
	 *
	 * @return bool
	 */
	private function tooOldAtStart(DossierCheck $check): bool {
		$maxAge = $check->requirement['maxAgeDaysAtStart'] ?? null;
		$issued = $this->progression->date(value: $check->evidence['issuedOn']);
		if (is_numeric($maxAge) === false || $check->start === null || $issued === null) {
			return false;
		}

		return $issued < $check->start->modify('-' . (int)$maxAge . ' days');
	}//end tooOldAtStart()

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
