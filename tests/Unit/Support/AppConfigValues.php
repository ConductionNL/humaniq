<?php

/**
 * AppConfigValues — answers an app config mock from a map of stored values.
 *
 * The settings classes read humaniq's app config through getAppValueString,
 * getAppValueInt and getAppValueBool with a default. This wires a mock so each
 * read answers the stored value for its key, or the caller's default when the
 * key is unset, the way the app config does.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Support;

use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Answers app config reads from a map of stored values.
 */
final class AppConfigValues {

	/**
	 * Make the mock answer the stored values by key.
	 *
	 * @param IAppConfig&MockObject $config The mock to wire.
	 * @param array<string, string|int|bool> $values The stored values by key.
	 *
	 * @return IAppConfig
	 */
	public static function answer(IAppConfig&MockObject $config, array $values): IAppConfig {
		$read = static fn (string $key, mixed $default): mixed => array_key_exists($key, $values) === true ? $values[$key] : $default;
		$config->method('getAppValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => (string)$read($key, $default));
		$config->method('getAppValueInt')->willReturnCallback(static fn (string $key, int $default = 0): int => (int)$read($key, $default));
		$config->method('getAppValueBool')->willReturnCallback(static fn (string $key, bool $default = false): bool => (bool)$read($key, $default));

		return $config;
	}//end answer()

}//end class
