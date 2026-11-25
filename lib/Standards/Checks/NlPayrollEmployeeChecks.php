<?php

/**
 * NL Payroll Employee Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The seven payroll rules over Employee, moved out of NlPayrollChecks so
 * neither class grows past the size limits: a verified ID-document copy and
 * the loonheffingenverklaring kept 5 years after the year employment ends,
 * the 30%-ruling's rate, WNT cap, term and salary norm, and a posted
 * worker's A1 certificate. The Employee seed sample stays in
 * NlPayrollChecks::seedObjects().
 *
 * Declared as OpenRegister decision tables via the ProvidesTables
 * capability (rules-onto-or-decision-tables, next conversion wave): the
 * field and date reading stays here as the derivations; the shared
 * Dmn\DecisionTableEvaluator decides. The pre-table closures stay as
 * legacyChecks(), the oracle NlPayrollTableParityTest compares with, never
 * registered in the engine, until staged retirement (REQ-RULE-009).
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
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * The payroll rules over Employee, as decision tables.
 *
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */
final class NlPayrollEmployeeChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;
	use ReadsRecordedFields;

	/**
	 * The 30%-ruling maximum term in months (Belastingdienst beschikking; the
	 * `dertigProcentRegeling.maxDurationMonths` corpus leaf). An Employee check
	 * has no wage period to load a versioned table for, so the flat statutory
	 * value is a class constant here -- the `nl-30-percent-regeling` <= 30 and
	 * `nl-minimumloon-2026` >= 14,71 hardcoding precedent.
	 *
	 * @var int
	 */
	private const THIRTY_PERCENT_MAX_DURATION_MONTHS = 60;

	/**
	 * The 2026 general 30%-ruling annual salary norm in cents
	 * (`dertigProcentRegeling.salarisnormAlgemeen`, €48.013). Same
	 * Employee-check hardcoding precedent as the term above.
	 *
	 * @var int
	 */
	private const THIRTY_PERCENT_SALARISNORM_ALGEMEEN_CENTS = 4801300;

	/**
	 * The 2026 reduced 30%-ruling annual salary norm in cents
	 * (`dertigProcentRegeling.salarisnormMasterOnder30`, €36.497) for employees
	 * under 30 with a qualifying master's degree.
	 *
	 * @var int
	 */
	private const THIRTY_PERCENT_SALARISNORM_MASTER_ONDER30_CENTS = 3649700;

	/**
	 * {@inheritDoc}
	 *
	 * Empty: every rule this provider owns is table-declared (see tables()).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
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
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function seedSpec(): array {
		return [];

	}//end seedSpec()

	/**
	 * {@inheritDoc}
	 *
	 * The Employee rules: a verified ID copy, and a loonheffingenverklaring on
	 * file, kept 5 years after the year employment ends; a granted 30%-ruling
	 * at a numeric rate of at most 30, capped at the WNT-norm, with a readable
	 * end inside 60 months of its start and not past, at or above its salary
	 * norm; a posted worker's A1 certificate numbered, dated and within 24
	 * months of the start.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function tables(): array {
		$failsUnlessBoth = self::satisfiedTable(
			[['name' => 'applies', 'type' => 'boolean'], ['name' => 'met', 'type' => 'boolean']],
			[['id' => 'applies-and-not-met', 'inputEntries' => ['true', 'false'], 'outputEntries' => [false]]]
		);

		return [
			'Employee' => [
				// Handboek Loonheffingen — a verified ID-document copy, kept 5 years after the year employment ends.
				'nl-id-bewaarplicht-5jaar' => [
					'derive' => static fn (array $o): array => ['verified' => (($o['identityDocumentVerified'] ?? false) === true), 'retained' => self::retainedFiveYears($o, 'identityDocumentRetainedUntil')],
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
					'derive' => static fn (array $o): array => ['applies' => (($o['loonheffingenVerklaringOnFile'] ?? false) === true), 'met' => self::retainedFiveYears($o, 'loonheffingenVerklaringRetainedUntil')],
					'table' => $failsUnlessBoth,
				],
				// Wet LB 1964 art. 31a (30%-regeling) — at most 30% tax-free for 2025-2026.
				'nl-30-percent-regeling' => [
					'derive' => static fn (array $o): array => ['granted' => self::thirtyPercentGranted($o), 'rateRecorded' => self::numeric($o, 'thirtyPercentRulingRate'), 'rate' => (float)($o['thirtyPercentRulingRate'] ?? 0)],
					'table' => self::satisfiedTable(
						[['name' => 'granted', 'type' => 'boolean'], ['name' => 'rateRecorded', 'type' => 'boolean'], ['name' => 'rate', 'type' => 'number']],
						[
							['id' => 'ruling-rate-missing', 'inputEntries' => ['true', 'false', '-'], 'outputEntries' => [false]],
							['id' => 'ruling-rate-above-cap', 'inputEntries' => ['true', 'true', '> 30'], 'outputEntries' => [false]],
						]
					),
				],
				// Wet LB 1964 art. 31a (aftopping WNT-norm) — a granted ruling is capped at the WNT-norm.
				'nl-30-regeling-aftoppingsgrens' => [
					'derive' => static fn (array $o): array => ['applies' => self::thirtyPercentGranted($o), 'met' => (($o['thirtyPercentCappedAtWntNorm'] ?? false) === true)],
					'table' => $failsUnlessBoth,
				],
				// Wet LB 1964 art. 31a (looptijd) — a granted ruling runs at most 60 months, and not past its end.
				'nl-30-regeling-looptijd-5jaar' => [
					'derive' => static fn (array $o): ?array => self::thirtyPercentTermInputs($o),
					'table' => self::satisfiedTable(
						[['name' => 'endReadable', 'type' => 'boolean'], ['name' => 'beyondMaximumTerm', 'type' => 'boolean'], ['name' => 'ended', 'type' => 'boolean']],
						[
							['id' => 'ruling-end-missing', 'inputEntries' => ['false', '-', '-'], 'outputEntries' => [false]],
							['id' => 'ruling-beyond-60-months', 'inputEntries' => ['true', 'true', '-'], 'outputEntries' => [false]],
							['id' => 'ruling-past-its-end', 'inputEntries' => ['true', 'false', 'true'], 'outputEntries' => [false]],
						]
					),
				],
				// Wet LB 1964 art. 31a (salarisnorm) — the annualised salary reaches the applicable norm.
				'nl-30-regeling-salarisnorm' => [
					'derive' => static fn (array $o): ?array => self::thirtyPercentSalaryInputs($o),
					'table' => self::satisfiedTable(
						[['name' => 'shortfallCents', 'type' => 'number']],
						[['id' => 'salary-below-ruling-norm', 'inputEntries' => ['> 0'], 'outputEntries' => [false]]]
					),
				],
				// Regulation (EC) 883/2004 art. 12 — a posted worker's A1 runs at most 24 months.
				'eu-a1-posted-worker' => [
					'derive' => static fn (array $o): array => [
						'applies' => (($o['postedWorker'] ?? false) === true),
						'met' => (self::present($o, 'a1CertificateNumber') && self::present($o, 'a1ValidUntil') && self::withinMonths($o, 'startDate', 'a1ValidUntil', 24)),
					],
					'table' => $failsUnlessBoth,
				],
			],
		];

	}//end tables()

	/**
	 * Whether a dated retention field is present and lies at least 5 years
	 * after the year employment ends.
	 *
	 * @param array<string, mixed> $o   The Employee.
	 * @param string               $key The retained-until field.
	 *
	 * @return bool
	 */
	private static function retainedFiveYears(array $o, string $key): bool {
		return self::present($o, $key) && self::retainedAtLeastYearsAfterEnd($o, $key, 5);

	}//end retainedFiveYears()

	/**
	 * Whether a 30%-ruling is granted.
	 *
	 * @param array<string, mixed> $o The Employee.
	 *
	 * @return bool
	 */
	private static function thirtyPercentGranted(array $o): bool {
		return ($o['thirtyPercentRulingGranted'] ?? false) === true;

	}//end thirtyPercentGranted()

	/**
	 * The ruling-term table's inputs: whether the end date reads, whether it
	 * lies beyond 60 months from the start, and whether it has passed. Null
	 * without a granted ruling or a readable start.
	 *
	 * @param array<string, mixed> $o The Employee.
	 *
	 * @return array{endReadable: bool, beyondMaximumTerm: bool, ended: bool}|null
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function thirtyPercentTermInputs(array $o): ?array {
		$start = strtotime((string)($o['thirtyPercentRulingStartDate'] ?? ''));
		if (self::thirtyPercentGranted($o) === false || $start === false) {
			return null;
		}

		$end = strtotime((string)($o['thirtyPercentRulingEndDate'] ?? ''));
		$maxEnd = strtotime('+' . self::THIRTY_PERCENT_MAX_DURATION_MONTHS . ' months', $start);

		return [
			'endReadable' => ($end !== false),
			'beyondMaximumTerm' => ($end !== false && $maxEnd !== false && $end > $maxEnd),
			'ended' => ($end !== false && $end < time()),
		];

	}//end thirtyPercentTermInputs()

	/**
	 * The salary-norm table's input: how far the annualised salary falls short
	 * of the ruling's norm (the reduced under-30-master norm when it applies),
	 * in cents. Null without a granted ruling or a numeric salary.
	 *
	 * @param array<string, mixed> $o The Employee.
	 *
	 * @return array{shortfallCents: int}|null
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function thirtyPercentSalaryInputs(array $o): ?array {
		if (self::thirtyPercentGranted($o) === false || self::numeric($o, 'grossMonthlySalary') === false) {
			return null;
		}

		$normCents = ((($o['thirtyPercentRulingReducedNormApplies'] ?? false) === true) ? self::THIRTY_PERCENT_SALARISNORM_MASTER_ONDER30_CENTS : self::THIRTY_PERCENT_SALARISNORM_ALGEMEEN_CENTS);

		return ['shortfallCents' => ($normCents - (int)round(((float)$o['grossMonthlySalary']) * 12 * 100))];

	}//end thirtyPercentSalaryInputs()

	/**
	 * Whether a verified ID-document copy is retained long enough.
	 *
	 * @param array<string, mixed> $o The Employee.
	 *
	 * @return bool
	 */
	private static function identityDocumentRetained(array $o): bool {
		return (($o['identityDocumentVerified'] ?? false) === true)
			&& self::present($o, 'identityDocumentRetainedUntil')
			&& self::retainedAtLeastYearsAfterEnd($o, 'identityDocumentRetainedUntil', 5);

	}//end identityDocumentRetained()

	/**
	 * Whether a loonheffingenverklaring on file is retained long enough.
	 *
	 * @param array<string, mixed> $o The Employee.
	 *
	 * @return bool
	 */
	private static function loonheffingenVerklaringRetained(array $o): bool {
		return (($o['loonheffingenVerklaringOnFile'] ?? false) !== true)
			|| (self::present($o, 'loonheffingenVerklaringRetainedUntil')
			&& self::retainedAtLeastYearsAfterEnd($o, 'loonheffingenVerklaringRetainedUntil', 5));

	}//end loonheffingenVerklaringRetained()

	/**
	 * Whether a granted 30%-ruling applies at most 30 percent.
	 *
	 * @param array<string, mixed> $o The Employee.
	 *
	 * @return bool
	 */
	private static function thirtyPercentRateWithinCap(array $o): bool {
		return (($o['thirtyPercentRulingGranted'] ?? false) !== true)
			|| (self::numeric($o, 'thirtyPercentRulingRate') && ((float)$o['thirtyPercentRulingRate']) <= 30.0);

	}//end thirtyPercentRateWithinCap()

	/**
	 * Whether a granted 30%-ruling is capped at the WNT-norm.
	 *
	 * @param array<string, mixed> $o The Employee.
	 *
	 * @return bool
	 */
	private static function thirtyPercentCappedAtWntNorm(array $o): bool {
		return (($o['thirtyPercentRulingGranted'] ?? false) !== true)
			|| (($o['thirtyPercentCappedAtWntNorm'] ?? false) === true);

	}//end thirtyPercentCappedAtWntNorm()

	/**
	 * The `nl-30-regeling-looptijd-5jaar` predicate (30-procent-regeling
	 * REQ-30P-004): vacuous when the ruling is not granted, or when the ruling
	 * has no `thirtyPercentRulingStartDate` (an incomplete-but-not-contradictory
	 * record — a MISSING start date is a data-quality signal deferred to a
	 * follow-up check, design.md D5, so it is not flagged here). Otherwise flags
	 * when the end date is absent, more than the 60-month maximum term after the
	 * start date, or already in the past while the ruling is still granted.
	 *
	 * @param array<string, mixed> $o The Employee object.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/30-procent-regeling/spec.md#REQ-30P-004
	 */
	private static function thirtyPercentTermSatisfied(array $o): bool {
		if (($o['thirtyPercentRulingGranted'] ?? false) !== true) {
			return true;
		}

		$start = strtotime((string)($o['thirtyPercentRulingStartDate'] ?? ''));
		if ($start === false) {
			// Incomplete-but-not-contradictory ruling (no start date) — vacuous,
			// deferred to a follow-up data-quality check (design.md D5).
			return true;
		}

		$end = strtotime((string)($o['thirtyPercentRulingEndDate'] ?? ''));
		if ($end === false) {
			// Granted with a start but no end date — flag.
			return false;
		}

		$maxEnd = strtotime('+' . self::THIRTY_PERCENT_MAX_DURATION_MONTHS . ' months', $start);
		if ($maxEnd !== false && $end > $maxEnd) {
			// End date runs beyond the 60-month statutory term — flag.
			return false;
		}

		// A stale ruling still marked granted after its end date has passed — flag.
		return $end >= time();
	}//end thirtyPercentTermSatisfied()

	/**
	 * The `nl-30-regeling-salarisnorm` predicate (30-procent-regeling
	 * REQ-30P-004): vacuous when the ruling is not granted or
	 * `grossMonthlySalary` is non-numeric. Otherwise flags when the annualised
	 * salary (`grossMonthlySalary × 12`) is below the applicable norm — the
	 * reduced under-30-master norm when
	 * `thirtyPercentRulingReducedNormApplies` is true, else the general norm.
	 *
	 * @param array<string, mixed> $o The Employee object.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/30-procent-regeling/spec.md#REQ-30P-004
	 */
	private static function thirtyPercentSalaryNormSatisfied(array $o): bool {
		if (($o['thirtyPercentRulingGranted'] ?? false) !== true) {
			return true;
		}

		if (self::numeric($o, 'grossMonthlySalary') === false) {
			return true;
		}

		$annualCents = (int)round(((float)$o['grossMonthlySalary']) * 12 * 100);
		$normCents = ((($o['thirtyPercentRulingReducedNormApplies'] ?? false) === true)
			? self::THIRTY_PERCENT_SALARISNORM_MASTER_ONDER30_CENTS
			: self::THIRTY_PERCENT_SALARISNORM_ALGEMEEN_CENTS);

		return $annualCents >= $normCents;
	}//end thirtyPercentSalaryNormSatisfied()

	/**
	 * Whether a posted worker holds an A1 certificate of at most 24 months.
	 *
	 * @param array<string, mixed> $o The Employee.
	 *
	 * @return bool
	 */
	private static function a1CertificateValid(array $o): bool {
		return (($o['postedWorker'] ?? false) !== true)
			|| (self::present($o, 'a1CertificateNumber')
			&& self::present($o, 'a1ValidUntil')
			&& self::withinMonths($o, 'startDate', 'a1ValidUntil', 24));

	}//end a1CertificateValid()

	/**
	 * True when a retention date is at least $years after the end of the year in
	 * which employment ended (or, while still employed, simply a future-dated and
	 * present retention date).
	 *
	 * @param array<string, mixed> $o The Employee.
	 * @param string $key Retention-date field.
	 * @param int $years Minimum number of years.
	 *
	 * @return bool
	 */
	private static function retainedAtLeastYearsAfterEnd(array $o, string $key, int $years): bool {
		$retain = strtotime((string)($o[$key] ?? ''));
		if ($retain === false) {
			return false;
		}

		$end = trim((string)($o['endDate'] ?? ''));
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
	 * @param array<string, mixed> $o The object.
	 * @param string $startKey Start-date field.
	 * @param string $endKey End-date field.
	 * @param int $months Maximum number of months.
	 *
	 * @return bool
	 */
	private static function withinMonths(array $o, string $startKey, string $endKey, int $months): bool {
		$start = strtotime((string)($o[$startKey] ?? ''));
		$end = strtotime((string)($o[$endKey] ?? ''));
		if ($start === false || $end === false || $end < $start) {
			return false;
		}

		$limit = strtotime('+' . $months . ' months', $start);
		return $end <= $limit;
	}//end withinMonths()

	/**
	 * Employee checks: document retention, the 30%-ruling and the A1 certificate.
	 *
	 * @return array<string, callable>
	 */
	private static function employeeChecks(): array {
		return [
			// Handboek Loonheffingen — a verified ID-document copy is kept until at least
			// 5 years after the year employment ends.
			'nl-id-bewaarplicht-5jaar' => self::identityDocumentRetained(...),
			// Uitvoeringsregeling loonbelasting 2011 art. 12.1 lid 5 - the
			// loonheffingenverklaring is kept until at least 5 years after the year
			// employment ends, the same clock as the ID copy above.
			//
			// Gated on the statement actually being on file. The retention duty
			// attaches to a document that exists, so an employee with no statement
			// is VACUOUS here rather than in violation; whether one must be on file
			// at all is a different obligation and no corpus rule covers it yet.
			'nl-loonbelastingverklaring-bewaarplicht-5jaar' => self::loonheffingenVerklaringRetained(...),
			// Wet LB 1964 art. 31a (30%-regeling) — when granted, the applied tax-free
			// percentage must not exceed 30 for 2025-2026.
			'nl-30-percent-regeling' => self::thirtyPercentRateWithinCap(...),
			// Wet LB 1964 art. 31a (aftopping WNT-norm) — a granted 30%-ruling is capped
			// at the WNT-norm.
			'nl-30-regeling-aftoppingsgrens' => self::thirtyPercentCappedAtWntNorm(...),
			// Wet LB 1964 art. 31a (looptijd) — a granted 30%-ruling runs at most
			// 60 months from its start; flags an end date absent/beyond the term
			// or a stale ruling past its end date (30-procent-regeling).
			'nl-30-regeling-looptijd-5jaar' => self::thirtyPercentTermSatisfied(...),
			// Wet LB 1964 art. 31a (salarisnorm) — a granted 30%-ruling requires an
			// annualised salary at or above the applicable norm (general or the
			// under-30-master reduced norm) (30-procent-regeling).
			'nl-30-regeling-salarisnorm' => self::thirtyPercentSalaryNormSatisfied(...),
			// Regulation (EC) 883/2004 art. 12 — a posted worker holds a valid A1
			// certificate that runs no longer than 24 months from the posting.
			'eu-a1-posted-worker' => self::a1CertificateValid(...),
		];

	}//end employeeChecks()

	/**
	 * The Employee closures employeeChecks() holds, the engine's registration
	 * before tables(); NlPayrollTableParityTest compares the tables with them.
	 * Never registered.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 */
	public static function legacyChecks(): array {
		return ['Employee' => self::employeeChecks()];

	}//end legacyChecks()
}//end class
