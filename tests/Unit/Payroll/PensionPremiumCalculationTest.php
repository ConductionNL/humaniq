<?php

/**
 * PensionPremiumCalculationTest
 *
 * The pension premium steps of the NL pack through the real calculator
 * (payroll-engine-port-from-shillinq 2.1): the REQ-PPP-001 scenarios.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll
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
 * @spec openspec/specs/payroll-pension-premium/spec.md#REQ-PPP-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\CalculationLevies;
use OCA\Humaniq\Payroll\CalculationWage;
use OCA\Humaniq\Payroll\InsuranceTariffs;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\PensionPremium;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Payroll\WageTaxElection;
use PHPUnit\Framework\TestCase;

/**
 * The pension premium reduces net and the taxable wage and is an employer charge.
 *
 * @spec openspec/specs/payroll-pension-premium/spec.md#REQ-PPP-001
 */
class PensionPremiumCalculationTest extends TestCase {

	/**
	 * REQ-PPP-001 "A full-time participant pays the employee premium": a base
	 * of 2,500.00 at 7.5% and 15% withholds 187.50 and charges 375.00; net
	 * is 187.50 lower before the wage tax effect, the taxable wage drops by
	 * the employee premium and the holiday reserve does not move.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-pension-premium/spec.md#REQ-PPP-001
	 */
	public function testAFullTimeParticipantPaysTheEmployeePremium(): void {
		$without = $this->calculate(gross: 400000, pension: null);
		$with = $this->calculate(gross: 400000, pension: new PensionPremium(pensionBaseCents: 250000, employeePercentage: 7.5, employerPercentage: 15.0));

		$this->assertSame(250000, $with->pensionBaseCents);
		$this->assertSame(18750, $with->pensionEmployeeCents);
		$this->assertSame(37500, $with->pensionEmployerCents);
		$taxSaved = ($without->loonheffingCents - $with->loonheffingCents);
		$this->assertGreaterThan(0, $taxSaved, 'the employee premium lowers the wage tax');
		$this->assertSame(($without->nettoPayCents - 18750 + $taxSaved), $with->nettoPayCents);
		$this->assertSame(($without->taxableWageCents - 18750), $with->taxableWageCents);
		$this->assertSame($without->vakantiegeldReservedCents, $with->vakantiegeldReservedCents);
		$this->assertSame($without->grossPayCents, $with->grossPayCents);
		$this->assertGreaterThan($without->employerChargesCents, $with->employerChargesCents);
	}//end testAFullTimeParticipantPaysTheEmployeePremium()

	/**
	 * REQ-PPP-001 "A salary below the franchise pays no premium".
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-pension-premium/spec.md#REQ-PPP-001
	 */
	public function testASalaryBelowTheFranchisePaysNoPremium(): void {
		$without = $this->calculate(gross: 120000, pension: null);
		$with = $this->calculate(gross: 120000, pension: new PensionPremium(pensionBaseCents: 0, employeePercentage: 7.5, employerPercentage: 15.0));

		$this->assertSame(0, $with->pensionBaseCents);
		$this->assertSame(0, $with->pensionEmployeeCents);
		$this->assertSame(0, $with->pensionEmployerCents);
		$this->assertSame($without->nettoPayCents, $with->nettoPayCents);
	}//end testASalaryBelowTheFranchisePaysNoPremium()

	/**
	 * REQ-PPP-001 "No participation, no premium": the result is the one
	 * before this change, and the input snapshot carries no pension keys.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-pension-premium/spec.md#REQ-PPP-001
	 */
	public function testNoParticipationNoPremium(): void {
		$input = $this->input(gross: 400000, pension: null);
		$result = (new PayrollCalculator())->calculate($input, TaxTables::load('nl-2026'));

		$this->assertSame(0, $result->pensionEmployeeCents);
		$this->assertSame(0, $result->pensionEmployerCents);
		$this->assertSame(318133, $result->nettoPayCents);
		$this->assertArrayNotHasKey('pensionBaseCents', $input->toArray());
	}//end testNoParticipationNoPremium()

	/**
	 * The stamped snapshot of a participant round-trips with its pension
	 * part, so a recalculation from the snapshot withholds the same premium.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-pension-premium/spec.md#REQ-PPP-001
	 */
	public function testTheSnapshotCarriesThePensionPart(): void {
		$input = $this->input(gross: 400000, pension: new PensionPremium(pensionBaseCents: 250000, employeePercentage: 7.5, employerPercentage: 15.0));
		$snapshot = $input->toArray();

		$this->assertSame(250000, $snapshot['pensionBaseCents']);
		$this->assertSame(7.5, $snapshot['pensionEmployeePercentage']);
		$this->assertSame(15.0, $snapshot['pensionEmployerPercentage']);
		$this->assertSame($snapshot, CalculationInput::fromCanonicalJson($input->toCanonicalJson())->toArray());
	}//end testTheSnapshotCarriesThePensionPart()

	/**
	 * A negative base or percentage is refused.
	 *
	 * @return void
	 */
	public function testANegativePensionPartIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		new PensionPremium(pensionBaseCents: -1);
	}//end testANegativePensionPartIsRefused()

	/**
	 * The calculation of an April wage.
	 *
	 * @param int                 $gross   The gross wage, in cents.
	 * @param PensionPremium|null $pension The pension part.
	 *
	 * @return \OCA\Humaniq\Payroll\CalculationResult
	 */
	private function calculate(int $gross, ?PensionPremium $pension): \OCA\Humaniq\Payroll\CalculationResult {
		return (new PayrollCalculator())->calculate($this->input(gross: $gross, pension: $pension), TaxTables::load('nl-2026'));
	}//end calculate()

	/**
	 * An April input on the white table with the anchor's tariffs.
	 *
	 * @param int                 $gross   The gross wage, in cents.
	 * @param PensionPremium|null $pension The pension part.
	 *
	 * @return CalculationInput
	 */
	private function input(int $gross, ?PensionPremium $pension): CalculationInput {
		return new CalculationInput(
			wage: new CalculationWage(grossMonthlySalaryCents: $gross, dateOfBirth: '1990-04-12', period: '2026-04'),
			levies: new CalculationLevies(
				wageTax: new WageTaxElection(taxTableColor: 'wit', loonheffingskortingToegepast: true),
				insurance: new InsuranceTariffs(awfTariff: 'low', aofTariff: 'laag', whkPercentage: 1.52)
			),
			pension: $pension
		);
	}//end input()
}//end class
