<?php

/**
 * Mock register access rules
 *
 * Loading demo data force-imports humaniq_mock_register.json, so a register
 * entry or schema definition in it replaces the real one. Its access rules
 * must therefore be the real register's: the register block, every schema
 * block and every property block.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Settings
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
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Service\RegisterConfigReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MockRegisterAuthorizationTest extends TestCase {

	/**
	 * The mock descriptor.
	 *
	 * @return array<string, mixed>
	 */
	private static function mock(): array {
		return json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/humaniq_mock_register.json'), true);
	}//end mock()

	/**
	 * The assembled real register.
	 *
	 * @return array<string, mixed>
	 */
	private static function real(): array {
		return ((new RegisterConfigReader(new NullLogger()))->read()['data'] ?? []);
	}//end real()

	public function testTheMockRegisterCarriesTheRealRegistersRules(): void {
		$real = self::real()['components']['registers']['humaniq'];
		$mock = self::mock()['components']['registers']['humaniq'];

		self::assertNotEmpty($real['authorization']);
		self::assertSame($real['authorization'], ($mock['authorization'] ?? null));
		self::assertSame($real['version'], $mock['version'], 'regenerate the mock register after a register change');
	}//end testTheMockRegisterCarriesTheRealRegistersRules()

	public function testEveryMockSchemaCarriesTheRealSchemasRules(): void {
		$real = self::real()['components']['schemas'];
		$mock = (self::mock()['components']['schemas'] ?? []);
		self::assertNotEmpty($mock);

		foreach ($mock as $name => $schema) {
			self::assertArrayHasKey($name, $real, $name . ' is not a real schema');
			self::assertSame(($real[$name]['authorization'] ?? null), ($schema['authorization'] ?? null), $name);
			foreach (($real[$name]['properties'] ?? []) as $property => $definition) {
				self::assertSame(($definition['authorization'] ?? null), ($schema['properties'][$property]['authorization'] ?? null), $name . '.' . $property);
			}
		}
	}//end testEveryMockSchemaCarriesTheRealSchemasRules()

}//end class
