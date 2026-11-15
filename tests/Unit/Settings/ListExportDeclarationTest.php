<?php

/**
 * Declaration tests for reporting-full-list-export (REQ-HLE-002).
 *
 * Every humaniq schema may be exported as a whole list, except the two that
 * hold data nobody should take out in bulk: SickLeaveCase (health data) and
 * DsrRequest (requests under the AVG). OpenRegister's export right still
 * decides per user who may export.
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
 * @spec openspec/changes/reporting-full-list-export/specs/hr-list-export/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing Guards declarative register fragments, not a PHP class.
 */
class ListExportDeclarationTest extends TestCase {

	/**
	 * The fragments whose schemas must never be exportable.
	 *
	 * @var list<string>
	 */
	private const NEVER = ['hr-verzuim.json', 'hr-dsr.json'];

	/**
	 * Every fragment's schemas, by file name.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private static function fragments(): array {
		$schemas = [];
		foreach ((array)glob(__DIR__ . '/../../../lib/Settings/register.d/*.json') as $file) {
			$fragment = json_decode((string)file_get_contents((string)$file), true);
			self::assertIsArray($fragment, (string)$file);
			$schemas[basename((string)$file)] = ($fragment['components']['schemas'] ?? []);
		}

		return $schemas;
	}//end fragments()

	/**
	 * No schema in the sick leave or AVG request fragments is exportable.
	 *
	 * @return void
	 */
	public function testSickLeaveAndDsrSchemasAreNeverExportable(): void {
		$fragments = self::fragments();
		foreach (self::NEVER as $file) {
			self::assertNotSame([], $fragments[$file], $file . ' declares no schema');
			foreach ($fragments[$file] as $name => $schema) {
				self::assertNotTrue(($schema['configuration']['exportable'] ?? null), $file . ' ' . $name . ' must not be exportable');
				self::assertNotTrue(($schema['exportable'] ?? null), $file . ' ' . $name . ' must not be exportable');
			}
		}
	}//end testSickLeaveAndDsrSchemasAreNeverExportable()

	/**
	 * Every other schema is exportable, so its list can be exported whole.
	 *
	 * @return void
	 */
	public function testEveryOtherSchemaIsExportable(): void {
		$count = 0;
		foreach (self::fragments() as $file => $schemas) {
			if (in_array($file, self::NEVER, true) === true) {
				continue;
			}

			foreach ($schemas as $name => $schema) {
				self::assertTrue(($schema['configuration']['exportable'] ?? null), $file . ' ' . $name . ' is not exportable');
				$count++;
			}
		}

		self::assertGreaterThan(90, $count);
	}//end testEveryOtherSchemaIsExportable()
}//end class
