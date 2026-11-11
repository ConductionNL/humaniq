<?php

/**
 * Expense settings test
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
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\ExpenseSettings;
use OCA\Humaniq\Tests\Unit\Support\AppConfigValues;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * What the expense settings read from app config.
 *
 * @covers \OCA\Humaniq\Service\ExpenseSettings
 * @uses   \OCA\Humaniq\Tests\Unit\Support\AppConfigValues
 */
class ExpenseSettingsTest extends TestCase {

	/**
	 * Only `payroll` routes a claim through payroll; anything else is direct.
	 *
	 * @return void
	 */
	public function testTheReimbursementRoute(): void {
		self::assertSame('direct', $this->settings([])->getExpenseReimbursementRoute());
		self::assertSame('payroll', $this->settings(['expense_reimbursement_route' => 'payroll'])->getExpenseReimbursementRoute());
		self::assertSame('direct', $this->settings(['expense_reimbursement_route' => 'Payroll'])->getExpenseReimbursementRoute());
	}//end testTheReimbursementRoute()

	/**
	 * The mileage rate takes a comma or a dot and is null unless positive.
	 *
	 * @return void
	 */
	public function testTheMileageRate(): void {
		self::assertNull($this->settings([])->getMileageRatePerKm());
		self::assertSame(0.3, $this->settings(['mileage_rate_per_km' => ' 0,30 '])->getMileageRatePerKm());
		self::assertSame(0.25, $this->settings(['mileage_rate_per_km' => '0.25'])->getMileageRatePerKm());
		self::assertNull($this->settings(['mileage_rate_per_km' => '0'])->getMileageRatePerKm());
		self::assertNull($this->settings(['mileage_rate_per_km' => '-1'])->getMileageRatePerKm());
		self::assertNull($this->settings(['mileage_rate_per_km' => 'abc'])->getMileageRatePerKm());
	}//end testTheMileageRate()

	/**
	 * The route planner source and endpoint are trimmed and default to empty.
	 *
	 * @return void
	 */
	public function testTheRouteDistanceSource(): void {
		self::assertSame(['source' => '', 'endpoint' => ''], $this->settings([])->getRouteDistanceSource());
		self::assertSame(
			['source' => 'src-1', 'endpoint' => '/route'],
			$this->settings(['route_distance_source' => ' src-1 ', 'route_distance_endpoint' => ' /route '])->getRouteDistanceSource()
		);
	}//end testTheRouteDistanceSource()

	/**
	 * The settings over an app config holding the given values.
	 *
	 * @param array<string, string> $values The stored app config values by key.
	 *
	 * @return ExpenseSettings
	 */
	private function settings(array $values): ExpenseSettings {
		return new ExpenseSettings(AppConfigValues::answer($this->createMock(IAppConfig::class), $values));
	}//end settings()

}//end class
