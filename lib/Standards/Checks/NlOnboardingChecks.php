<?php

/**
 * NL Onboarding Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Executable checks for the Dutch onboarding rules of the labour/payroll corpus
 * (lib/Standards/rules/labour.json, frameworks nl-wid / bw7-10 / nl-loonheffingen),
 * mapped onto the Onboarding object type (onboarding-wizard-mvp): the WID identity
 * check must be done on or before the first workday (nl-onboarding-wid-check), a
 * proeftijd must respect the BW 7:652 contract-type caps and never be silently
 * outlived (nl-onboarding-proeftijd-bewaking), and the employee's
 * loonheffingenverklaring must be on file before the case is ready for (or past)
 * the first workday (nl-onboarding-loonheffingenverklaring).
 *
 * The proeftijd-cap and loonheffingenverklaring predicates are cross-object: they
 * read the `context['related']['Employee']` and
 * `context['related']['EmploymentContract']` indexes RuleAuditService::audit()
 * populates in its pre-pass (design.md D3), rather than loading siblings
 * themselves. This provider does NOT implement SeedsObjects: a self-contained
 * sample cannot carry a resolvable `employeeId` cross-reference (the pension
 * precedent) — the Onboarding seed data instead lives in
 * lib/Settings/register.d/hr-seed.json (ADR-001).
 *
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): each of the three onboarding rules is declared as an OpenRegister decision table via
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
 * @spec openspec/specs/onboarding-wizard/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use DateTimeImmutable;

/**
 * Dutch onboarding (WID / proeftijd / loonheffingenverklaring) executable checks.
 *
 * @spec openspec/specs/onboarding-wizard/spec.md
 */
final class NlOnboardingChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * Onboarding statuses at/past "ready for the first workday" — the MVP proxy
	 * for "the first payroll run may already have happened" (design.md D3, no
	 * per-employee PayrollRun membership exists in the data model yet).
	 *
	 * @var string[]
	 */
	private const READY_OR_LATER = ['gereed_eerste_werkdag', 'proeftijd_lopend', 'afgerond'];

	/**
	 * READY_OR_LATER as a decision-table input entry.
	 *
	 * @var string
	 */
	private const READY_OR_LATER_TEST = 'in ("gereed_eerste_werkdag","proeftijd_lopend","afgerond")';

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
	 * The three Onboarding rules as derivation + FIRST-hit decision tables.
	 * The date arithmetic (start date passed, proeftijd cap, overdue end)
	 * stays in the derivations; the tables decide over the flags.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/specs/onboarding-wizard/spec.md
	 */
	public static function tables(): array {
		return [
			'Onboarding' => [
				'nl-onboarding-wid-check' => [
					'derive' => static fn (array $o): array => self::widInputs($o),
					'table' => self::satisfiedTable(
						[
							['name' => 'cancelled', 'type' => 'boolean'],
							['name' => 'widCheckDone', 'type' => 'boolean'],
							['name' => 'startDatePassed', 'type' => 'boolean'],
							['name' => 'status', 'type' => 'string'],
						],
						[
							['id' => 'started-without-wid-check', 'inputEntries' => ['false', 'false', 'true', '-'], 'outputEntries' => [false]],
							['id' => 'ready-without-wid-check', 'inputEntries' => ['false', 'false', '-', self::READY_OR_LATER_TEST], 'outputEntries' => [false]],
						]
					),
				],
				'nl-onboarding-proeftijd-bewaking' => [
					'derive' => static fn (array $o, array $c): array => [
						'withinContractCap' => self::proeftijdWithinContractCap($o, $c),
						'overdueUnclosed' => (self::proeftijdNotOverdueUnclosed($o) === false),
					],
					'table' => self::satisfiedTable(
						[['name' => 'withinContractCap', 'type' => 'boolean'], ['name' => 'overdueUnclosed', 'type' => 'boolean']],
						[
							['id' => 'proeftijd-over-cap', 'inputEntries' => ['false', '-'], 'outputEntries' => [false]],
							['id' => 'proeftijd-overdue', 'inputEntries' => ['-', 'true'], 'outputEntries' => [false]],
						]
					),
				],
				'nl-onboarding-loonheffingenverklaring' => [
					'derive' => static fn (array $o, array $c): array => self::verklaringInputs($o, $c),
					'table' => self::satisfiedTable(
						[['name' => 'status', 'type' => 'string'], ['name' => 'verklaringOnFile', 'type' => 'boolean']],
						[['id' => 'ready-without-verklaring', 'inputEntries' => [self::READY_OR_LATER_TEST, 'false'], 'outputEntries' => [false]]]
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
	 * @spec openspec/specs/onboarding-wizard/spec.md
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * True when the case is geannuleerd (cancelled cases never WID-flag), or
	 * widCheckDone is true, or neither timing trigger has fired yet: startDate is
	 * not in the past AND status has not reached gereed_eerste_werkdag or later.
	 *
	 * @param array<string, mixed> $o The Onboarding case.
	 *
	 * @return bool
	 */
	private static function widCheckSatisfied(array $o): bool {
		if ((string)($o['status'] ?? 'aangenomen') === 'geannuleerd') {
			return true;
		}

		if (($o['widCheckDone'] ?? false) === true) {
			return true;
		}

		$startDate = strtotime((string)($o['startDate'] ?? ''));
		$startDatePassed = $startDate !== false && $startDate < (new DateTimeImmutable('today'))->getTimestamp();

		$readyOrLater = in_array((string)($o['status'] ?? ''), self::READY_OR_LATER, true);

		return $startDatePassed === false && $readyOrLater === false;
	}//end widCheckSatisfied()

	/**
	 * True unless (a) a contract resolves for the case's employeeId and
	 * proeftijdEndDate exceeds startDate plus the BW 7:652 cap for that
	 * contract's type/duration, or (b) the case is proeftijd_lopend with
	 * proeftijdEndDate already past (a running proeftijd must be explicitly
	 * closed via afronden/annuleren). When no contract resolves, branch (a) is
	 * skipped — deliberately not fail-closed, contracts are optional MVP data
	 * (design.md D3).
	 *
	 * @param array<string, mixed> $o The Onboarding case.
	 * @param array<string, mixed> $c Evaluation context (carries `related`).
	 *
	 * @return bool
	 */
	private static function proeftijdSatisfied(array $o, array $c): bool {
		if (self::proeftijdWithinContractCap($o, $c) === false) {
			return false;
		}

		return self::proeftijdNotOverdueUnclosed($o);
	}//end proeftijdSatisfied()

	/**
	 * Branch (a): true when no contract resolves for employeeId, or
	 * proeftijdEndDate is absent, or proeftijdEndDate does not exceed startDate
	 * plus the resolved contract's BW 7:652 cap.
	 *
	 * @param array<string, mixed> $o The Onboarding case.
	 * @param array<string, mixed> $c Evaluation context (carries `related`).
	 *
	 * @return bool
	 */
	private static function proeftijdWithinContractCap(array $o, array $c): bool {
		$proeftijdEndDate = trim((string)($o['proeftijdEndDate'] ?? ''));
		if ($proeftijdEndDate === '') {
			return true;
		}

		$employeeId = (string)($o['employeeId'] ?? '');
		$contract = self::relatedContractByEmployeeId($c)[$employeeId] ?? null;
		if (is_array($contract) === false) {
			return true;
		}

		$startDate = strtotime((string)($o['startDate'] ?? ''));
		$endDate = strtotime($proeftijdEndDate);
		if ($startDate === false || $endDate === false) {
			return true;
		}

		$capMonths = self::proeftijdCapMonths($contract);
		$capTimestamp = strtotime('+' . $capMonths . ' months', $startDate);

		return $endDate <= $capTimestamp;
	}//end proeftijdWithinContractCap()

	/**
	 * The BW 7:652 proeftijd cap in months for a resolved contract: 2 months for
	 * a permanent contract or a fixed-term contract of 2 years or more; 1 month
	 * for a fixed-term contract shorter than 2 years.
	 *
	 * @param array<string, mixed> $contract The resolved EmploymentContract fields.
	 *
	 * @return int
	 */
	private static function proeftijdCapMonths(array $contract): int {
		if ((string)($contract['type'] ?? '') === 'permanent') {
			return 2;
		}

		$contractStart = strtotime((string)($contract['startDate'] ?? ''));
		$contractEnd = strtotime((string)($contract['endDate'] ?? ''));
		if ($contractStart === false || $contractEnd === false) {
			// Duration not decidable — treat as the shorter (fixed-term) cap.
			return 1;
		}

		$twoYearsOut = strtotime('+2 years', $contractStart);

		return $contractEnd < $twoYearsOut ? 1 : 2;
	}//end proeftijdCapMonths()

	/**
	 * Branch (b): true unless the case is proeftijd_lopend and proeftijdEndDate
	 * is before the audit-run date.
	 *
	 * @param array<string, mixed> $o The Onboarding case.
	 *
	 * @return bool
	 */
	private static function proeftijdNotOverdueUnclosed(array $o): bool {
		if ((string)($o['status'] ?? '') !== 'proeftijd_lopend') {
			return true;
		}

		$proeftijdEndDate = strtotime((string)($o['proeftijdEndDate'] ?? ''));
		if ($proeftijdEndDate === false) {
			return true;
		}

		return $proeftijdEndDate >= (new DateTimeImmutable('today'))->getTimestamp();
	}//end proeftijdNotOverdueUnclosed()

	/**
	 * True unless the case's status is at/past gereed_eerste_werkdag and its
	 * resolved Employee lacks loonheffingenVerklaringOnFile = true. Fail-closed
	 * on a dangling employeeId at those statuses (per REQ-OBW-004).
	 *
	 * @param array<string, mixed> $o The Onboarding case.
	 * @param array<string, mixed> $c Evaluation context (carries `related`).
	 *
	 * @return bool
	 */
	private static function loonheffingenverklaringSatisfied(array $o, array $c): bool {
		if (in_array((string)($o['status'] ?? ''), self::READY_OR_LATER, true) === false) {
			return true;
		}

		$employeeId = (string)($o['employeeId'] ?? '');
		$employee = self::relatedEmployeesById($c)[$employeeId] ?? null;
		if (is_array($employee) === false) {
			return false;
		}

		return ($employee['loonheffingenVerklaringOnFile'] ?? false) === true;
	}//end loonheffingenverklaringSatisfied()

	/**
	 * The `related.Employee.byId` index from the context, or an empty array
	 * when the pre-pass has not populated it.
	 *
	 * @param array<string, mixed> $c Evaluation context.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function relatedEmployeesById(array $c): array {
		$byId = ($c['related']['Employee']['byId'] ?? []);
		return is_array($byId) === true ? $byId : [];
	}//end relatedEmployeesById()

	/**
	 * The `related.EmploymentContract.byEmployeeId` index from the context, or
	 * an empty array when the pre-pass has not populated it.
	 *
	 * @param array<string, mixed> $c Evaluation context.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function relatedContractByEmployeeId(array $c): array {
		$byEmployeeId = ($c['related']['EmploymentContract']['byEmployeeId'] ?? []);
		return is_array($byEmployeeId) === true ? $byEmployeeId : [];
	}//end relatedContractByEmployeeId()

	/**
	 * The WID-check table's inputs.
	 *
	 * @param array<string, mixed> $o The Onboarding.
	 *
	 * @return array{cancelled: bool, widCheckDone: bool, startDatePassed: bool, status: string}
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function widInputs(array $o): array {
		$startDate = strtotime((string)($o['startDate'] ?? ''));

		return [
			'cancelled' => ((string)($o['status'] ?? 'aangenomen') === 'geannuleerd'),
			'widCheckDone' => (($o['widCheckDone'] ?? false) === true),
			'startDatePassed' => ($startDate !== false && $startDate < (new DateTimeImmutable('today'))->getTimestamp()),
			'status' => (string)($o['status'] ?? ''),
		];

	}//end widInputs()

	/**
	 * The loonheffingenverklaring table's inputs: the status, and whether the
	 * employee resolves with a verklaring on file (a missing employee counts
	 * as none on file).
	 *
	 * @param array<string, mixed> $o The Onboarding.
	 * @param array<string, mixed> $c Evaluation context; reads the Employee index.
	 *
	 * @return array{status: string, verklaringOnFile: bool}
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function verklaringInputs(array $o, array $c): array {
		$employee = (self::relatedEmployeesById($c)[(string)($o['employeeId'] ?? '')] ?? null);

		return [
			'status' => (string)($o['status'] ?? ''),
			'verklaringOnFile' => (is_array($employee) === true && ($employee['loonheffingenVerklaringOnFile'] ?? false) === true),
		];

	}//end verklaringInputs()

	/**
	 * Reference closures for the three onboarding tables, from before the
	 * conversion. NlOnboardingTableParityTest holds them against tables();
	 * nothing registers them, and the OpenRegister audit run retires them.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/specs/onboarding-wizard/spec.md
	 */
	public static function legacyChecks(): array {
		return [
			'Onboarding' => [
				// Wet op de identificatieplicht art. 15 jo. Wet LB 1964 art. 28 lid 1
				// onder f — the WID check must precede the first workday.
				'nl-onboarding-wid-check' => static fn (array $o): bool => self::widCheckSatisfied($o),
				// BW art. 7:652 — proeftijd contract-type caps, plus overdue-unclosed.
				'nl-onboarding-proeftijd-bewaking' => static fn (array $o, array $c): bool => self::proeftijdSatisfied($o, $c),
				// Wet LB 1964 art. 29 jo. 28 — loonheffingenverklaring on file before
				// the first payroll run (proxied by status, D3).
				'nl-onboarding-loonheffingenverklaring' => static fn (array $o, array $c): bool => self::loonheffingenverklaringSatisfied($o, $c),
			],
		];

	}//end legacyChecks()

}//end class
