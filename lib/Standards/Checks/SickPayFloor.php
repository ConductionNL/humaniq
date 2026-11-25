<?php

/**
 * Sick Pay Floor
 *
 * The continued-wage-payment floor of BW art. 7:629 lid 1 jo. WML art. 12
 * for a sick-pay payslip: the statutory percentage of the reference wage, or
 * in year one the minimum-wage floor when that is higher. Shared by
 * NlAbsenceChecks' `nl-loondoorbetaling-floor` table derivation and the
 * closure kept as its parity oracle. Pure and stateless.
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
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * The sick-pay floor of one payslip, in cents.
 *
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */
final class SickPayFloor {

	/**
	 * The floor table's inputs: whether the reference wage and the paid
	 * amount are numeric, and how far the paid amount falls short of the
	 * floor in cents. Null for a payslip outside a sick case, or without
	 * floor parameters.
	 *
	 * @param array<string, mixed>      $payslip    The Payslip.
	 * @param array<string, mixed>|null $parameters The rule's corpus parameters, if any.
	 *
	 * @return array{figuresRecorded: bool, shortfallCents: int}|null
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public function inputs(array $payslip, ?array $parameters): ?array {
		if (trim((string)($payslip['sickLeaveCaseId'] ?? '')) === '' || $parameters === null) {
			return null;
		}

		if (is_numeric($payslip['sickPayReferenceWage'] ?? null) === false || is_numeric($payslip['doorbetaaldLoon'] ?? null) === false) {
			return ['figuresRecorded' => false, 'shortfallCents' => 0];
		}

		return ['figuresRecorded' => true, 'shortfallCents' => ($this->cents($payslip, $parameters) - self::toCents((float)$payslip['doorbetaaldLoon']))];

	}//end inputs()

	/**
	 * The floor in cents: the statutory percentage (70 unless the corpus
	 * says otherwise) of the reference wage, or in year one the minimum-wage
	 * floor when higher.
	 *
	 * @param array<string, mixed> $payslip    The Payslip, with a numeric reference wage.
	 * @param array<string, mixed> $parameters The rule's corpus parameters.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public function cents(array $payslip, array $parameters): int {
		$statutoryFloorCents = (int)round((self::toCents((float)$payslip['sickPayReferenceWage']) * (float)($parameters['statutoryPercentage'] ?? 70)) / 100);

		$minimumWageFloor = ($payslip['sickPayMinimumWageFloor'] ?? null);
		if (($payslip['sickPayYearOne'] ?? false) !== true || is_numeric($minimumWageFloor) === false) {
			return $statutoryFloorCents;
		}

		return max($statutoryFloorCents, self::toCents((float)$minimumWageFloor));

	}//end cents()

	/**
	 * Euros as whole cents.
	 *
	 * @param float $euro The amount in euros.
	 *
	 * @return int
	 */
	private static function toCents(float $euro): int {
		return (int)round($euro * 100);

	}//end toCents()

}//end class
