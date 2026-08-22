<?php

/**
 * NL Contract/Payslip Document Check Provider
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
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
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
 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-009
 * @spec openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Written-permanent-contract document-evidence executable check.
 */
final class NlDocumentChecks implements CheckProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'EmploymentContract' => [
				'nl-contract-schriftelijk' => static fn (array $o, array $context): bool => self::isCompliant($o, $context),
			],
			'Payslip' => [
				'nl-loonstrook-verplicht' => static fn (array $o, array $context): bool => self::isLoonstrookDocumented($o, $context),
			],
		];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

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

}//end class
