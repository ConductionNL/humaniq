<?php

/**
 * OpenRegister availability test
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * OpenRegister is available exactly when the openregister app is installed.
 *
 * @covers \OCA\Humaniq\Service\SettingsService
 */
class OpenRegisterAvailabilityTest extends TestCase {

	/**
	 * The answer follows the app manager's answer for `openregister`.
	 *
	 * @return void
	 */
	public function testItAsksTheAppManagerForOpenRegister(): void {
		foreach ([true, false] as $installed) {
			$apps = $this->createMock(IAppManager::class);
			$apps->expects($this->once())->method('isInstalled')->with('openregister')->willReturn($installed);

			self::assertSame($installed, (new SettingsService($this->createMock(IAppConfig::class), $apps, $this->createMock(ContainerInterface::class), new NullLogger()))->isOpenRegisterAvailable());
		}
	}//end testItAsksTheAppManagerForOpenRegister()

}//end class
