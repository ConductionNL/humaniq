<?php

/**
 * NL Net-Pay IBAN-Presence Check Provider
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
 * @category Standards
 * @package  OCA\Hrmq\Standards\Checks
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
 * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-008
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

/**
 * Payslip-to-employee-IBAN-presence executable check.
 */
final class NlNetPayChecks implements CheckProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'Payslip' => [
				'nl-netpay-iban-present' => static fn (array $o, array $context): bool => self::isCompliant($o, $context),
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

}//end class
