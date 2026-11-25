<?php

/**
 * Unit tests for SickPayFloor.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\SickPayFloor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Humaniq\Standards\Checks\SickPayFloor
 */
class SickPayFloorTest extends TestCase {

	/**
	 * The floor is the statutory share of the reference wage, raised to the
	 * minimum-wage floor in year one only.
	 *
	 * @return void
	 */
	public function testTheFloorTakesTheYearOneMinimumWageWhenHigher(): void {
		$floor = new SickPayFloor();
		$payslip = ['sickPayReferenceWage' => 3000];

		self::assertSame(210000, $floor->cents($payslip, []));
		self::assertSame(240000, $floor->cents($payslip, ['statutoryPercentage' => 80]));
		self::assertSame(220000, $floor->cents($payslip + ['sickPayYearOne' => true, 'sickPayMinimumWageFloor' => 2200], []));
		self::assertSame(210000, $floor->cents($payslip + ['sickPayYearOne' => true, 'sickPayMinimumWageFloor' => 2000], []));
		self::assertSame(210000, $floor->cents($payslip + ['sickPayYearOne' => false, 'sickPayMinimumWageFloor' => 2200], []));
	}//end testTheFloorTakesTheYearOneMinimumWageWhenHigher()

	/**
	 * The table inputs: null outside a sick case or without parameters,
	 * figures not recorded when a wage is not numeric, else the shortfall.
	 *
	 * @return void
	 */
	public function testTheInputsNameTheShortfall(): void {
		$floor = new SickPayFloor();
		$payslip = ['sickLeaveCaseId' => 'sick-1', 'sickPayReferenceWage' => 3000, 'doorbetaaldLoon' => 2000];

		self::assertSame(['figuresRecorded' => true, 'shortfallCents' => 10000], $floor->inputs($payslip, []));
		self::assertSame(['figuresRecorded' => false, 'shortfallCents' => 0], $floor->inputs(['doorbetaaldLoon' => 'x'] + $payslip, []));
		self::assertNull($floor->inputs($payslip, null));
		self::assertNull($floor->inputs(['sickLeaveCaseId' => ' '] + $payslip, []));
	}//end testTheInputsNameTheShortfall()
}//end class
