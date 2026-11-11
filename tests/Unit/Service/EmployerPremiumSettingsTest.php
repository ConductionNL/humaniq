<?php

/**
 * Employer premium settings test
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
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\EmployerPremiumSettings;
use OCA\Humaniq\Tests\Unit\Support\AppConfigValues;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * What the employer's Aof tariff and Whk percentage read from app config.
 *
 * @covers \OCA\Humaniq\Service\EmployerPremiumSettings
 * @uses   \OCA\Humaniq\Tests\Unit\Support\AppConfigValues
 */
class EmployerPremiumSettingsTest extends TestCase {

	/**
	 * Only `hoog` (any case, trimmed) is the high Aof tariff; anything else is `laag`.
	 *
	 * @return void
	 */
	public function testTheAofTariff(): void {
		self::assertSame('laag', $this->settings([])->getPayrollAofTariff());
		self::assertSame('hoog', $this->settings(['payroll_aof_tariff' => ' HOOG '])->getPayrollAofTariff());
		self::assertSame('laag', $this->settings(['payroll_aof_tariff' => 'middel'])->getPayrollAofTariff());
	}//end testTheAofTariff()

	/**
	 * The Whk percentage falls back to the tables' default unless it is a non-negative number.
	 *
	 * @return void
	 */
	public function testTheWhkPercentage(): void {
		self::assertSame(1.52, $this->settings([])->getPayrollWhkPercentage(1.52));
		self::assertSame(0.8, $this->settings(['payroll_whk_percentage' => ' 0.8 '])->getPayrollWhkPercentage(1.52));
		self::assertSame(0.0, $this->settings(['payroll_whk_percentage' => '0'])->getPayrollWhkPercentage(1.52));
		self::assertSame(1.52, $this->settings(['payroll_whk_percentage' => '-1'])->getPayrollWhkPercentage(1.52));
		self::assertSame(1.52, $this->settings(['payroll_whk_percentage' => 'n/a'])->getPayrollWhkPercentage(1.52));
	}//end testTheWhkPercentage()

	/**
	 * The settings over an app config holding the given values.
	 *
	 * @param array<string, string> $values The stored app config values by key.
	 *
	 * @return EmployerPremiumSettings
	 */
	private function settings(array $values): EmployerPremiumSettings {
		$config = AppConfigValues::answer($this->createMock(IAppConfig::class), $values);

		return new EmployerPremiumSettings($config);
	}//end settings()

}//end class
