<?php

/**
 * NL Employee Document Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The payroll rules over an Employee's documents, moved out of
 * NlPayrollChecks: a verified ID-document copy and the
 * loonheffingenverklaring kept 5 years after the year employment ends, and a
 * posted worker's A1 certificate (numbered, dated, at most 24 months).
 *
 * Declared as OpenRegister decision tables via the ProvidesTables
 * capability (rules-onto-or-decision-tables, next conversion wave): the
 * field and date reading stays here as the derivations; the shared
 * Dmn\DecisionTableEvaluator decides. The pre-table closures, moved here
 * from NlPayrollChecks, stay as legacyChecks(), the oracle
 * NlPayrollTableParityTest compares with, never registered in the engine,
 * until staged retirement (REQ-RULE-009). The Employee seed sample stays in
 * NlPayrollChecks::seedObjects().
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * NL Employee Document rules, as decision tables.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */
final class NlEmployeeDocumentChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;
	use ReadsRecordedFields;

	/**
	 * {@inheritDoc}
	 *
	 * Empty: every rule this provider owns is table-declared (see tables()).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function checks(): array {
		return [];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * None: the Employee sample is seeded by NlPayrollChecks.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function seedSpec(): array {
		return [];

	}//end seedSpec()

	/**
	 * {@inheritDoc}
	 *
 * The document rules: a verified ID copy, and a loonheffingenverklaring on
 * file, kept 5 years after the year employment ends; a posted worker's A1
 * certificate numbered, dated and within 24 months of the start.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function tables(): array {
		$appliesButUnmet = self::satisfiedTable(
			[['name' => 'applies', 'type' => 'boolean'], ['name' => 'met', 'type' => 'boolean']],
			[['id' => 'applies-and-not-met', 'inputEntries' => ['true', 'false'], 'outputEntries' => [false]]]
		);

		return [
			'Employee' => [
				// Handboek Loonheffingen — a verified ID-document copy, kept 5 years after the year employment ends.
				'nl-id-bewaarplicht-5jaar' => [
					'derive' => static fn (array $employee): array => ['verified' => (($employee['identityDocumentVerified'] ?? false) === true), 'retained' => self::retainedFiveYears($employee, 'identityDocumentRetainedUntil')],
					'table' => self::satisfiedTable(
						[['name' => 'verified', 'type' => 'boolean'], ['name' => 'retained', 'type' => 'boolean']],
						[
							['id' => 'id-copy-not-verified', 'inputEntries' => ['false', '-'], 'outputEntries' => [false]],
							['id' => 'id-copy-not-retained', 'inputEntries' => ['-', 'false'], 'outputEntries' => [false]],
						]
					),
				],
				// Uitvoeringsregeling loonbelasting 2011 art. 12.1 lid 5 — a statement on file is kept 5 years after the year employment ends.
				'nl-loonbelastingverklaring-bewaarplicht-5jaar' => [
					'derive' => static fn (array $employee): array => ['applies' => (($employee['loonheffingenVerklaringOnFile'] ?? false) === true), 'met' => self::retainedFiveYears($employee, 'loonheffingenVerklaringRetainedUntil')],
					'table' => $appliesButUnmet,
				],
				// Regulation (EC) 883/2004 art. 12 — a posted worker's A1 runs at most 24 months.
				'eu-a1-posted-worker' => [
					'derive' => static fn (array $employee): array => [
						'applies' => (($employee['postedWorker'] ?? false) === true),
						'met' => (self::present($employee, 'a1CertificateNumber') && self::present($employee, 'a1ValidUntil') && self::withinMonths($employee, 'startDate', 'a1ValidUntil', 24)),
					],
					'table' => $appliesButUnmet,
				],
			],
		];

	}//end tables()

	/**
	 * Whether a dated retention field is present and lies at least 5 years
	 * after the year employment ends.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 * @param string               $key      The retained-until field.
	 *
	 * @return bool
	 */
	private static function retainedFiveYears(array $employee, string $key): bool {
		return self::present($employee, $key) && self::retainedAtLeastYearsAfterEnd($employee, $key, 5);

	}//end retainedFiveYears()

	/**
	 * Whether a verified ID-document copy is retained long enough.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 *
	 * @return bool
	 */
	private static function identityDocumentRetained(array $employee): bool {
		return (($employee['identityDocumentVerified'] ?? false) === true)
			&& self::present($employee, 'identityDocumentRetainedUntil')
			&& self::retainedAtLeastYearsAfterEnd($employee, 'identityDocumentRetainedUntil', 5);

	}//end identityDocumentRetained()

	/**
	 * Whether a loonheffingenverklaring on file is retained long enough.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 *
	 * @return bool
	 */
	private static function loonheffingenVerklaringRetained(array $employee): bool {
		return (($employee['loonheffingenVerklaringOnFile'] ?? false) !== true)
			|| (self::present($employee, 'loonheffingenVerklaringRetainedUntil')
			&& self::retainedAtLeastYearsAfterEnd($employee, 'loonheffingenVerklaringRetainedUntil', 5));

	}//end loonheffingenVerklaringRetained()

	/**
	 * Whether a posted worker holds an A1 certificate of at most 24 months.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 *
	 * @return bool
	 */
	private static function a1CertificateValid(array $employee): bool {
		return (($employee['postedWorker'] ?? false) !== true)
			|| (self::present($employee, 'a1CertificateNumber')
			&& self::present($employee, 'a1ValidUntil')
			&& self::withinMonths($employee, 'startDate', 'a1ValidUntil', 24));

	}//end a1CertificateValid()

	/**
	 * True when a retention date is at least $years after the end of the year in
	 * which employment ended (or, while still employed, simply a future-dated and
	 * present retention date).
	 *
	 * @param array<string, mixed> $employee The Employee.
	 * @param string $key Retention-date field.
	 * @param int $years Minimum number of years.
	 *
	 * @return bool
	 */
	private static function retainedAtLeastYearsAfterEnd(array $employee, string $key, int $years): bool {
		$retain = strtotime((string)($employee[$key] ?? ''));
		if ($retain === false) {
			return false;
		}

		$end = trim((string)($employee['endDate'] ?? ''));
		if ($end === '') {
			// Still employed: retention clock has not started; presence is enough.
			return true;
		}

		$endYear = (int)date('Y', (int)strtotime($end));
		$required = mktime(0, 0, 0, 12, 31, ($endYear + $years));
		return $retain >= $required;
	}//end retainedAtLeastYearsAfterEnd()

	/**
	 * True when the date at $endKey is no more than $months after the date at
	 * $startKey.
	 *
	 * @param array<string, mixed> $employee The object.
	 * @param string $startKey Start-date field.
	 * @param string $endKey End-date field.
	 * @param int $months Maximum number of months.
	 *
	 * @return bool
	 */
	private static function withinMonths(array $employee, string $startKey, string $endKey, int $months): bool {
		$start = strtotime((string)($employee[$startKey] ?? ''));
		$end = strtotime((string)($employee[$endKey] ?? ''));
		if ($start === false || $end === false || $end < $start) {
			return false;
		}

		$limit = strtotime('+' . $months . ' months', $start);
		return $end <= $limit;
	}//end withinMonths()

	/**
	 * The document closures as NlPayrollChecks registered them before the
	 * tables; NlPayrollTableParityTest holds the tables to them. Never registered.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 */
	public static function legacyChecks(): array {
		return [
			'Employee' => [
			'nl-id-bewaarplicht-5jaar' => self::identityDocumentRetained(...),
			'nl-loonbelastingverklaring-bewaarplicht-5jaar' => self::loonheffingenVerklaringRetained(...),
			'eu-a1-posted-worker' => self::a1CertificateValid(...),
			],
		];

	}//end legacyChecks()
}//end class
