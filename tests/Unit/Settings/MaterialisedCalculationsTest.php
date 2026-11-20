<?php

/**
 * Materialised calculation declaration tests.
 *
 * @category Tests
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
 * @spec exclude Register declaration check for OpenRegister's calculation validator
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * OpenRegister ignores a schema's whole calculation annotation when one
 * materialised calculation has no property of the same name.
 */
class MaterialisedCalculationsTest extends TestCase {

	/**
	 * Every materialised calculation names a declared property of its schema.
	 *
	 * @return void
	 */
	public function testEveryMaterialisedCalculationHasItsProperty(): void {
		$missing = [];
		foreach ($this->schemaNames() as $schemaName) {
			$schema = RegisterSchemaValidator::schema($schemaName);
			$calculations = ($schema['configuration']['x-openregister-calculations'] ?? []);
			foreach ($calculations as $name => $calculation) {
				if (($calculation['materialise'] ?? false) === true && isset($schema['properties'][$name]) === false) {
					$missing[] = $schemaName . '.' . $name;
				}
			}
		}

		self::assertSame([], $missing);
	}//end testEveryMaterialisedCalculationHasItsProperty()

	/**
	 * The schemas declared (with a slug) in the register fragments.
	 *
	 * @return array<int, string>
	 */
	private function schemaNames(): array {
		$names = [];
		foreach (glob(dirname(__DIR__, 3) . '/lib/Settings/register.d/*.json') as $file) {
			$fragment = json_decode((string)file_get_contents($file), true);
			foreach (($fragment['components']['schemas'] ?? []) as $name => $schema) {
				if (isset($schema['slug']) === true) {
					$names[] = (string)$name;
				}
			}
		}

		return array_values(array_unique($names));
	}//end schemaNames()
}//end class
