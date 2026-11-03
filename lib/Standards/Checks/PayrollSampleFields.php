<?php

/**
 * Payroll sample fields
 *
 * The payslip fields every payroll seed sample shares, so the NL and the
 * EU/US samples stay cross-compliant from one definition: the NL statement
 * flags, the German no-tax and social-rate fields, the US withholding
 * fields and the French neutral fields.
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
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Shared payslip fields of the payroll seed samples.
 */
trait PayrollSampleFields {

	/**
	 * The NL loonstrook statement flags: the statement is provided and shows
	 * every required element.
	 *
	 * @return array<string, bool>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	private static function statementFlags(): array {
		return [
			'statementProvided' => true,
			'showsGrossWage' => true,
			'showsDeductionBasis' => true,
			'showsMinimumWage' => true,
			'showsEmployerEmployeeIds' => true,
		];
	}//end statementFlags()

	/**
	 * German wage tax, solidarity surcharge and church tax all absent.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	private static function germanTaxAbsent(): array {
		return [
			'lohnsteuer' => 0.00,
			'solzApplicable' => false,
			'solidaritaetszuschlag' => 0.00,
			'churchMember' => false,
			'kirchensteuer' => 0.00,
		];
	}//end germanTaxAbsent()

	/**
	 * The German church-tax and social-insurance rates, and the
	 * contribution bases they apply to.
	 *
	 * @param float $base The social-insurance contribution base.
	 *
	 * @return array<string, float>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	private static function germanSocialRates(float $base): array {
		return [
			'kirchensteuerRate' => 9.0,
			'rvEmployeeRate' => 9.3,
			'rvEmployerRate' => 9.3,
			'kvBaseRate' => 14.6,
			'avRate' => 2.6,
			'pvBaseRate' => 3.6,
			'svContributionBase' => $base,
			'kvPvContributionBase' => $base,
		];
	}//end germanSocialRates()

	/**
	 * The US FICA and Medicare rates over a wage base, and the federal
	 * income tax withheld.
	 *
	 * @param float $wageBase The FICA social-security wage base applied.
	 * @param float $withheld The federal income tax withheld.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	private static function usWithholding(float $wageBase, float $withheld): array {
		return [
			'ficaSsRate' => 6.2,
			'ficaSsWageBaseApplied' => $wageBase,
			'medicareRate' => 1.45,
			'additionalMedicareApplied' => false,
			'federalIncomeTaxWithheld' => $withheld,
		];
	}//end usWithholding()

	/**
	 * French contributions, withholding and the general reduction all absent.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	private static function frenchContributionsAbsent(): array {
		return [
			'cotisations' => 0.00,
			'pasRate' => 0.0,
			'prelevementSource' => 0.00,
			'reductionGeneraleApplied' => false,
		];
	}//end frenchContributionsAbsent()

}//end trait
