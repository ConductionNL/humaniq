<?php

/**
 * The employee page's Own fields card shows exactly what the app does not ship.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Manifest
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
 * @spec openspec/changes/employee-custom-fields-through-the-register/tasks.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Manifest;

use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped register fragments and the EmployeeDetail page, so a newly
 * shipped Employee property that is not added to the exclude list fails here
 * instead of showing twice on the page.
 *
 * @coversNothing
 */
final class EmployeeCustomFieldsManifestTest extends TestCase {

	/**
	 * The shipped register files that declare Employee properties.
	 *
	 * @var list<string>
	 */
	private const SHIPPED = ['lib/Settings/register.d/hr-cost-rate.json', 'lib/Settings/register.d/hr-objects.json'];

	/**
	 * Every Employee property the app ships, in declaration order, across all shipped register files.
	 *
	 * @return list<string> The property names.
	 */
	private static function shippedProperties(): array {
		$root  = dirname(__DIR__, 3);
		$names = [];
		foreach (glob($root.'/lib/Settings/register.d/*.json') as $file) {
			$properties = (json_decode((string) file_get_contents($file), true)['components']['schemas']['Employee']['properties'] ?? []);
			foreach (array_keys($properties) as $name) {
				$names[] = (string) $name;
			}
		}

		return array_values(array_unique($names));
	}//end shippedProperties()

	/**
	 * The EmployeeDetail widgets, by id.
	 *
	 * @return array<string, array<string, mixed>> The widgets.
	 */
	private static function widgets(): array {
		$manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/src/manifest.d/hr-objects.json'), true);
		foreach ($manifest['pages'] as $page) {
			if ($page['id'] === 'EmployeeDetail') {
				return array_column($page['config']['widgets'], null, 'id');
			}
		}

		return [];
	}//end widgets()

	/**
	 * Own fields excludes every shipped property and nothing else.
	 *
	 * @return void
	 */
	public function testOwnFieldsExcludesExactlyTheShippedProperties(): void {
		$own = self::widgets()['own-fields'];

		$this->assertSame('data', $own['type']);
		$this->assertSame(self::shippedProperties(), $own['content']['exclude']);
		$this->assertArrayNotHasKey('include', $own['content']);
	}//end testOwnFieldsExcludesExactlyTheShippedProperties()

	/**
	 * The two shipped files are where Employee comes from; a third file declaring it must be listed here too.
	 *
	 * @return void
	 */
	public function testTheShippedPropertiesComeFromTheKnownFiles(): void {
		$root  = dirname(__DIR__, 3);
		$files = [];
		foreach (glob($root.'/lib/Settings/register.d/*.json') as $file) {
			if (isset(json_decode((string) file_get_contents($file), true)['components']['schemas']['Employee']) === true) {
				$files[] = substr($file, strlen($root) + 1);
			}
		}

		sort($files);
		$this->assertSame(self::SHIPPED, $files);
		$this->assertContains('grossMonthlySalary', self::shippedProperties());
		$this->assertContains('hourlyCostRateOverrideCents', self::shippedProperties());
	}//end testTheShippedPropertiesComeFromTheKnownFiles()

	/**
	 * Person and Employment & compliance name their shipped fields, so an added field shows only under Own fields.
	 *
	 * @return void
	 */
	public function testTheOtherDataCardsNameTheirFields(): void {
		$widgets  = self::widgets();
		$shipped  = self::shippedProperties();
		$person   = $widgets['data']['content']['include'];
		$employed = $widgets['data-2']['content']['include'];

		$this->assertArrayNotHasKey('exclude', $widgets['data-2']['content']);
		$this->assertSame([], array_values(array_intersect($person, $employed)), 'no field shows in both cards');
		$this->assertSame($shipped, array_values(array_filter($shipped, static fn (string $name): bool => in_array($name, $person, true) || in_array($name, $employed, true))));
		$this->assertCount(count($shipped), array_unique([...$person, ...$employed]), 'every shipped field still shows once');
	}//end testTheOtherDataCardsNameTheirFields()
}//end class
