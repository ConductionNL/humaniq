<?php

/**
 * BenefitEntitlementPay tests.
 *
 * Which entitlement pays in a period, what it pays (a fixed amount or a
 * schedule share of a reference salary, less the income to offset), which
 * regime it and a contract select, and why a regime may not be paid under.
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
 * @spec openspec/changes/payroll-special-regimes
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\BenefitEntitlementPay;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Tests\Unit\Support\PackFixtures;
use PHPUnit\Framework\TestCase;

/**
 * The period amount and regime of a benefit entitlement.
 *
 * @covers \OCA\Humaniq\Payroll\BenefitEntitlementPay
 * @uses   \OCA\Humaniq\Payroll\TaxTables
 */
class BenefitEntitlementPayTest extends TestCase {

	/**
	 * An active periodic entitlement pays from its start month to its end month, in its own administration only.
	 *
	 * @return void
	 */
	public function testAPeriodicEntitlementPaysWithinItsTerm(): void {
		$entitlement = ['status' => 'active', 'paymentMode' => 'periodic', 'startDate' => '2026-07-15', 'endDate' => '2026-09-30', 'administrationId' => 'adm-1'];

		self::assertFalse(BenefitEntitlementPay::paysIn($entitlement, '2026-06', 'adm-1'));
		self::assertTrue(BenefitEntitlementPay::paysIn($entitlement, '2026-07', 'adm-1'));
		self::assertTrue(BenefitEntitlementPay::paysIn($entitlement, '2026-09', 'adm-1'));
		self::assertFalse(BenefitEntitlementPay::paysIn($entitlement, '2026-10', 'adm-1'));
		self::assertFalse(BenefitEntitlementPay::paysIn($entitlement, '2026-08', 'adm-2'));
		self::assertTrue(BenefitEntitlementPay::paysIn($entitlement, '2026-08', ''));
		self::assertTrue(BenefitEntitlementPay::paysIn(array_merge($entitlement, ['endDate' => '']), '2030-01', 'adm-1'));
	}//end testAPeriodicEntitlementPaysWithinItsTerm()

	/**
	 * A draft or ended entitlement, a missing start date and a lump sum outside its month pay nothing.
	 *
	 * @return void
	 */
	public function testWhatDoesNotPay(): void {
		$base = ['status' => 'active', 'paymentMode' => 'periodic', 'startDate' => '2026-01-01'];

		self::assertFalse(BenefitEntitlementPay::paysIn(array_merge($base, ['status' => 'draft']), '2026-03', ''));
		self::assertFalse(BenefitEntitlementPay::paysIn(array_merge($base, ['status' => 'ended']), '2026-03', ''));
		self::assertFalse(BenefitEntitlementPay::paysIn(array_merge($base, ['startDate' => '']), '2026-03', ''));
		self::assertFalse(BenefitEntitlementPay::paysIn(array_merge($base, ['startDate' => 'soon']), '2026-03', ''));

		$lumpSum = ['status' => 'active', 'paymentMode' => 'lump-sum', 'startDate' => '2026-01-01', 'lumpSumPeriod' => '2026-09'];
		self::assertFalse(BenefitEntitlementPay::paysIn($lumpSum, '2026-08', ''));
		self::assertTrue(BenefitEntitlementPay::paysIn($lumpSum, '2026-09', ''));
	}//end testWhatDoesNotPay()

	/**
	 * A fixed amount less the offset, never below zero; a lump sum pays its amount.
	 *
	 * @return void
	 */
	public function testAFixedAmountLessTheOffset(): void {
		self::assertSame(['cents' => 200000, 'reason' => null], BenefitEntitlementPay::grossFor(['monthlyAmount' => 2000.00], '2026-07'));
		self::assertSame(['cents' => 125050, 'reason' => null], BenefitEntitlementPay::grossFor(['monthlyAmount' => 1500.00, 'offsetIncomeMonthly' => 249.50], '2026-07'));
		self::assertSame(['cents' => 0, 'reason' => null], BenefitEntitlementPay::grossFor(['monthlyAmount' => 500.00, 'offsetIncomeMonthly' => 900.00], '2026-07'));
		self::assertSame(['cents' => 4000000, 'reason' => null], BenefitEntitlementPay::grossFor(['paymentMode' => 'lump-sum', 'lumpSumAmount' => 40000.00, 'offsetIncomeMonthly' => 900.00], '2026-07'));
		self::assertSame('no-amount (lumpSumAmount ontbreekt)', BenefitEntitlementPay::grossFor(['paymentMode' => 'lump-sum'], '2026-07')['reason']);
	}//end testAFixedAmountLessTheOffset()

	/**
	 * A schedule pays its share of the reference salary for the month counted from the start date.
	 *
	 * @return void
	 */
	public function testAScheduleShareOfTheReferenceSalary(): void {
		$entitlement = [
			'startDate' => '2026-04-01',
			'referenceSalary' => 5000.00,
			'offsetIncomeMonthly' => 100.00,
			'schedule' => [
				['fromMonth' => 1, 'toMonth' => 2, 'percentage' => 80],
				['fromMonth' => 3, 'toMonth' => 24, 'percentage' => 70],
			],
		];

		self::assertSame(390000, BenefitEntitlementPay::grossFor($entitlement, '2026-04')['cents']);
		self::assertSame(390000, BenefitEntitlementPay::grossFor($entitlement, '2026-05')['cents']);
		self::assertSame(340000, BenefitEntitlementPay::grossFor($entitlement, '2026-06')['cents']);
		self::assertSame(340000, BenefitEntitlementPay::grossFor($entitlement, '2028-03')['cents']);

		$beyond = BenefitEntitlementPay::grossFor($entitlement, '2028-04');
		self::assertSame(0, $beyond['cents']);
		self::assertSame('no-amount (het schema dekt maand 25 van de uitkering niet)', $beyond['reason']);
		self::assertStringStartsWith('no-amount', (string)BenefitEntitlementPay::grossFor(['startDate' => '2026-01-01'], '2026-02')['reason']);
	}//end testAScheduleShareOfTheReferenceSalary()

	/**
	 * The kind and the contract type select the regime.
	 *
	 * @return void
	 */
	public function testTheRegimeKeys(): void {
		self::assertSame('rvu', BenefitEntitlementPay::regimeOfEntitlement(['kind' => 'rvu']));
		self::assertSame('wachtgeld', BenefitEntitlementPay::regimeOfEntitlement(['kind' => 'wachtgeld']));
		self::assertNull(BenefitEntitlementPay::regimeOfEntitlement(['kind' => 'pensioen']));
		self::assertSame('politiek-ambt', BenefitEntitlementPay::regimeOfContract(['type' => 'political-office']));
		self::assertNull(BenefitEntitlementPay::regimeOfContract(['type' => 'permanent']));
		self::assertNull(BenefitEntitlementPay::regimeOfContract(null));
	}//end testTheRegimeKeys()

	/**
	 * A regime is read when verified, and refused with its unverified leaves or when the tables lack it.
	 *
	 * @return void
	 */
	public function testReadingARegime(): void {
		$raw = PackFixtures::tables('nl-2026');
		$bundled = BenefitEntitlementPay::readRegime(TaxTables::fromDocument($raw, 'test'), 'politiek-ambt');
		self::assertNull($bundled['regime']);
		self::assertStringStartsWith('regime-unverified (niet bevestigd: specialRegimes.politiek-ambt.taxTableColor', (string)$bundled['reason']);

		foreach (array_keys($raw['parameters']['specialRegimes']['politiek-ambt']) as $key) {
			$raw['parameters']['specialRegimes']['politiek-ambt'][$key]['verified'] = true;
		}

		$verified = BenefitEntitlementPay::readRegime(TaxTables::fromDocument($raw, 'test'), 'politiek-ambt');
		self::assertNull($verified['reason']);
		self::assertSame('wit', $verified['regime']['taxTableColor']);

		unset($raw['parameters']['specialRegimes']);
		$missing = BenefitEntitlementPay::readRegime(TaxTables::fromDocument($raw, 'test'), 'rvu');
		self::assertNull($missing['regime']);
		self::assertStringContainsString('kennen het regime rvu niet', (string)$missing['reason']);
	}//end testReadingARegime()

}//end class
