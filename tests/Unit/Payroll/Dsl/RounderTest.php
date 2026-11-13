<?php

/**
 * Unit tests for Rounder — the closed rounding modifier of the pack DSL.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll\Dsl
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll\Dsl;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\Rounder;
use PHPUnit\Framework\TestCase;

/**
 * Every mode in every unit, and the refusal of an undeclared mode or unit.
 *
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
 */
class RounderTest extends TestCase {

	/**
	 * No rounding declared leaves the value untouched, type included.
	 *
	 * @return void
	 */
	public function testANullSpecReturnsTheValueUnchanged(): void {
		$rounder = new Rounder();

		$this->assertSame(1234.5678, $rounder->apply(1234.5678, null));
		$this->assertSame(7, $rounder->apply(7, null));

	}//end testANullSpecReturnsTheValueUnchanged()

	/**
	 * Euro rounding works on whole euros and returns cents.
	 *
	 * @return void
	 */
	public function testEuroRoundingInEachMode(): void {
		$rounder = new Rounder();

		$this->assertSame(12300, $rounder->apply(12345, ['mode' => 'floor', 'unit' => 'euro']));
		$this->assertSame(12400, $rounder->apply(12301, ['mode' => 'ceil', 'unit' => 'euro']));
		$this->assertSame(12300, $rounder->apply(12349.99, ['mode' => 'nearest', 'unit' => 'euro']));
		$this->assertSame(12400, $rounder->apply(12350, ['mode' => 'nearest', 'unit' => 'euro']));

	}//end testEuroRoundingInEachMode()

	/**
	 * Cent rounding first squashes float noise to four decimals, so a value a
	 * hair above a whole cent does not ceil to the next cent.
	 *
	 * @return void
	 */
	public function testCentRoundingInEachMode(): void {
		$rounder = new Rounder();

		$this->assertSame(10, $rounder->apply(10.99, ['mode' => 'floor', 'unit' => 'cent']));
		$this->assertSame(11, $rounder->apply(10.01, ['mode' => 'ceil', 'unit' => 'cent']));
		$this->assertSame(10, $rounder->apply(10.000001, ['mode' => 'ceil', 'unit' => 'cent']));
		$this->assertSame(11, $rounder->apply(10.5, ['mode' => 'nearest', 'unit' => 'cent']));

	}//end testCentRoundingInEachMode()

	/**
	 * Decimal rounding keeps a float at the declared precision.
	 *
	 * @return void
	 */
	public function testDecimalRoundingInEachMode(): void {
		$rounder = new Rounder();

		$this->assertSame(1.234, $rounder->apply(1.23456, ['mode' => 'floor', 'unit' => 'decimals', 'decimals' => 3]));
		$this->assertSame(1.235, $rounder->apply(1.23411, ['mode' => 'ceil', 'unit' => 'decimals', 'decimals' => 3]));
		$this->assertSame(1.24, $rounder->apply(1.235, ['mode' => 'nearest', 'unit' => 'decimals', 'decimals' => 2]));
		$this->assertSame(2.0, $rounder->apply(1.5, ['mode' => 'nearest', 'unit' => 'decimals']));

	}//end testDecimalRoundingInEachMode()

	/**
	 * An undeclared mode is refused, naming the allowed modes.
	 *
	 * @return void
	 */
	public function testAnUnknownModeIsRejected(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessage('Pack: onbekende afrondingsmodus "bankers" (toegestaan: floor, ceil, nearest).');

		(new Rounder())->apply(1, ['mode' => 'bankers', 'unit' => 'cent']);

	}//end testAnUnknownModeIsRejected()

	/**
	 * An undeclared unit is refused, naming the allowed units.
	 *
	 * @return void
	 */
	public function testAnUnknownUnitIsRejected(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessage('Pack: onbekende afrondingseenheid "dollar" (toegestaan: cent, euro, decimals).');

		(new Rounder())->apply(1, ['mode' => 'floor', 'unit' => 'dollar']);

	}//end testAnUnknownUnitIsRejected()

}//end class
