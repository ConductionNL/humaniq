<?php

/**
 * Unit tests for SickPayCalculator.
 *
 * The `anchor` fixture (tests/fixtures/sick-pay-2026/anchor.json) pins the
 * design.md D2 hand-computed worked example digit-for-digit. The remaining
 * D2 cross-check rows (floor-binding, wachtdag, CAO-100%, aangepast-loon) and
 * the year-2 switch are hand-computed inline (design.md D2's table), the same
 * digit-for-digit discipline as `PayrollCalculatorTest`'s anchor test.
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
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-002
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-003
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-004
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\SickPayCalculator;
use OCA\Humaniq\Payroll\SickPayInput;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * Golden-fixture + cross-check tests for SickPayCalculator.
 *
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
 */
class PayrollSickPayCalculatorTest extends TestCase {

	/**
	 * The anchor fixture reproduces the design.md D2 worked example
	 * digit-for-digit (REQ-SICK-001/-007).
	 *
	 * @return void
	 */
	public function testAnchorFixtureReproducesExpectedFiguresExactly(): void {
		$fixture = self::loadAnchorFixture();

		$calculator = new SickPayCalculator();
		$tables = TaxTables::load('nl-2026');
		$input = self::inputFromFixture($fixture['input']);

		$result = $calculator->compute($input, $tables);

		$expected = $fixture['expected'];
		$this->assertSame(self::cents((float)$expected['doorbetaaldLoon']), $result->doorbetaaldLoonCents, 'doorbetaaldLoon');
		$this->assertSame(self::cents((float)$expected['wachtdagDeduction']), $result->wachtdagDeductionCents, 'wachtdagDeduction');
		$this->assertSame(self::cents((float)$expected['payableGross']), $result->payableGrossCents, 'payableGross');
		$this->assertSame(self::cents((float)$expected['minimumWageFloor']), $result->minimumWageFloorCents, 'minimumWageFloor');
		$this->assertSame((bool)$expected['floorApplied'], $result->floorApplied, 'floorApplied');

		// The anchor, restated literally (belt-and-braces on top of the
		// fixture-driven assertions above — design.md D2).
		$this->assertSame(266000, $result->payableGrossCents);
		$this->assertFalse($result->floorApplied);

	}//end testAnchorFixtureReproducesExpectedFiguresExactly()

	/**
	 * Year-1 sub-WML 70% is raised to the statutory minimum wage
	 * (REQ-SICK-002): €3.000,00 -> €2.294,40, floorApplied true.
	 *
	 * @return void
	 */
	public function testFloorBindingRaisesToMinimumWage(): void {
		$result = $this->compute(
			referenceWage: 300000,
			aangepastLoon: 0,
			percentage: 70.0,
			yearOne: true,
			wachtdag: false,
			firstSickDayInPeriod: false
		);

		$this->assertSame(229440, $result->doorbetaaldLoonCents);
		$this->assertSame(229440, $result->payableGrossCents);
		$this->assertTrue($result->floorApplied);

	}//end testFloorBindingRaisesToMinimumWage()

	/**
	 * Year-2 sickness gets no minimum-wage floor (REQ-SICK-002): the same
	 * €3.000,00 employee, past 52 weeks, pays the bare 70% (€2.100,00),
	 * floorApplied false.
	 *
	 * @return void
	 */
	public function testYearTwoGetsNoMinimumWageFloor(): void {
		$result = $this->compute(
			referenceWage: 300000,
			aangepastLoon: 0,
			percentage: 70.0,
			yearOne: false,
			wachtdag: false,
			firstSickDayInPeriod: false
		);

		$this->assertSame(210000, $result->doorbetaaldLoonCents);
		$this->assertSame(210000, $result->payableGrossCents);
		$this->assertFalse($result->floorApplied);

	}//end testYearTwoGetsNoMinimumWageFloor()

	/**
	 * The waiting day is deducted once, in the starting month
	 * (REQ-SICK-003): the anchor employee with wachtdag true and
	 * firstSickDay inside the run period -> €122,30 deducted, payable gross
	 * €2.537,70.
	 *
	 * @return void
	 */
	public function testWachtdagDeductedInTheStartingMonth(): void {
		$result = $this->compute(
			referenceWage: 380000,
			aangepastLoon: 0,
			percentage: 70.0,
			yearOne: true,
			wachtdag: true,
			firstSickDayInPeriod: true
		);

		$this->assertSame(266000, $result->doorbetaaldLoonCents);
		$this->assertSame(12230, $result->wachtdagDeductionCents);
		$this->assertSame(253770, $result->payableGrossCents);

	}//end testWachtdagDeductedInTheStartingMonth()

	/**
	 * No wachtdag deduction in a continuation month (REQ-SICK-003): the same
	 * employee whose firstSickDay was in an earlier period.
	 *
	 * @return void
	 */
	public function testNoWachtdagDeductionInAContinuationMonth(): void {
		$result = $this->compute(
			referenceWage: 380000,
			aangepastLoon: 0,
			percentage: 70.0,
			yearOne: true,
			wachtdag: true,
			firstSickDayInPeriod: false
		);

		$this->assertSame(0, $result->wachtdagDeductionCents);
		$this->assertSame($result->doorbetaaldLoonCents, $result->payableGrossCents);

	}//end testNoWachtdagDeductionInAContinuationMonth()

	/**
	 * A 100% CAO pays the full wage (REQ-SICK-004): continuation equals the
	 * full wage, floor non-binding.
	 *
	 * @return void
	 */
	public function testCao100PercentPaysTheFullWage(): void {
		$result = $this->compute(
			referenceWage: 380000,
			aangepastLoon: 0,
			percentage: 100.0,
			yearOne: true,
			wachtdag: false,
			firstSickDayInPeriod: false
		);

		$this->assertSame(380000, $result->doorbetaaldLoonCents);
		$this->assertSame(380000, $result->payableGrossCents);
		$this->assertFalse($result->floorApplied);

	}//end testCao100PercentPaysTheFullWage()

	/**
	 * Adjusted wage composes worked and sick pay (REQ-SICK-004): the anchor
	 * employee, 70%, with aangepastLoon €1.000,00 -> continuation €1.960,00,
	 * doorbetaaldLoon €2.960,00.
	 *
	 * @return void
	 */
	public function testAangepastLoonComposesWorkedAndSickPay(): void {
		$result = $this->compute(
			referenceWage: 380000,
			aangepastLoon: 100000,
			percentage: 70.0,
			yearOne: true,
			wachtdag: false,
			firstSickDayInPeriod: false
		);

		$this->assertSame(296000, $result->doorbetaaldLoonCents);
		$this->assertSame(296000, $result->payableGrossCents);

	}//end testAangepastLoonComposesWorkedAndSickPay()

	/**
	 * Zero Nextcloud dependencies + PayrollCalculator untouched (acceptance
	 * criteria, tasks.md): SickPayCalculator's constructor takes no
	 * arguments and its file imports nothing outside the OCA\Humaniq\Payroll
	 * namespace.
	 *
	 * @return void
	 */
	public function testCalculatorHasZeroNextcloudDependencies(): void {
		$reflection = new \ReflectionClass(SickPayCalculator::class);
		$this->assertNull($reflection->getConstructor(), 'SickPayCalculator must be stateless (no constructor dependencies).');

		$source = (string)file_get_contents((string)$reflection->getFileName());
		$this->assertStringNotContainsString('use OCP\\', $source);
		$this->assertStringNotContainsString('use OC\\', $source);

	}//end testCalculatorHasZeroNextcloudDependencies()

	/**
	 * Build a SickPayResult for one hand-computed cross-check row (design.md
	 * D2 table), against the real nl-2026 tables, full-time hours both sides
	 * (36/36) unless noted.
	 *
	 * @param int $referenceWage `W`, in cents.
	 * @param int $aangepastLoon `A`, in cents.
	 * @param float $percentage `p`.
	 * @param bool $yearOne Year-1 switch.
	 * @param bool $wachtdag Wachtdag flag.
	 * @param bool $firstSickDayInPeriod Whether firstSickDay falls in this period.
	 *
	 * @return \OCA\Humaniq\Payroll\SickPayResult
	 */
	private function compute(
		int $referenceWage,
		int $aangepastLoon,
		float $percentage,
		bool $yearOne,
		bool $wachtdag,
		bool $firstSickDayInPeriod,
	) {
		$calculator = new SickPayCalculator();
		$tables = TaxTables::load('nl-2026');

		$input = new SickPayInput(
			referenceWageCents: $referenceWage,
			aangepastLoonCents: $aangepastLoon,
			loondoorbetalingPercentage: $percentage,
			yearOne: $yearOne,
			wachtdag: $wachtdag,
			firstSickDayInPeriod: $firstSickDayInPeriod,
			contractHoursPerWeek: 36.0,
			fulltimeHoursPerWeek: 36.0
		);

		return $calculator->compute($input, $tables);
	}//end compute()

	/**
	 * Load the anchor fixture.
	 *
	 * @return array<string, mixed>
	 */
	private static function loadAnchorFixture(): array {
		$path = __DIR__ . '/../../fixtures/sick-pay-2026/anchor.json';
		$decoded = json_decode((string)file_get_contents($path), true);

		return is_array($decoded) === true ? $decoded : [];
	}//end loadAnchorFixture()

	/**
	 * Build a `SickPayInput` from a fixture's `input` block.
	 *
	 * @param array<string, mixed> $input The fixture's `input` block.
	 *
	 * @return SickPayInput
	 */
	private static function inputFromFixture(array $input): SickPayInput {
		return new SickPayInput(
			referenceWageCents: self::cents((float)$input['referenceWage']),
			aangepastLoonCents: self::cents((float)$input['aangepastLoon']),
			loondoorbetalingPercentage: (float)$input['percentage'],
			yearOne: (bool)$input['yearOne'],
			wachtdag: (bool)$input['wachtdag'],
			firstSickDayInPeriod: (bool)$input['firstSickDayInPeriod'],
			contractHoursPerWeek: (float)$input['contractHoursPerWeek'],
			fulltimeHoursPerWeek: (float)$input['fulltimeHoursPerWeek']
		);

	}//end inputFromFixture()

	/**
	 * Convert a euro amount to integer cents (round-half-away-from-zero).
	 *
	 * @param float $euro The euro amount.
	 *
	 * @return int
	 */
	private static function cents(float $euro): int {
		return (int)round($euro * 100);
	}//end cents()

}//end class
