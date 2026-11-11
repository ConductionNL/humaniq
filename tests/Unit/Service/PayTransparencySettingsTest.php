<?php

/**
 * Pay transparency settings test
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
 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\AppConfigValues;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The smallest group the pay transparency report shows figures of.
 *
 * @covers \OCA\Humaniq\Service\SettingsService
 * @uses   \OCA\Humaniq\Tests\Unit\Support\AppConfigValues
 */
class PayTransparencySettingsTest extends TestCase {

	/**
	 * The minimum group defaults to 5 and is at least 1.
	 *
	 * @return void
	 */
	public function testTheMinimumGroup(): void {
		self::assertSame(5, $this->settings([])->getPayTransparencyMinimumGroup());
		self::assertSame(10, $this->settings(['pay_transparency_minimum_group' => 10])->getPayTransparencyMinimumGroup());
		self::assertSame(1, $this->settings(['pay_transparency_minimum_group' => 0])->getPayTransparencyMinimumGroup());
	}//end testTheMinimumGroup()

	/**
	 * The settings over an app config holding the given values.
	 *
	 * @param array<string, int> $values The stored app config values by key.
	 *
	 * @return SettingsService
	 */
	private function settings(array $values): SettingsService {
		$config = AppConfigValues::answer($this->createMock(IAppConfig::class), $values);

		return new SettingsService($config, $this->createMock(IAppManager::class), $this->createMock(ContainerInterface::class), new NullLogger());
	}//end settings()

}//end class
