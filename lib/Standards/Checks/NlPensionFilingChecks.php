<?php

/**
 * NL Pension Filing (UPA) Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Executable checks for the Dutch sector-pension filing rules of the payroll
 * corpus (lib/Standards/rules/payroll.json, framework nl-pensioenaangifte),
 * mapped onto the PensionFiling / PayrollRun object types (pension-filing-upa-mvp):
 * a delivery must reference an approved-or-later PayrollRun
 * (nl-upa-payrollrun-approved), every period with an approved run must have
 * at least one delivery (nl-upa-monthly-completeness), and an unsent delivery
 * must not be overdue or within 14 days of its deadline
 * (nl-upa-deadline-alert).
 *
 * The reference-integrity and completeness predicates are cross-object: they
 * read the `context['related']` index RuleAuditService::audit() populates in
 * its pre-pass (PayrollRun `{id, period, status}` by id + approved-run period
 * set; PensionFiling period set) rather than loading siblings themselves.
 * This provider does NOT implement SeedsObjects: a self-contained sample
 * cannot carry a resolvable `payrollRunId`, and a dangling reference would
 * immediately violate the fail-closed nl-upa-payrollrun-approved rule — the
 * PayrollRun + PensionFiling seed data instead lives in
 * lib/Settings/register.d/hr-seed.json (ADR-001).
 *
 * Converted in part to the table-backed check form (rules-onto-or-decision-
 * tables, next conversion wave): `nl-upa-payrollrun-approved` and
 * `nl-upa-monthly-completeness` are OpenRegister decision tables via the
 * ProvidesTables capability, their closures kept as legacyChecks() -- the
 * parity oracle, NOT registered -- until staged retirement (REQ-RULE-009).
 * `nl-upa-deadline-alert` stays a closure: it is a date window over the
 * shared filing-deadline arithmetic, not a table shape.
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
 * @spec openspec/specs/pension-filing-upa-mvp/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use DateTimeImmutable;
use OCA\Humaniq\Support\ChecksFilingDeadlines;

/**
 * Dutch sector-pension (UPA) filing executable checks.
 */
final class NlPensionFilingChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;
	use ChecksFilingDeadlines;

	/**
	 * PayrollRun statuses that count as "approved or later" for both the
	 * reference-integrity and monthly-completeness predicates.
	 *
	 * @var string[]
	 */
	private const APPROVED_OR_LATER = ['approved', 'posted', 'paid'];

	/**
	 * APPROVED_OR_LATER as a decision-table input entry.
	 *
	 * @var string
	 */
	private const APPROVED_OR_LATER_TEST = 'in ("approved","posted","paid")';

	/**
	 * Number of days before (and including) the deadline that an unsent
	 * filing starts alerting (nl-upa-deadline-alert).
	 *
	 * @var int
	 */
	private const ALERT_WINDOW_DAYS = 14;

	/**
	 * {@inheritDoc}
	 *
	 * Only the deadline alert: the other two UPA rules are table-declared
	 * (see tables()).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function checks(): array {
		return [
			'PensionFiling' => [
				// Fund aanleverschema / uitvoeringsreglement — an unsent delivery does not
				// sit within 14 days of, or past, its deadline.
				'nl-upa-deadline-alert' => static fn (array $o, array $c): bool => self::deadlineNotAlerting($o),
			],
		];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * The run-approval and monthly-completeness rules as derivation +
	 * FIRST-hit decision table.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/specs/pension-filing-upa-mvp/spec.md
	 */
	public static function tables(): array {
		return [
			'PensionFiling' => [
				// UPA-specificatie (SIVI) / fund delivery terms — the delivery reports
				// approved salary data, so its PayrollRun must be approved or later.
				'nl-upa-payrollrun-approved' => [
					'derive' => static fn (array $o, array $c): array => ['runStatus' => self::referencedRunStatus($o, $c)],
					'table' => [
						'hitPolicy' => 'FIRST',
						'inputs' => [['name' => 'runStatus', 'type' => 'string']],
						'outputs' => [['name' => 'satisfied', 'type' => 'boolean']],
						'rules' => [
							['id' => 'run-approved-or-later', 'inputEntries' => [self::APPROVED_OR_LATER_TEST], 'outputEntries' => [true]],
							['id' => 'run-missing-or-not-approved', 'inputEntries' => ['-'], 'outputEntries' => [false]],
						],
					],
				],
			],
			// Additive on PayrollRun: NlPayrollChecks already registers
			// xc-payroll-gl-reconciliation / xc-withholding-liability-clearing here;
			// RuleEngine merges providers per object type, never overwrites.
			'PayrollRun' => [
				// APG aanleververplichting (UPA, monthly delivery) — every period with an
				// approved-or-later NL run must have at least one PensionFiling.
				'nl-upa-monthly-completeness' => [
					'derive' => static fn (array $o, array $c): ?array => self::completenessInputs($o, $c),
					'table' => self::satisfiedTable(
						[['name' => 'nl', 'type' => 'boolean'], ['name' => 'status', 'type' => 'string'], ['name' => 'periodFiled', 'type' => 'boolean']],
						[['id' => 'approved-run-without-filing', 'inputEntries' => ['true', self::APPROVED_OR_LATER_TEST, 'false'], 'outputEntries' => [false]]]
					),
				],
			],
		];

	}//end tables()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * True when the filing's `payrollRunId` resolves in the context's
	 * PayrollRun index to a run whose status is approved/posted/paid.
	 * Fail-closed: an empty or dangling reference, or a run in any other
	 * status, is a violation.
	 *
	 * @param array<string, mixed> $o The PensionFiling.
	 * @param array<string, mixed> $c Evaluation context (carries `related`).
	 *
	 * @return bool
	 */
	private static function payrollRunApproved(array $o, array $c): bool {
		$payrollRunId = trim((string)($o['payrollRunId'] ?? ''));
		if ($payrollRunId === '') {
			return false;
		}

		$byId = self::relatedPayrollRunsById($c);
		$run = ($byId[$payrollRunId] ?? null);
		if (is_array($run) === false) {
			return false;
		}

		return in_array((string)($run['status'] ?? ''), self::APPROVED_OR_LATER, true);
	}//end payrollRunApproved()

	/**
	 * True when this NL PayrollRun is not (yet) approved-or-later, or its
	 * period has at least one PensionFiling in the context's period set
	 * (MVP fund-blind check — the full per-configured-fund obligation is
	 * recorded in the rule statement, not enforced field-by-field here).
	 *
	 * @param array<string, mixed> $o The PayrollRun.
	 * @param array<string, mixed> $c Evaluation context (carries `related`).
	 *
	 * @return bool
	 */
	private static function monthlyCompletenessSatisfied(array $o, array $c): bool {
		if (strtoupper((string)($o['jurisdiction'] ?? '')) !== 'NL') {
			return true;
		}

		if (in_array((string)($o['status'] ?? ''), self::APPROVED_OR_LATER, true) === false) {
			return true;
		}

		$period = (string)($o['period'] ?? '');
		if ($period === '') {
			return true;
		}

		$filedPeriods = (array)($c['related']['PensionFiling']['filedPeriods'] ?? []);
		return in_array($period, $filedPeriods, true);
	}//end monthlyCompletenessSatisfied()

	/**
	 * The `related.PayrollRun.byId` index from the context, or an empty
	 * array when the pre-pass has not populated it.
	 *
	 * @param array<string, mixed> $c Evaluation context.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function relatedPayrollRunsById(array $c): array {
		$byId = ($c['related']['PayrollRun']['byId'] ?? []);
		return is_array($byId) === true ? $byId : [];
	}//end relatedPayrollRunsById()

	/**
	 * The status of the PayrollRun a filing references, or '' when the
	 * reference is empty or does not resolve.
	 *
	 * @param array<string, mixed> $o The PensionFiling.
	 * @param array<string, mixed> $c Evaluation context; reads `related.PayrollRun.byId`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function referencedRunStatus(array $o, array $c): string {
		$run = (self::relatedPayrollRunsById($c)[trim((string)($o['payrollRunId'] ?? ''))] ?? null);

		return is_array($run) === true ? (string)($run['status'] ?? '') : '';

	}//end referencedRunStatus()

	/**
	 * The completeness table's inputs, or null for a run without a period.
	 *
	 * @param array<string, mixed> $o The PayrollRun.
	 * @param array<string, mixed> $c Evaluation context; reads `related.PensionFiling.filedPeriods`.
	 *
	 * @return array{nl: bool, status: string, periodFiled: bool}|null
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function completenessInputs(array $o, array $c): ?array {
		$period = (string)($o['period'] ?? '');
		if ($period === '') {
			return null;
		}

		return [
			'nl' => (strtoupper((string)($o['jurisdiction'] ?? '')) === 'NL'),
			'status' => (string)($o['status'] ?? ''),
			'periodFiled' => in_array($period, (array)($c['related']['PensionFiling']['filedPeriods'] ?? []), true),
		];

	}//end completenessInputs()

	/**
	 * Parity oracle for the two table-declared UPA rules: their closures from
	 * before the conversion, compared with tables() by
	 * NlPensionFilingTableParityTest and never registered; removed after the
	 * OpenRegister audit run confirms the tables.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/specs/pension-filing-upa-mvp/spec.md
	 */
	public static function legacyChecks(): array {
		return [
			'PensionFiling' => [
				'nl-upa-payrollrun-approved' => static fn (array $o, array $c): bool => self::payrollRunApproved($o, $c),
			],
			'PayrollRun' => [
				'nl-upa-monthly-completeness' => static fn (array $o, array $c): bool => self::monthlyCompletenessSatisfied($o, $c),
			],
		];

	}//end legacyChecks()

}//end class
