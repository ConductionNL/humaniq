<?php

/**
 * NL Net-Pay IBAN-Presence Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Executable check for the payroll-to-bank IBAN-presence rule
 * (`nl-netpay-iban-present`, lib/Standards/rules/payroll.json,
 * payroll-sepa-netpay-shillinq), mapped onto the `Payslip` object type.
 *
 * The predicate is cross-object: it reads the `context['netpay']
 * ['ibanByEmployeeKey']` index (object id, slug, AND employeeNumber, each
 * mapped to IBAN-presence) and `context['netpay']['payablePeriods']`
 * `RuleAuditService::audit()` populates in its pre-pass, rather than loading
 * sibling Employee/PayrollRun rows itself. A payslip on a payable
 * (approved/posted) run's period violates when its `employeeId` resolves to
 * no employee, or to one without an `iban` (BW art. 7:625). Payslips whose
 * period has no payable run are always compliant -- nothing is payable yet
 * (design.md D4).
 *
 * This provider does NOT implement SeedsObjects: the seeded Employee IBANs
 * live declaratively in `lib/Settings/register.d/hr-seed.json` and the
 * NlPayrollChecks::seedObjects() Employee row, the same pattern
 * NlGlPostChecks documents for cross-object predicates whose sample would
 * otherwise need a resolvable sibling reference.
 *
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): the IBAN-presence rule is declared as OpenRegister decision tables via
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
 * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Payslip-to-employee-IBAN-presence executable check.
 *
 * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-008
 */
final class NlNetPayChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * {@inheritDoc}
	 *
	 * Empty: every check this provider owns is table-declared (see tables()).
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
	 * `nl-netpay-iban-present` as derivation + FIRST-hit decision table: a
	 * payslip on a payable period without a resolvable employee IBAN fails.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-008
	 */
	public static function tables(): array {
		return [
			'Payslip' => [
				'nl-netpay-iban-present' => [
					'derive' => static fn (array $o, array $context): array => [
						'payable' => in_array((string)($o['period'] ?? ''), (array)($context['netpay']['payablePeriods'] ?? []), true),
						'ibanOnFile' => self::ibanOnFile($o, $context),
					],
					'table' => self::satisfiedTable(
						[['name' => 'payable', 'type' => 'boolean'], ['name' => 'ibanOnFile', 'type' => 'boolean']],
						[['id' => 'payable-without-iban', 'inputEntries' => ['true', 'false'], 'outputEntries' => [false]]]
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
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * Whether the payslip's employee resolves and has an IBAN on file. An
	 * empty employee key or one that does not resolve counts as no IBAN.
	 *
	 * @param array<string, mixed> $o       The Payslip object.
	 * @param array<string, mixed> $context Evaluation context; reads `netpay.ibanByEmployeeKey`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function ibanOnFile(array $o, array $context): bool {
		$employeeKey = trim((string)($o['employeeId'] ?? ''));
		$ibanByEmployeeKey = (array)($context['netpay']['ibanByEmployeeKey'] ?? []);

		return $employeeKey !== '' && ($ibanByEmployeeKey[$employeeKey] ?? null) === true;

	}//end ibanOnFile()

	/**
	 * The `nl-netpay-iban-present` predicate (spec.md REQ-PNP-008).
	 *
	 * @param array<string, mixed> $o The Payslip object.
	 * @param array<string, mixed> $context Evaluation context; reads `netpay.ibanByEmployeeKey`/`netpay.payablePeriods`.
	 *
	 * @return bool
	 */
	private static function isCompliant(array $o, array $context): bool {
		$period = (string)($o['period'] ?? '');
		$payablePeriods = (array)($context['netpay']['payablePeriods'] ?? []);
		if (in_array($period, $payablePeriods, true) === false) {
			// Nothing is payable yet on this period's run -- not a violation (design.md D4).
			return true;
		}

		$employeeKey = trim((string)($o['employeeId'] ?? ''));
		if ($employeeKey === '') {
			return false;
		}

		$ibanByEmployeeKey = (array)($context['netpay']['ibanByEmployeeKey'] ?? []);
		if (array_key_exists($employeeKey, $ibanByEmployeeKey) === false) {
			// Employee does not resolve at all -- treated the same as missing IBAN.
			return false;
		}

		return $ibanByEmployeeKey[$employeeKey] === true;
	}//end isCompliant()

	/**
	 * Parity oracle: the IBAN-presence predicate as it stood before the table
	 * form. Never handed to the engine; NlNetPayTableParityTest compares it
	 * with tables() until the audit run on OpenRegister proves the table and
	 * it is retired (REQ-RULE-009).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-008
	 */
	public static function legacyChecks(): array {
		return [
			'Payslip' => [
				'nl-netpay-iban-present' => static fn (array $o, array $context): bool => self::isCompliant($o, $context),
			],
		];

	}//end legacyChecks()

}//end class
