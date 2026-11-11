<?php

/**
 * Register prefill settings test
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
 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
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
 * Which integriq source a base register is called through.
 *
 * @covers \OCA\Humaniq\Service\SettingsService
 */
class RegisterPrefillSettingsTest extends TestCase {

	/**
	 * Each register reads its own trimmed `prefill_<register>_source` key, empty when unset.
	 *
	 * @return void
	 */
	public function testEachRegisterReadsItsOwnSource(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($app === 'humaniq' ? (['prefill_rdw_source' => ' src-rdw '][$key] ?? $default) : $default)
		);
		$settings = $this->settings($config);

		self::assertSame('src-rdw', $settings->getRegisterPrefillSource('rdw'));
		self::assertSame('', $settings->getRegisterPrefillSource('brp'));
	}//end testEachRegisterReadsItsOwnSource()

	/**
	 * The settings over the given app config.
	 *
	 * @param IAppConfig $config The app config.
	 *
	 * @return SettingsService
	 */
	private function settings(IAppConfig $config): SettingsService {
		return new SettingsService($config, $this->createMock(IAppManager::class), $this->createMock(ContainerInterface::class), new NullLogger());
	}//end settings()

}//end class
