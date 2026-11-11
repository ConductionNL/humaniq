<?php

/**
 * ShillinqPayables test
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
 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\ShillinqPayables;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * shillinq's payables are reached only while OpenRegister is installed.
 *
 * @covers \OCA\Humaniq\Service\ShillinqPayables
 * @uses   \OCA\Humaniq\Service\SettingsService
 * @uses   \OCA\Humaniq\Service\OpenRegisterAvailability
 */
class ShillinqPayablesTest extends TestCase {

	/**
	 * Without OpenRegister the payables are unavailable and OpenRegister is never asked.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterThePayablesAreUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		self::assertFalse($this->payables(['shillinq'], $container)->available());
	}//end testWithoutOpenRegisterThePayablesAreUnavailable()

	/**
	 * With OpenRegister and shillinq a readable payable schema makes them available.
	 *
	 * @return void
	 */
	public function testWithOpenRegisterAndShillinqThePayablesAreAvailable(): void {
		$objects = new class {
			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = []): array {
				return [];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);

		self::assertTrue($this->payables(['shillinq', 'openregister'], $container)->available());
	}//end testWithOpenRegisterAndShillinqThePayablesAreAvailable()

	/**
	 * Creating a payable without OpenRegister names the app to install.
	 *
	 * @return void
	 */
	public function testCreatingWithoutOpenRegisterNamesTheMissingApp(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('humaniq requires the OpenRegister app, which is not installed on this instance.');

		$this->payables(['shillinq'], $this->createMock(ContainerInterface::class))->createOrAdopt(['vendorId' => 'v', 'invoiceNumber' => 'i']);
	}//end testCreatingWithoutOpenRegisterNamesTheMissingApp()

	/**
	 * The payables on an instance with the given apps installed.
	 *
	 * @param list<string> $installed The installed app ids.
	 * @param ContainerInterface $container The container.
	 *
	 * @return ShillinqPayables
	 */
	private function payables(array $installed, ContainerInterface $container): ShillinqPayables {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => in_array($app, $installed, true));
		$settings = new SettingsService($this->createMock(IAppConfig::class), $apps, $this->createMock(ContainerInterface::class), new NullLogger());

		return new ShillinqPayables(container: $container, appManager: $apps, settingsService: $settings, logger: new NullLogger());
	}//end payables()

}//end class
