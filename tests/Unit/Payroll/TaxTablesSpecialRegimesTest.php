<?php

/**
 * TaxTables special-regime tests.
 *
 * `specialRegime()` reads each regime of the `specialRegimes` table group:
 * the tax table colour, the insurance switch, the Zvw mode and, for early
 * retirement, the threshold in cents and the final-levy rate, and it lists
 * every leaf that is not verified so the run can refuse to pay under it.
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

use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Tests\Unit\Support\PackFixtures;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each special regime is read from the tables with its unverified leaves.
 *
 * @covers \OCA\Humaniq\Payroll\TaxTables
 */
class TaxTablesSpecialRegimesTest extends TestCase {

	/**
	 * The bundled 2026 tables load and carry the three regimes.
	 *
	 * @return void
	 */
	public function testTheBundledTablesLoadWithEveryRegime(): void {
		$tables = TaxTables::load('nl-2026');

		self::assertSame('groen', $tables->specialRegime('rvu')['taxTableColor']);
		self::assertSame('groen', $tables->specialRegime('wachtgeld')['taxTableColor']);
		self::assertSame('wit', $tables->specialRegime('politiek-ambt')['taxTableColor']);
	}//end testTheBundledTablesLoadWithEveryRegime()

	/**
	 * The early-retirement regime carries its threshold in cents and its levy rate.
	 *
	 * @return void
	 */
	public function testTheEarlyRetirementRegime(): void {
		$raw = PackFixtures::tables('nl-2026');
		$rvu = TaxTables::fromDocument($raw, 'test')->specialRegime('rvu');

		$expectedThreshold = (int)round((float)$raw['parameters']['specialRegimes']['rvu']['thresholdPerMonth']['value'] * 100);
		self::assertSame($expectedThreshold, $rvu['thresholdPerMonthCents']);
		self::assertGreaterThan(0, $rvu['thresholdPerMonthCents']);
		self::assertSame((float)$raw['parameters']['specialRegimes']['rvu']['finalLevyRate']['value'], $rvu['finalLevyRate']);
		self::assertFalse($rvu['werknemersverzekeringen']);
		self::assertSame('inhouding', $rvu['zvwMode']);
	}//end testTheEarlyRetirementRegime()

	/**
	 * A regime without threshold leaves owes no final levy, and an office holder keeps the employer's Zvw levy.
	 *
	 * @return void
	 */
	public function testRegimesWithoutAThresholdOweNoFinalLevy(): void {
		$tables = TaxTables::fromDocument(PackFixtures::tables('nl-2026'), 'test');

		$wachtgeld = $tables->specialRegime('wachtgeld');
		self::assertSame(0, $wachtgeld['thresholdPerMonthCents']);
		self::assertSame(0.0, $wachtgeld['finalLevyRate']);
		self::assertSame('inhouding', $wachtgeld['zvwMode']);
		self::assertFalse($wachtgeld['werknemersverzekeringen']);

		$office = $tables->specialRegime('politiek-ambt');
		self::assertSame('werkgeversheffing', $office['zvwMode']);
		self::assertFalse($office['werknemersverzekeringen']);
		self::assertSame(0, $office['thresholdPerMonthCents']);
	}//end testRegimesWithoutAThresholdOweNoFinalLevy()

	/**
	 * Every unverified or placeholder leaf is listed by path; a verified regime lists none.
	 *
	 * @return void
	 */
	public function testUnverifiedLeavesAreListed(): void {
		$raw = PackFixtures::tables('nl-2026');
		$raw['parameters']['specialRegimes']['wachtgeld'] = [
			'taxTableColor' => ['value' => 'groen', 'source' => 's', 'verified' => true],
			'werknemersverzekeringen' => ['value' => false, 'source' => 's', 'verified' => true],
			'zvwMode' => ['value' => 'inhouding', 'source' => 's', 'verified' => true, 'placeholder' => true, 'checkAgainst' => 'c'],
		];
		$raw['parameters']['specialRegimes']['politiek-ambt'] = [
			'taxTableColor' => ['value' => 'wit', 'source' => 's', 'verified' => true],
			'werknemersverzekeringen' => ['value' => false, 'source' => 's', 'verified' => true],
			'zvwMode' => ['value' => 'werkgeversheffing', 'source' => 's', 'verified' => true],
		];
		$tables = TaxTables::fromDocument($raw, 'test');

		self::assertSame(['specialRegimes.wachtgeld.zvwMode'], $tables->specialRegime('wachtgeld')['unverified']);
		self::assertSame([], $tables->specialRegime('politiek-ambt')['unverified']);
		self::assertContains('specialRegimes.rvu.thresholdPerMonth', $tables->specialRegime('rvu')['unverified']);
	}//end testUnverifiedLeavesAreListed()

	/**
	 * An unknown regime is refused with its name.
	 *
	 * @return void
	 */
	public function testAnUnknownRegimeIsNamed(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('specialRegimes.onbekend');

		TaxTables::fromDocument(PackFixtures::tables('nl-2026'), 'test')->specialRegime('onbekend');
	}//end testAnUnknownRegimeIsNamed()

	/**
	 * A regime that names a threshold but no rate is refused rather than levied at zero.
	 *
	 * @return void
	 */
	public function testAThresholdWithoutARateIsRefused(): void {
		$raw = PackFixtures::tables('nl-2026');
		unset($raw['parameters']['specialRegimes']['rvu']['finalLevyRate']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('specialRegimes.rvu.finalLevyRate');

		TaxTables::fromDocument($raw, 'test')->specialRegime('rvu');
	}//end testAThresholdWithoutARateIsRefused()

}//end class
