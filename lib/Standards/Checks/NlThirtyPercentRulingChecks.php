<?php

/**
 * NL Thirty Percent Ruling Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The four 30%-ruling rules over Employee (Wet LB 1964 art. 31a), moved out
 * of NlPayrollChecks: the tax-free rate at most 30, the WNT-norm cap, a term
 * of at most 60 months not yet past, and the salary norm (general, or the
 * reduced norm for an under-30 master).
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
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * NL Thirty Percent Ruling rules, as decision tables.
 *
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */
final class NlThirtyPercentRulingChecks implements CheckProvider, ProvidesTables {

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
 * The ruling rules: a granted ruling at a numeric rate of at most 30,
 * capped at the WNT-norm, with a readable end inside 60 months of its start
 * and not past, at or above its salary norm.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function tables(): array {
		return [
			'Employee' => [
				// Wet LB 1964 art. 31a (30%-regeling) — at most 30% tax-free for 2025-2026.
				'nl-30-percent-regeling' => [
					'derive' => static fn (array $employee): array => ['granted' => self::thirtyPercentGranted($employee), 'rateRecorded' => self::numeric($employee, 'thirtyPercentRulingRate'), 'rate' => (float)($employee['thirtyPercentRulingRate'] ?? 0)],
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
					'derive' => static fn (array $employee): array => ['granted' => self::thirtyPercentGranted($employee), 'capped' => (($employee['thirtyPercentCappedAtWntNorm'] ?? false) === true)],
					'table' => self::satisfiedTable(
						[['name' => 'granted', 'type' => 'boolean'], ['name' => 'capped', 'type' => 'boolean']],
						[['id' => 'ruling-not-capped-at-wnt-norm', 'inputEntries' => ['true', 'false'], 'outputEntries' => [false]]]
					),
				],
				// Wet LB 1964 art. 31a (looptijd) — a granted ruling runs at most 60 months, and not past its end.
				'nl-30-regeling-looptijd-5jaar' => [
					'derive' => static fn (array $employee): ?array => self::thirtyPercentTermInputs($employee),
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
					'derive' => static fn (array $employee): ?array => self::thirtyPercentSalaryInputs($employee),
					'table' => self::satisfiedTable(
						[['name' => 'shortfallCents', 'type' => 'number']],
						[['id' => 'salary-below-ruling-norm', 'inputEntries' => ['> 0'], 'outputEntries' => [false]]]
					),
				],
			],
		];

	}//end tables()

	/**
	 * {@inheritDoc}
	 *
	 * No closures: the four ruling rules are decision tables, see tables().
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [];
	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * No generated seed: NlPayrollChecks::seedObjects() carries the sample
	 * Employee these rules read.
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * Whether a 30%-ruling is granted.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 *
	 * @return bool
	 */
	private static function thirtyPercentGranted(array $employee): bool {
		return ($employee['thirtyPercentRulingGranted'] ?? false) === true;

	}//end thirtyPercentGranted()

	/**
	 * The ruling-term table's inputs: whether the end date reads, whether it
	 * lies beyond 60 months from the start, and whether it has passed. Null
	 * without a granted ruling or a readable start.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 *
	 * @return array{endReadable: bool, beyondMaximumTerm: bool, ended: bool}|null
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function thirtyPercentTermInputs(array $employee): ?array {
		$start = strtotime((string)($employee['thirtyPercentRulingStartDate'] ?? ''));
		if (self::thirtyPercentGranted($employee) === false || $start === false) {
			return null;
		}

		$end = strtotime((string)($employee['thirtyPercentRulingEndDate'] ?? ''));
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
	 * @param array<string, mixed> $employee The Employee.
	 *
	 * @return array{shortfallCents: int}|null
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function thirtyPercentSalaryInputs(array $employee): ?array {
		if (self::thirtyPercentGranted($employee) === false || self::numeric($employee, 'grossMonthlySalary') === false) {
			return null;
		}

		$normCents = ((($employee['thirtyPercentRulingReducedNormApplies'] ?? false) === true) ? self::THIRTY_PERCENT_SALARISNORM_MASTER_ONDER30_CENTS : self::THIRTY_PERCENT_SALARISNORM_ALGEMEEN_CENTS);

		return ['shortfallCents' => ($normCents - (int)round(((float)$employee['grossMonthlySalary']) * 12 * 100))];

	}//end thirtyPercentSalaryInputs()

	/**
	 * Whether a granted 30%-ruling applies at most 30 percent.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 *
	 * @return bool
	 */
	private static function thirtyPercentRateWithinCap(array $employee): bool {
		return (($employee['thirtyPercentRulingGranted'] ?? false) !== true)
			|| (self::numeric($employee, 'thirtyPercentRulingRate') && ((float)$employee['thirtyPercentRulingRate']) <= 30.0);

	}//end thirtyPercentRateWithinCap()

	/**
	 * Whether a granted 30%-ruling is capped at the WNT-norm.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 *
	 * @return bool
	 */
	private static function thirtyPercentCappedAtWntNorm(array $employee): bool {
		return (($employee['thirtyPercentRulingGranted'] ?? false) !== true)
			|| (($employee['thirtyPercentCappedAtWntNorm'] ?? false) === true);

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
	 * @param array<string, mixed> $employee The Employee object.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/30-procent-regeling/spec.md#REQ-30P-004
	 */
	private static function thirtyPercentTermSatisfied(array $employee): bool {
		if (($employee['thirtyPercentRulingGranted'] ?? false) !== true) {
			return true;
		}

		$start = strtotime((string)($employee['thirtyPercentRulingStartDate'] ?? ''));
		if ($start === false) {
			// Incomplete-but-not-contradictory ruling (no start date) — vacuous,
			// deferred to a follow-up data-quality check (design.md D5).
			return true;
		}

		$end = strtotime((string)($employee['thirtyPercentRulingEndDate'] ?? ''));
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
	 * @param array<string, mixed> $employee The Employee object.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/30-procent-regeling/spec.md#REQ-30P-004
	 */
	private static function thirtyPercentSalaryNormSatisfied(array $employee): bool {
		if (($employee['thirtyPercentRulingGranted'] ?? false) !== true) {
			return true;
		}

		if (self::numeric($employee, 'grossMonthlySalary') === false) {
			return true;
		}

		$annualCents = (int)round(((float)$employee['grossMonthlySalary']) * 12 * 100);
		$normCents = ((($employee['thirtyPercentRulingReducedNormApplies'] ?? false) === true)
			? self::THIRTY_PERCENT_SALARISNORM_MASTER_ONDER30_CENTS
			: self::THIRTY_PERCENT_SALARISNORM_ALGEMEEN_CENTS);

		return $annualCents >= $normCents;
	}//end thirtyPercentSalaryNormSatisfied()

	/**
	 * The ruling closures as NlPayrollChecks registered them before the
	 * tables; NlPayrollTableParityTest holds the tables to them. Never registered.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 */
	public static function legacyChecks(): array {
		return [
			'Employee' => [
			'nl-30-percent-regeling' => self::thirtyPercentRateWithinCap(...),
			'nl-30-regeling-aftoppingsgrens' => self::thirtyPercentCappedAtWntNorm(...),
			'nl-30-regeling-looptijd-5jaar' => self::thirtyPercentTermSatisfied(...),
			'nl-30-regeling-salarisnorm' => self::thirtyPercentSalaryNormSatisfied(...),
			],
		];

	}//end legacyChecks()
}//end class
