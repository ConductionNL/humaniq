<?php

/**
 * NL Payroll GL-Post Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Executable check for the payroll-to-GL posting idempotency rule
 * (`nl-glpost-idempotent-per-run`, lib/Standards/rules/payroll.json,
 * payroll-glpost-shillinq), mapped onto the `PayrollGLPost` object type.
 *
 * The predicate is cross-object: it reads the `context['glpost']
 * ['activeCountByRun']` index `RuleAuditService::audit()` populates in its
 * pre-pass (a payrollRunId => active-record-count map) rather than loading
 * sibling rows itself. An active (`pending`/`posted`) record violates when
 * its run's active count exceeds 1; a `posted` record additionally violates
 * unless it carries a `journalEntryId` + `postedAt` and its `lines` snapshot
 * balances cents-equal. `failed`/`skipped-no-shillinq` attempts are always
 * compliant — they are superseded-by-retry outcomes, never a violation
 * (design.md D6).
 *
 * This provider does NOT implement SeedsObjects: the seed PayrollGLPost lives
 * declaratively in `lib/Settings/register.d/hr-glpost.json` (ADR-001), the
 * same pattern NlPensionFilingChecks documents for cross-object predicates
 * whose sample would otherwise need a resolvable sibling reference.
 *
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): the GL-post idempotency rule is declared as an OpenRegister decision table via
 * the ProvidesTables capability. The derivations stay here; the shared
 * Dmn\DecisionTableEvaluator decides. The closure predicate remains
 * available as legacyChecks() -- the parity oracle, NOT registered in the
 * engine -- until staged retirement (REQ-RULE-009).
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
 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Payroll-to-GL posting idempotency executable check.
 *
 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
 */
final class NlGlPostChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * PayrollGLPost statuses that count toward the at-most-one-per-run
	 * invariant.
	 *
	 * @var string[]
	 */
	private const ACTIVE_STATUSES = ['pending', 'posted'];

	/**
	 * {@inheritDoc}
	 *
	 * Empty: the check this provider owns is table-declared (see tables()).
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
	 * `nl-glpost-idempotent-per-run` as derivation + FIRST-hit decision
	 * table: an active (pending/posted) post fails when its run has more than
	 * one active post, and a posted one also fails without a journal entry,
	 * a posted-at moment or a balanced lines snapshot.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
	 */
	public static function tables(): array {
		return [
			'PayrollGLPost' => [
				'nl-glpost-idempotent-per-run' => [
					'derive' => static fn (array $o, array $context): array => [
						'status' => (string)($o['status'] ?? ''),
						'activeCount' => (int)($context['glpost']['activeCountByRun'][(string)($o['payrollRunId'] ?? '')] ?? 0),
						'journalEntryRecorded' => self::present($o, 'journalEntryId'),
						'postedAtRecorded' => self::present($o, 'postedAt'),
						'linesBalance' => self::linesBalance($o['lines'] ?? []),
					],
					'table' => self::satisfiedTable(
						[
							['name' => 'status', 'type' => 'string'],
							['name' => 'activeCount', 'type' => 'number'],
							['name' => 'journalEntryRecorded', 'type' => 'boolean'],
							['name' => 'postedAtRecorded', 'type' => 'boolean'],
							['name' => 'linesBalance', 'type' => 'boolean'],
						],
						[
							['id' => 'second-active-post', 'inputEntries' => ['in ("pending","posted")', '> 1', '-', '-', '-'], 'outputEntries' => [false]],
							['id' => 'posted-without-journal-entry', 'inputEntries' => ['in ("posted")', '-', 'false', '-', '-'], 'outputEntries' => [false]],
							['id' => 'posted-without-moment', 'inputEntries' => ['in ("posted")', '-', '-', 'false', '-'], 'outputEntries' => [false]],
							['id' => 'posted-unbalanced', 'inputEntries' => ['in ("posted")', '-', '-', '-', 'false'], 'outputEntries' => [false]],
						]
					),
				],
			],
		];

	}//end tables()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * The `nl-glpost-idempotent-per-run` predicate (spec.md REQ-PGP-007).
	 *
	 * @param array<string, mixed> $o The PayrollGLPost object.
	 * @param array<string, mixed> $context Evaluation context; reads `glpost.activeCountByRun`.
	 *
	 * @return bool
	 */
	private static function isCompliant(array $o, array $context): bool {
		$status = (string)($o['status'] ?? '');
		if (in_array($status, self::ACTIVE_STATUSES, true) === false) {
			// failed / skipped-no-shillinq attempts are superseded, never a violation.
			return true;
		}

		$runId = (string)($o['payrollRunId'] ?? '');
		$activeCount = (int)($context['glpost']['activeCountByRun'][$runId] ?? 0);
		if ($activeCount > 1) {
			return false;
		}

		if ($status !== 'posted') {
			return true;
		}

		if (self::present($o, 'journalEntryId') === false || self::present($o, 'postedAt') === false) {
			return false;
		}

		return self::linesBalance($o['lines'] ?? []);
	}//end isCompliant()

	/**
	 * True when a posted record's `lines` snapshot is non-empty, well-shaped,
	 * and its debit total equals its credit total to the cent.
	 *
	 * @param mixed $lines The `lines` field value.
	 *
	 * @return bool
	 */
	private static function linesBalance(mixed $lines): bool {
		if (is_array($lines) === false || $lines === []) {
			return false;
		}

		$totalCents = ['debit' => 0, 'credit' => 0];
		foreach ($lines as $line) {
			if (self::isWellFormedLine($line) === false) {
				return false;
			}

			$totalCents[$line['side']] += (int)round(((float)$line['amount']) * 100);
		}

		return $totalCents['debit'] === $totalCents['credit'] && $totalCents['debit'] > 0;
	}//end linesBalance()

	/**
	 * True when a `lines` entry is a row with a numeric amount on the debit or credit side.
	 *
	 * @param mixed $line One `lines` entry.
	 *
	 * @return bool
	 */
	private static function isWellFormedLine(mixed $line): bool {
		return is_array($line) === true
			&& isset($line['side'], $line['amount']) === true
			&& is_numeric($line['amount']) === true
			&& ($line['side'] === 'debit' || $line['side'] === 'credit');

	}//end isWellFormedLine()

	/**
	 * True when an object field holds a non-empty value.
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 *
	 * @return bool
	 */
	private static function present(array $o, string $key): bool {
		return isset($o[$key]) === true && trim((string)$o[$key]) !== '';
	}//end present()

	/**
	 * The idempotency closure from before the table form: the reference
	 * NlGlPostTableParityTest checks tables() against. Unregistered, and
	 * deleted once the OpenRegister audit run has confirmed the table.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
	 */
	public static function legacyChecks(): array {
		return [
			'PayrollGLPost' => [
				'nl-glpost-idempotent-per-run' => static fn (array $o, array $context): bool => self::isCompliant($o, $context),
			],
		];

	}//end legacyChecks()

}//end class
