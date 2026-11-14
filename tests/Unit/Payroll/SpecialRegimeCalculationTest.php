<?php

/**
 * Special-regime calculation tests.
 *
 * The Zvw withholding mode and the early-retirement final levy travel from
 * the calculation input through the pack to the result: the withheld Zvw
 * lowers net and is no employer cost, the final levy is an employer cost on
 * the excess only, and an ordinary wage's snapshot stays byte-identical.
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

use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\CalculationInputMapper;
use OCA\Humaniq\Payroll\CalculationLevies;
use OCA\Humaniq\Payroll\CalculationWage;
use OCA\Humaniq\Payroll\InsuranceTariffs;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Payroll\WageTaxElection;
use PHPUnit\Framework\TestCase;

/**
 * The Zvw mode and the RVU final levy, end to end through the pack.
 *
 * No coverage metadata: the path runs the whole pack interpreter.
 */
class SpecialRegimeCalculationTest extends TestCase {

	/**
	 * A benefit input.
	 *
	 * @param int    $grossCents The gross benefit in cents.
	 * @param string $zvwMode    The Zvw mode.
	 * @param int    $threshold  The final-levy threshold in cents.
	 * @param float  $rate       The final-levy rate.
	 *
	 * @return CalculationInput
	 */
	private static function input(int $grossCents, string $zvwMode = 'werkgeversheffing', int $threshold = 0, float $rate = 0.0): CalculationInput {
		return new CalculationInput(
			wage: new CalculationWage(grossMonthlySalaryCents: $grossCents, dateOfBirth: '1961-03-15', period: '2026-07'),
			levies: new CalculationLevies(
				wageTax: new WageTaxElection(taxTableColor: 'groen', loonheffingskortingToegepast: true),
				insurance: new InsuranceTariffs(awfTariff: 'low', aofTariff: 'laag', whkPercentage: 1.52, verzekeringsplichtig: false, zvwMode: $zvwMode),
				rvuThresholdCents: $threshold,
				rvuFinalLevyRate: $rate
			)
		);
	}//end input()

	/**
	 * The withheld Zvw is 4.85% of the base, lowers net by exactly that and is no employer cost.
	 *
	 * @return void
	 */
	public function testWithheldZvwLowersNetAndIsNoEmployerCost(): void {
		$tables = TaxTables::load('nl-2026');
		$calculator = new PayrollCalculator();

		$levy = $calculator->calculate(self::input(200000), $tables);
		$withheld = $calculator->calculate(self::input(200000, 'inhouding'), $tables);

		self::assertSame('werkgeversheffing', $levy->zvwMode);
		self::assertSame(12200, $levy->zvwCents);
		self::assertSame(12200, $levy->employerChargesCents);

		self::assertSame('inhouding', $withheld->zvwMode);
		self::assertSame(4.85, $withheld->zvwRate);
		self::assertSame(9700, $withheld->zvwCents);
		self::assertSame(0, $withheld->employerChargesCents);
		self::assertSame($levy->loonheffingCents, $withheld->loonheffingCents);
		self::assertSame(($levy->nettoPayCents - 9700), $withheld->nettoPayCents);
	}//end testWithheldZvwLowersNetAndIsNoEmployerCost()

	/**
	 * The final levy is the rate times the excess, an employer cost that leaves net unchanged; below the threshold it is zero.
	 *
	 * @return void
	 */
	public function testTheFinalLevyFallsOnTheExcessOnly(): void {
		$tables = TaxTables::load('nl-2026');
		$calculator = new PayrollCalculator();

		$plain = $calculator->calculate(self::input(300000, 'inhouding'), $tables);
		$rvu = $calculator->calculate(self::input(300000, 'inhouding', 228600, 52.0), $tables);
		$below = $calculator->calculate(self::input(200000, 'inhouding', 228600, 52.0), $tables);

		self::assertSame(0, $plain->rvuFinalLevyCents);
		self::assertSame(37128, $rvu->rvuFinalLevyCents);
		self::assertSame(($plain->employerChargesCents + 37128), $rvu->employerChargesCents);
		self::assertSame($plain->nettoPayCents, $rvu->nettoPayCents);
		self::assertSame(0, $below->rvuFinalLevyCents);
	}//end testTheFinalLevyFallsOnTheExcessOnly()

	/**
	 * An ordinary wage's snapshot carries none of the new keys; a benefit's carries them and round-trips.
	 *
	 * @return void
	 */
	public function testTheSnapshotCarriesTheRegimeOnlyWhenSet(): void {
		$ordinary = self::input(200000)->toArray();
		self::assertArrayNotHasKey('zvwMode', $ordinary);
		self::assertArrayNotHasKey('rvuThresholdCents', $ordinary);
		self::assertArrayNotHasKey('rvuFinalLevyRate', $ordinary);
		self::assertCount(11, $ordinary);

		$benefit = self::input(300000, 'inhouding', 228600, 52.0);
		self::assertSame('inhouding', $benefit->toArray()['zvwMode']);
		self::assertSame(228600, $benefit->toArray()['rvuThresholdCents']);

		$back = CalculationInput::fromCanonicalJson($benefit->toCanonicalJson());
		self::assertSame('inhouding', $back->zvwMode);
		self::assertSame(228600, $back->rvuThresholdCents);
		self::assertSame(52.0, $back->rvuFinalLevyRate);
		self::assertSame($benefit->toCanonicalJson(), $back->toCanonicalJson());
	}//end testTheSnapshotCarriesTheRegimeOnlyWhenSet()

	/**
	 * An unknown Zvw mode falls back to the employer's levy and a negative threshold to none.
	 *
	 * @return void
	 */
	public function testUnknownModesAndNegativeThresholdsAreNormalised(): void {
		$input = self::input(200000, 'iets-anders', -5);

		self::assertSame('werkgeversheffing', $input->zvwMode);
		self::assertSame(0, $input->rvuThresholdCents);

		$packInputs = (new CalculationInputMapper())->toPackInputs(self::input(300000, 'inhouding', 228600, 52.0));
		self::assertSame('inhouding', $packInputs['zvwMode']);
		self::assertSame(228600, $packInputs['rvuThreshold']);
		self::assertSame(52.0, $packInputs['rvuFinalLevyRate']);
	}//end testUnknownModesAndNegativeThresholdsAreNormalised()

}//end class
