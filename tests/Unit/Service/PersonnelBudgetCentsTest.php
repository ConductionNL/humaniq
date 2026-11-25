<?php

/**
 * Humaniq PersonnelBudgetCentsTest
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
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\PersonnelBudgetTotals;
use PHPUnit\Framework\TestCase;

/**
 * Budget amounts round to the same cents on every PHP version.
 *
 * @covers \OCA\Humaniq\Service\PersonnelBudgetTotals
 */
class PersonnelBudgetCentsTest extends TestCase {

	/**
	 * A float sum a hair under a half cent rounds the half cent up.
	 *
	 * @return void
	 */
	public function testFloatNoiseUnderAHalfCentRoundsUp(): void {
		$totals = new PersonnelBudgetTotals();

		// 51048.075 as a float sum arrives as 51048.07499999999.
		self::assertSame(51048.08, $totals->cents(amount: 51048.075 - 1e-11));
		self::assertSame(16906.15, $totals->cents(amount: 16906.145 - 1e-11));
	}//end testFloatNoiseUnderAHalfCentRoundsUp()

	/**
	 * Real fractions of a cent still round to the nearest cent.
	 *
	 * @return void
	 */
	public function testRealFractionsRoundToTheNearestCent(): void {
		$totals = new PersonnelBudgetTotals();

		self::assertSame(10.07, $totals->cents(amount: 10.0749));
		self::assertSame(10.08, $totals->cents(amount: 10.0751));
		self::assertSame(-3.13, $totals->cents(amount: -3.125));
		self::assertSame(0.0, $totals->cents(amount: 0.0));
	}//end testRealFractionsRoundToTheNearestCent()

	/**
	 * A line's surcharges and its roll-up carry the rounded cents.
	 *
	 * @return void
	 */
	public function testSurchargesAndRollUpUseTheSameCents(): void {
		$totals = new PersonnelBudgetTotals(holidayAllowancePercentage: 0.0);
		$line = $totals->withSurcharges(line: ['orgUnitId' => 'u', 'unitName' => 'U', 'occupiedCost' => (51048.075 - 1e-11), 'vacantCost' => 0.0, 'unpriced' => false, 'fteByMonth' => []], chargesRatio: 0.0);

		self::assertSame(51048.08, $line['occupiedCost']);
		self::assertSame(51048.08, $totals->rollUp(lines: [$line], key: 'orgUnitId')['u']['occupiedCost']);
	}//end testSurchargesAndRollUpUseTheSameCents()

}//end class
