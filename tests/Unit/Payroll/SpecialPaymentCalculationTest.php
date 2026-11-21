<?php

/**
 * Special payment through the calculation.
 *
 * A special payment travels from the CalculationInput through the mapper and
 * the NL pack into the result: taxed at the special rate apart from the
 * regular wage tax, counted in the gross, the taxable and the premium wage,
 * and stamped on the snapshot only when there is one, so a period without it
 * computes and stamps exactly what it did before (payroll-reservation-payouts
 * REQ-RSP-001, task 1.4).
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
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-engine-shall-tax-a-special-payment-at-the-special-rate-req-rsp-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use InvalidArgumentException;
use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\CalculationInputMapper;
use OCA\Humaniq\Payroll\CalculationLevies;
use OCA\Humaniq\Payroll\CalculationWage;
use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\InsuranceTariffs;
use OCA\Humaniq\Payroll\JurisdictionPack;
use OCA\Humaniq\Payroll\PackRepository;
use OCA\Humaniq\Payroll\PackSourceInterface;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\SpecialPayment;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Payroll\WageTaxElection;
use OCA\Humaniq\Tests\Unit\Support\PackFixtures;
use PHPUnit\Framework\TestCase;

/**
 * The special payment is carried, taxed apart and stamped only when present.
 *
 * @covers \OCA\Humaniq\Payroll\SpecialPayment
 * @covers \OCA\Humaniq\Payroll\CalculationInput
 * @covers \OCA\Humaniq\Payroll\CalculationInputMapper
 * @covers \OCA\Humaniq\Payroll\CalculationResult
 * @covers \OCA\Humaniq\Payroll\PayrollCalculator
 */
class SpecialPaymentCalculationTest extends TestCase {

	/**
	 * The 3800.00 anchor employee, with an optional special part.
	 *
	 * @param SpecialPayment|null $special The special part.
	 * @param string              $period  The period.
	 *
	 * @return CalculationInput
	 */
	private function input(?SpecialPayment $special = null, string $period = '2026-05'): CalculationInput {
		return new CalculationInput(
			wage: new CalculationWage(grossMonthlySalaryCents: 380000, dateOfBirth: '1990-04-12', period: $period),
			levies: new CalculationLevies(
				wageTax: new WageTaxElection(taxTableColor: 'wit', loonheffingskortingToegepast: true),
				insurance: new InsuranceTariffs(awfTariff: 'low', aofTariff: 'laag', whkPercentage: 1.52)
			),
			special: $special
		);
	}//end input()

	/**
	 * The May holiday allowance: regular wage tax as before, the payout taxed
	 * at the special rate for an annual wage of 45600.00, both in the gross.
	 *
	 * @return void
	 */
	public function testTheMayHolidayAllowanceIsTaxedAtTheSpecialRate(): void {
		$calculator = new PayrollCalculator();
		$tables = TaxTables::load('nl-2026');
		$plain = $calculator->calculate($this->input(), $tables);
		$paid = $calculator->calculate($this->input(new SpecialPayment(specialPaymentCents: 364800, annualWageReferenceCents: 4560000)), $tables);

		self::assertSame($plain->loonheffingCents, $paid->loonheffingCents, 'the regular wage tax does not move');
		self::assertSame(364800, $paid->specialPaymentCents);
		self::assertSame(50.47, $paid->specialTaxRate);
		self::assertSame(184115, $paid->specialLoonheffingCents);
		self::assertSame(380000 + 364800, $paid->grossPayCents);
		self::assertSame($plain->nettoPayCents + 364800 - 184115, $paid->nettoPayCents);
		self::assertSame($plain->taxableWageCents + 364800, $paid->taxableWageCents);
		self::assertGreaterThan($plain->employerChargesCents, $paid->employerChargesCents, 'premiums are levied on the payout too');
	}//end testTheMayHolidayAllowanceIsTaxedAtTheSpecialRate()

	/**
	 * Without a special payment nothing is added: the result has zeroes, the
	 * mapper passes exactly the old inputs and the snapshot is the old one.
	 *
	 * @return void
	 */
	public function testNoSpecialPaymentChangesNothing(): void {
		$input = $this->input();
		$result = (new PayrollCalculator())->calculate($input, TaxTables::load('nl-2026'));

		self::assertSame(0, $result->specialPaymentCents);
		self::assertSame(0, $result->specialLoonheffingCents);
		self::assertSame(0.0, $result->specialTaxRate);
		self::assertSame(0, $result->eindejaarsuitkeringReservedCents);
		self::assertSame(308117, $result->nettoPayCents, 'the anchor fixture net');
		self::assertArrayNotHasKey('specialPayment', (new CalculationInputMapper())->toPackInputs($input));
		self::assertArrayNotHasKey('specialPaymentCents', $input->toArray());
		self::assertStringNotContainsString('special', $input->toCanonicalJson());
	}//end testNoSpecialPaymentChangesNothing()

	/**
	 * A special part survives the snapshot round trip, so a payout payslip reproduces.
	 *
	 * @return void
	 */
	public function testASpecialPaymentSurvivesTheSnapshot(): void {
		$input = $this->input(new SpecialPayment(specialPaymentCents: 80000, annualWageReferenceCents: 4560000, endOfYearRate: 8.33));

		$back = CalculationInput::fromCanonicalJson($input->toCanonicalJson());

		self::assertSame(80000, $back->special->specialPaymentCents);
		self::assertSame(4560000, $back->special->annualWageReferenceCents);
		self::assertSame(8.33, $back->special->endOfYearRate);
		self::assertSame(
			['specialPayment' => 80000, 'annualWageReference' => 4560000, 'endOfYearRate' => 8.33],
			array_intersect_key((new CalculationInputMapper())->toPackInputs($back), array_flip(['specialPayment', 'annualWageReference', 'endOfYearRate']))
		);
	}//end testASpecialPaymentSurvivesTheSnapshot()

	/**
	 * An end-of-year rate reserves that rate of the wage and pays nothing.
	 *
	 * @return void
	 */
	public function testAnEndOfYearRateReservesWithoutPaying(): void {
		$result = (new PayrollCalculator())->calculate($this->input(new SpecialPayment(endOfYearRate: 8.33), '2026-12'), TaxTables::load('nl-2026'));

		self::assertSame(31654, $result->eindejaarsuitkeringReservedCents);
		self::assertSame(380000, $result->grossPayCents);
		self::assertSame(308117, $result->nettoPayCents);
	}//end testAnEndOfYearRateReservesWithoutPaying()

	/**
	 * A pack that cannot tax a special payment refuses it rather than paying it untaxed.
	 *
	 * @return void
	 */
	public function testAPackWithoutTheSpecialInputRefusesASpecialPayment(): void {
		$source = $this->createMock(PackSourceInterface::class);
		$source->method('activePack')->willReturn(new JurisdictionPack(PackFixtures::pack(tables: 'nl-2026')));
		$calculator = new PayrollCalculator(packs: new PackRepository($source));

		$this->expectException(DslException::class);
		$this->expectExceptionMessage('specialPayment');
		$calculator->calculate($this->input(new SpecialPayment(specialPaymentCents: 100)), TaxTables::load('nl-2026'));
	}//end testAPackWithoutTheSpecialInputRefusesASpecialPayment()

	/**
	 * Negative amounts are refused.
	 *
	 * @return void
	 */
	public function testANegativeSpecialPaymentIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		new SpecialPayment(specialPaymentCents: -1);
	}//end testANegativeSpecialPaymentIsRefused()
}//end class
