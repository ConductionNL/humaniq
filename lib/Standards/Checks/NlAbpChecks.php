<?php

/**
 * NL ABP Mandatory-Affiliation Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Executable check for the abp-aansluiting corpus rule
 * (lib/Standards/rules/payroll.json, framework nl-pensioenaangifte,
 * `nl-abp-fund-required`), additive alongside the shipped
 * pension-filing-upa-mvp rules on the same PayrollRun/PensionFiling object
 * types (NlPensionFilingChecks, RuleEngine merges providers per object type,
 * never overwrites).
 *
 * The shipped `nl-upa-monthly-completeness` check is deliberately fund-blind
 * and tenant-blind: it only asks whether *any* fund got a filing for a
 * period, anywhere. This provider closes the one gap that leaves open for
 * ABP-obligated administraties (Wet Privatisering ABP 1996): it asks whether
 * *this obligated administratie's own* ABP filing exists for *its own*
 * period, reading the `context['related']` cross-object indexes
 * RuleAuditService::buildRelatedContext() builds (Administration
 * `abpPlichtigByAdministrationId`; the existing PayrollRun `byId` index,
 * extended with `administrationId`; the new PensionFiling
 * `abpFiledPeriodsByAdministrationId` index) rather than loading siblings
 * itself.
 *
 * This provider does NOT implement SeedsObjects: the seed data proving both
 * branches (an obligated administratie with its own filing passing, and one
 * without a filing violating) lives in lib/Settings/register.d/hr-seed.json
 * (ADR-001) — the NlPensionFilingChecks precedent.
 *
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): the ABP fund rule is declared as an OpenRegister decision table via
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
 * @spec openspec/changes/abp-aansluiting/specs/abp-aansluiting/spec.md#REQ-ABP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Fund- and tenant-scoped ABP mandatory-affiliation completeness check.
 *
 * @spec openspec/changes/abp-aansluiting/specs/abp-aansluiting/spec.md#REQ-ABP-003
 */
final class NlAbpChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * PayrollRun statuses that count as "approved or later" for this
	 * predicate — the same set NlPensionFilingChecks::APPROVED_OR_LATER uses.
	 *
	 * @var string[]
	 */
	private const APPROVED_OR_LATER = ['approved', 'posted', 'paid'];

	/**
	 * {@inheritDoc}
	 *
	 * Empty: the check this provider owns is table-declared (see tables()).
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
	 * `nl-abp-fund-required` as derivation + FIRST-hit decision table: an NL
	 * run, approved or later, of an ABP-plichtige administratie without an
	 * ABP PensionFiling for its period fails. A run without period or
	 * administration derives null and passes, as before.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/changes/abp-aansluiting/specs/abp-aansluiting/spec.md#REQ-ABP-003
	 */
	public static function tables(): array {
		return [
			'PayrollRun' => [
				'nl-abp-fund-required' => [
					'derive' => static fn (array $run, array $c): ?array => self::abpInputs($run, $c),
					'table' => self::satisfiedTable(
						[
							['name' => 'nl', 'type' => 'boolean'],
							['name' => 'status', 'type' => 'string'],
							['name' => 'abpPlichtig', 'type' => 'boolean'],
							['name' => 'abpFiled', 'type' => 'boolean'],
						],
						[['id' => 'plichtig-run-without-abp-filing', 'inputEntries' => ['true', 'in ("approved","posted","paid")', 'true', 'false'], 'outputEntries' => [false]]]
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
	 * @spec openspec/changes/abp-aansluiting/specs/abp-aansluiting/spec.md#REQ-ABP-003
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * True (satisfied/vacuous) unless the run is an NL, approved-or-later run
	 * belonging to an `abpAansluitingsplichtig` administratie whose own
	 * `(period, administrationId)` pair carries no `fund: "abp"`
	 * PensionFiling. Vacuous for non-NL runs, non-approved-or-later runs,
	 * runs with an empty period, and runs whose `administrationId` does not
	 * resolve to an `Administration` marked `abpAansluitingsplichtig: true`
	 * (design.md REQ-ABP-003) — a missing/unresolvable administratie never
	 * turns a run red, only a *provable* obligation does.
	 *
	 * @param array<string, mixed> $run The PayrollRun.
	 * @param array<string, mixed> $c Evaluation context (carries `related`).
	 *
	 * @return bool
	 */
	private static function abpFundFiled(array $run, array $c): bool {
		if (strtoupper((string)($run['jurisdiction'] ?? '')) !== 'NL') {
			return true;
		}

		if (in_array((string)($run['status'] ?? ''), self::APPROVED_OR_LATER, true) === false) {
			return true;
		}

		$period = trim((string)($run['period'] ?? ''));
		if ($period === '') {
			return true;
		}

		$administrationId = trim((string)($run['administrationId'] ?? ''));
		if ($administrationId === '') {
			return true;
		}

		if ((self::abpPlichtigByAdministrationId($c)[$administrationId] ?? false) !== true) {
			return true;
		}

		$filedPeriods = (self::abpFiledPeriodsByAdministrationId($c)[$administrationId] ?? []);
		return ($filedPeriods[$period] ?? false) === true;
	}//end abpFundFiled()

	/**
	 * The `related.Administration.abpPlichtigByAdministrationId` index from
	 * the context, or an empty array when the pre-pass has not populated it
	 * (the Administration schema does not exist yet in the register).
	 *
	 * @param array<string, mixed> $c Evaluation context.
	 *
	 * @return array<string, bool>
	 */
	private static function abpPlichtigByAdministrationId(array $c): array {
		$map = ($c['related']['Administration']['abpPlichtigByAdministrationId'] ?? []);
		return is_array($map) === true ? $map : [];
	}//end abpPlichtigByAdministrationId()

	/**
	 * The `related.PensionFiling.abpFiledPeriodsByAdministrationId` index
	 * from the context, or an empty array when the pre-pass has not
	 * populated it.
	 *
	 * @param array<string, mixed> $c Evaluation context.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function abpFiledPeriodsByAdministrationId(array $c): array {
		$map = ($c['related']['PensionFiling']['abpFiledPeriodsByAdministrationId'] ?? []);
		return is_array($map) === true ? $map : [];
	}//end abpFiledPeriodsByAdministrationId()

	/**
	 * The ABP table's inputs, or null when the run carries no period or no
	 * administration to key the indexes on.
	 *
	 * @param array<string, mixed> $run The PayrollRun.
	 * @param array<string, mixed> $c   Evaluation context; reads the Administration and PensionFiling indexes.
	 *
	 * @return array{nl: bool, status: string, abpPlichtig: bool, abpFiled: bool}|null
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function abpInputs(array $run, array $c): ?array {
		$period = trim((string)($run['period'] ?? ''));
		$administrationId = trim((string)($run['administrationId'] ?? ''));
		if ($period === '' || $administrationId === '') {
			return null;
		}

		return [
			'nl' => (strtoupper((string)($run['jurisdiction'] ?? '')) === 'NL'),
			'status' => (string)($run['status'] ?? ''),
			'abpPlichtig' => ((self::abpPlichtigByAdministrationId($c)[$administrationId] ?? false) === true),
			'abpFiled' => (((self::abpFiledPeriodsByAdministrationId($c)[$administrationId] ?? [])[$period] ?? false) === true),
		];

	}//end abpInputs()

	/**
	 * The ABP-fund predicate from before the table form, kept to check the
	 * table against: NlAbpTableParityTest runs both, the engine runs only
	 * tables(). Goes once an OpenRegister audit run confirms the table.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/changes/abp-aansluiting/specs/abp-aansluiting/spec.md#REQ-ABP-003
	 */
	public static function legacyChecks(): array {
		return [
			// Additive on PayrollRun: NlPensionFilingChecks already registers
			// nl-upa-monthly-completeness here (and NlPayrollChecks/NlGlPostChecks
			// etc. register others); RuleEngine merges providers per object
			// type, never overwrites (design.md D2).
			'PayrollRun' => [
				// Wet Privatisering ABP (1996) — an obligated administratie's own
				// approved-or-later NL run must have its own ABP filing for its
				// own period.
				'nl-abp-fund-required' => static fn (array $run, array $c): bool => self::abpFundFiled($run, $c),
			],
		];

	}//end legacyChecks()

}//end class
