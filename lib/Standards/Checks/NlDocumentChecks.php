<?php

/**
 * NL Contract/Payslip Document Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Executable checks for two document-evidence rules: the written-contract
 * rule (`nl-contract-schriftelijk`, lib/Standards/rules/labour.json,
 * humaniq-docudesk-documents), mapped onto `EmploymentContract`, and the
 * loonstrook-evidence rule (`nl-loonstrook-verplicht`,
 * lib/Standards/rules/payroll.json, payslip-pdf-docudesk design.md D7),
 * mapped onto `Payslip`.
 *
 * Both predicates are cross-object: they read indexes
 * `RuleAuditService::buildDocumentsContext()` populates in its pre-pass --
 * `context['documents']['generatedArbeidsovereenkomstByContract']` (contractId
 * => true, present only for contracts with an active `generated`
 * arbeidsovereenkomst `GeneratedDocument`) and
 * `context['documents']['generatedLoonstrookByPayslip']` (payslipId => true,
 * present only for payslips with an active `generated` loonstrook
 * `GeneratedDocument`) -- rather than loading sibling rows themselves. Each
 * violation is on the SUBJECT (a missing document), not on the
 * GeneratedDocument schema -- a permanent contract with `writtenContract:
 * true` and no entry in its index is non-compliant (every other contract is
 * vacuously compliant); a Payslip with no entry in its index is non-compliant
 * (severity `recommended` -- the rule mirrors `nl-contract-schriftelijk`'s
 * `nl-contract-schriftelijk` calibration: `statementProvided` may be honestly
 * true via an out-of-band document, so this predicate deliberately ignores
 * that self-asserted boolean and checks the system record instead).
 *
 * This provider does NOT implement SeedsObjects: the seeded
 * `contract-jansen-permanent` + its `generated` arbeidsovereenkomst, and the
 * seeded `payslip-jansen-2026-05` + its `generated` loonstrook, live
 * declaratively in `lib/Settings/register.d/hr-documents.json` (ADR-001), the
 * same pattern NlGlPostChecks/NlPensionFilingChecks document for cross-object
 * predicates whose sample would otherwise need a resolvable sibling reference.
 *
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): both rules are declared as OpenRegister decision tables via
 * the ProvidesTables capability. The derivations stay here; the shared
 * Dmn\DecisionTableEvaluator decides. The closure predicates remain
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
 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-009
 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Written-permanent-contract document-evidence executable check.
 *
 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-004
 */
final class NlDocumentChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * {@inheritDoc}
	 *
	 * Empty: every check this provider owns is table-declared (see tables()).
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
	 * Both document-evidence rules as derivation + FIRST-hit decision table.
	 * An object without an id derives null and passes: the document index
	 * cannot be keyed, and a violation is never fabricated.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function tables(): array {
		return [
			'EmploymentContract' => [
				'nl-contract-schriftelijk' => [
					'derive' => static fn (array $o, array $context): ?array => self::documentInputs(
						$o,
						(array)($context['documents']['generatedArbeidsovereenkomstByContract'] ?? []),
						['permanentWritten' => ((string)($o['type'] ?? '') === 'permanent' && ($o['writtenContract'] ?? false) === true)]
					),
					'table' => self::satisfiedTable(
						[['name' => 'permanentWritten', 'type' => 'boolean'], ['name' => 'documented', 'type' => 'boolean']],
						[['id' => 'written-permanent-without-document', 'inputEntries' => ['true', 'false'], 'outputEntries' => [false]]]
					),
				],
			],
			'Payslip' => [
				'nl-loonstrook-verplicht' => [
					'derive' => static fn (array $o, array $context): ?array => self::documentInputs(
						$o,
						(array)($context['documents']['generatedLoonstrookByPayslip'] ?? []),
						[]
					),
					'table' => self::satisfiedTable(
						[['name' => 'documented', 'type' => 'boolean']],
						[['id' => 'payslip-without-loonstrook', 'inputEntries' => ['false'], 'outputEntries' => [false]]]
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
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * The table inputs for a document-evidence rule: the given leading
	 * inputs, then whether the object's id has a generated document in the
	 * index. Null when the object has no id.
	 *
	 * @param array<string, mixed> $o       The object.
	 * @param array<string, mixed> $index   The generated-document index, keyed by object id.
	 * @param array<string, bool>  $leading The inputs before `documented`.
	 *
	 * @return array<string, bool>|null
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function documentInputs(array $o, array $index, array $leading): ?array {
		$objectId = (string)($o['id'] ?? $o['@self']['id'] ?? '');
		if ($objectId === '') {
			return null;
		}

		return array_merge($leading, ['documented' => (bool)($index[$objectId] ?? false)]);

	}//end documentInputs()

	/**
	 * The `nl-contract-schriftelijk` predicate (spec.md REQ-HDD-009): a
	 * permanent, written contract must have an active generated
	 * arbeidsovereenkomst GeneratedDocument on file.
	 *
	 * @param array<string, mixed> $o The EmploymentContract object.
	 * @param array<string, mixed> $context Evaluation context; reads `documents.generatedArbeidsovereenkomstByContract`.
	 *
	 * @return bool
	 */
	private static function isCompliant(array $o, array $context): bool {
		$permanent = ((string)($o['type'] ?? '') === 'permanent');
		$written = (($o['writtenContract'] ?? false) === true);
		if ($permanent === false || $written === false) {
			// Non-permanent or unwritten contracts carry no document-evidence
			// obligation under this rule -- vacuously compliant.
			return true;
		}

		$contractId = (string)($o['id'] ?? $o['@self']['id'] ?? '');
		if ($contractId === '') {
			// No identity to key the document index on (e.g. an unpersisted
			// sample) -- never fabricate a violation without a resolvable id.
			return true;
		}

		return (bool)($context['documents']['generatedArbeidsovereenkomstByContract'][$contractId] ?? false);
	}//end isCompliant()

	/**
	 * The `nl-loonstrook-verplicht` predicate (payslip-pdf-docudesk design.md
	 * D7): every Payslip should have an active `generated` loonstrook
	 * `GeneratedDocument` referencing it.
	 *
	 * @param array<string, mixed> $o The Payslip object.
	 * @param array<string, mixed> $context Evaluation context; reads `documents.generatedLoonstrookByPayslip`.
	 *
	 * @return bool
	 */
	private static function isLoonstrookDocumented(array $o, array $context): bool {
		$payslipId = (string)($o['id'] ?? $o['@self']['id'] ?? '');
		if ($payslipId === '') {
			// No identity to key the document index on (e.g. an unpersisted
			// sample) -- never fabricate a violation without a resolvable id.
			return true;
		}

		return (bool)($context['documents']['generatedLoonstrookByPayslip'][$payslipId] ?? false);
	}//end isLoonstrookDocumented()

	/**
	 * Parity oracle for the two document-evidence rules: their closures from
	 * before the conversion, read only by NlDocumentTableParityTest and
	 * dropped once an OpenRegister audit run has shown the tables give the
	 * same verdicts (REQ-RULE-009).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-004
	 */
	public static function legacyChecks(): array {
		return [
			'EmploymentContract' => [
				'nl-contract-schriftelijk' => static fn (array $o, array $context): bool => self::isCompliant($o, $context),
			],
			'Payslip' => [
				'nl-loonstrook-verplicht' => static fn (array $o, array $context): bool => self::isLoonstrookDocumented($o, $context),
			],
		];

	}//end legacyChecks()

}//end class
