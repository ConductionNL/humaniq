<?php

/**
 * Unit tests for AllowanceSplitter.
 *
 * The norm is the Belastingdienst 2026 targeted exemption for home working,
 * 2.45 a day (Tarieven, bedragen en percentages loonheffingen vanaf 1 januari
 * 2026, table 13), in cents.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\AllowanceSplitter;
use PHPUnit\Framework\TestCase;

/**
 * Taxed parts to the gross, untaxed parts to net, capped at the norm.
 */
class AllowanceSplitterTest extends TestCase {

	/**
	 * The verified 2026 day norm.
	 *
	 * @var array{perDayCents: int, verified: bool}
	 */
	private const NORM = ['perDayCents' => 245, 'verified' => true];

	/**
	 * An active allowance.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function allowance(array $overrides = []): array {
		return array_merge(
			['id' => 'alw-1', 'employeeId' => 'emp-1', 'kind' => 'thuiswerk', 'amountPerDay' => 2.45, 'daysPerMonth' => 8, 'taxTreatment' => 'gericht-vrijgesteld', 'startDate' => '2026-01-01', 'endDate' => null, 'status' => 'active'],
			$overrides
		);
	}//end allowance()

	/**
	 * Eight home-working days at the norm are untaxed in full.
	 *
	 * @return void
	 */
	public function testAHomeWorkingAllowanceAtTheNormIsUntaxed(): void {
		$split = (new AllowanceSplitter())->split(allowance: $this->allowance(), arrangement: null, norm: self::NORM);

		$this->assertSame('paid', $split['status']);
		$this->assertSame(0, $split['taxedCents']);
		$this->assertSame(1960, $split['untaxedCents']);
		$this->assertSame('gericht-vrijgesteld', $split['wkrCategory']);
	}//end testAHomeWorkingAllowanceAtTheNormIsUntaxed()

	/**
	 * Above the norm the excess is taxed.
	 *
	 * @return void
	 */
	public function testAHomeWorkingAllowanceOverTheNormTaxesTheExcess(): void {
		$split = (new AllowanceSplitter())->split(allowance: $this->allowance(['amountPerDay' => null, 'amountPerMonth' => 25.00]), arrangement: null, norm: self::NORM);

		$this->assertSame(1960, $split['untaxedCents']);
		$this->assertSame(540, $split['taxedCents']);
	}//end testAHomeWorkingAllowanceOverTheNormTaxesTheExcess()

	/**
	 * An unverified or missing norm pays nothing, and says so; so do missing days.
	 *
	 * @return void
	 */
	public function testAnUnverifiedNormOrMissingDaysPaysNothing(): void {
		$splitter = new AllowanceSplitter();

		$unverified = $splitter->split(allowance: $this->allowance(), arrangement: null, norm: ['perDayCents' => 245, 'verified' => false]);
		$this->assertSame('norm-unverified', $unverified['status']);
		$this->assertSame(0, $unverified['taxedCents'] + $unverified['untaxedCents']);

		$this->assertSame('norm-unverified', $splitter->split(allowance: $this->allowance(), arrangement: null, norm: null)['status']);

		$noDays = $splitter->split(allowance: $this->allowance(['amountPerDay' => null, 'amountPerMonth' => 20.00, 'daysPerMonth' => null]), arrangement: null, norm: self::NORM);
		$this->assertSame('days-missing', $noDays['status']);
		$this->assertSame(0, $noDays['untaxedCents']);
	}//end testAnUnverifiedNormOrMissingDaysPaysNothing()

	/**
	 * A taxed telephone allowance goes to the gross whole; a free-margin one to
	 * net whole; an untaxed telephone allowance as declared.
	 *
	 * @return void
	 */
	public function testTaxedFreeMarginAndDeclaredTreatments(): void {
		$splitter = new AllowanceSplitter();

		$taxed = $splitter->split(allowance: $this->allowance(['kind' => 'telefoon', 'amountPerDay' => null, 'amountPerMonth' => 20.00, 'taxTreatment' => 'belast']), arrangement: null, norm: null);
		$this->assertSame(['paid', 2000, 0, null], [$taxed['status'], $taxed['taxedCents'], $taxed['untaxedCents'], $taxed['wkrCategory']]);

		$free = $splitter->split(allowance: $this->allowance(['kind' => 'other', 'amountPerDay' => null, 'amountPerMonth' => 50.00, 'taxTreatment' => 'vrije-ruimte']), arrangement: null, norm: null);
		$this->assertSame(['paid', 0, 5000, 'vrije-ruimte'], [$free['status'], $free['taxedCents'], $free['untaxedCents'], $free['wkrCategory']]);

		$phone = $splitter->split(allowance: $this->allowance(['kind' => 'telefoon', 'amountPerDay' => null, 'amountPerMonth' => 15.00]), arrangement: null, norm: null);
		$this->assertSame([0, 1500], [$phone['taxedCents'], $phone['untaxedCents']]);
	}//end testTaxedFreeMarginAndDeclaredTreatments()

	/**
	 * A travel allowance takes its amount and tax-free part from the commuting
	 * arrangement; without one it is not paid.
	 *
	 * @return void
	 */
	public function testATravelAllowanceReadsItsCommutingArrangement(): void {
		$splitter = new AllowanceSplitter();
		$allowance = $this->allowance(['kind' => 'reiskosten', 'amountPerDay' => null, 'daysPerMonth' => null, 'commuteArrangementId' => 'ca-1']);

		$split = $splitter->split(allowance: $allowance, arrangement: ['id' => 'ca-1', 'monthlyAllowance' => 140.00, 'taxFreeMonthly' => 118.13, 'taxableMonthly' => 21.87], norm: null);
		$this->assertSame([2187, 11813], [$split['taxedCents'], $split['untaxedCents']]);

		$this->assertSame('arrangement-missing', $splitter->split(allowance: $allowance, arrangement: null, norm: null)['status']);
	}//end testATravelAllowanceReadsItsCommutingArrangement()

	/**
	 * An allowance without an amount, or with an unknown treatment, pays nothing.
	 *
	 * @return void
	 */
	public function testNoAmountOrAnUnknownTreatmentPaysNothing(): void {
		$splitter = new AllowanceSplitter();

		$this->assertSame('no-amount', $splitter->split(allowance: $this->allowance(['amountPerDay' => null, 'daysPerMonth' => null]), arrangement: null, norm: self::NORM)['status']);
		$this->assertSame('treatment-unknown', $splitter->split(allowance: $this->allowance(['taxTreatment' => 'x']), arrangement: null, norm: self::NORM)['status']);
	}//end testNoAmountOrAnUnknownTreatmentPaysNothing()

	/**
	 * An allowance covers a period while active and inside its dates.
	 *
	 * @return void
	 */
	public function testAnAllowanceCoversThePeriodsInsideItsDates(): void {
		$splitter = new AllowanceSplitter();
		$allowance = $this->allowance(['startDate' => '2026-03-15', 'endDate' => '2026-05-01']);

		$this->assertFalse($splitter->covers(allowance: $allowance, period: '2026-02'));
		$this->assertTrue($splitter->covers(allowance: $allowance, period: '2026-03'));
		$this->assertTrue($splitter->covers(allowance: $allowance, period: '2026-05'));
		$this->assertFalse($splitter->covers(allowance: $allowance, period: '2026-06'));
		$this->assertFalse($splitter->covers(allowance: array_merge($allowance, ['status' => 'draft']), period: '2026-04'));
		$this->assertFalse($splitter->covers(allowance: array_merge($allowance, ['startDate' => '']), period: '2026-04'));
		$this->assertFalse($splitter->covers(allowance: $allowance, period: 'garbage'));
	}//end testAnAllowanceCoversThePeriodsInsideItsDates()

}//end class
