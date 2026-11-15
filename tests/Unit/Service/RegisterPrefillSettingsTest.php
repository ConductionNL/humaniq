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

use OCA\Humaniq\Service\RegisterPrefillSettings;
use OCA\Humaniq\Tests\Unit\Support\AppConfigValues;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Which integriq source a base register is called through.
 *
 * @covers \OCA\Humaniq\Service\RegisterPrefillSettings
 * @uses   \OCA\Humaniq\Tests\Unit\Support\AppConfigValues
 */
class RegisterPrefillSettingsTest extends TestCase {

	/**
	 * Each register reads its own trimmed `prefill_<register>_source` key, empty when unset.
	 *
	 * @return void
	 */
	public function testEachRegisterReadsItsOwnSource(): void {
		$settings = $this->settings(AppConfigValues::answer($this->createMock(IAppConfig::class), ['prefill_rdw_source' => ' src-rdw ']));

		self::assertSame('src-rdw', $settings->getRegisterPrefillSource('rdw'));
		self::assertSame('', $settings->getRegisterPrefillSource('brp'));
	}//end testEachRegisterReadsItsOwnSource()

	/**
	 * The settings over the given app config.
	 *
	 * @param IAppConfig $config The app config.
	 *
	 * @return RegisterPrefillSettings
	 */
	private function settings(IAppConfig $config): RegisterPrefillSettings {
		return new RegisterPrefillSettings($config);
	}//end settings()

}//end class
