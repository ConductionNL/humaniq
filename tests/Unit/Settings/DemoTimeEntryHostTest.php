<?php

/**
 * Demo time entries point at a seeded host
 *
 * The hours leaf shows the time entries whose `domainObjectType` and
 * `domainObjectRef` match the object it sits on. Demo rows with placeholder
 * strings match no object anywhere, so the leaf could never be seen working.
 * Every seeded TimeEntry must name a real `register:schema` pair and reach an
 * object the demo data also creates, through the `@ref:<schema>:<slug>` token
 * the importer resolves to that object's uuid, and carry hours.
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
 * @spec openspec/changes/hours-leaf-loads-on-a-consuming-page/specs/hours-leaf-delivery/spec.md#requirement-demo-data-lets-the-leaf-be-seen-working-req-hld-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

class DemoTimeEntryHostTest extends TestCase {

	/**
	 * The mock descriptor.
	 *
	 * @return array<string, mixed>
	 */
	private static function mock(): array {
		return json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/humaniq_mock_register.json'), true);
	}//end mock()

	/**
	 * The seeded objects of one schema.
	 *
	 * @param array<string, mixed> $mock   The descriptor.
	 * @param string               $schema The schema slug.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function seeds(array $mock, string $schema): array {
		return array_values(array_filter(
			$mock['components']['objects'],
			static fn (array $object): bool => ($object['@self']['schema'] ?? '') === $schema
		));
	}//end seeds()

	/**
	 * Every seeded time entry is booked on a seeded host object, with hours.
	 *
	 * @return void
	 */
	public function testEverySeededTimeEntryPointsAtASeededHost(): void {
		$mock    = self::mock();
		$entries = self::seeds(mock: $mock, schema: 'TimeEntry');
		$this->assertNotEmpty($entries);

		foreach ($entries as $entry) {
			$slug = $entry['@self']['slug'];
			$type = (string)($entry['domainObjectType'] ?? '');
			$ref  = (string)($entry['domainObjectRef'] ?? '');

			$this->assertMatchesRegularExpression('/^[a-z0-9-]+:[A-Za-z0-9-]+$/', $type, "$slug: domainObjectType is not app:schema");
			[$register, $schema] = explode(':', $type);
			$this->assertSame('humaniq', $register, "$slug: the host must be an object the demo data creates");
			$this->assertArrayHasKey($schema, $mock['components']['schemas'], "$slug: $schema is no schema of this register");

			$this->assertStringStartsWith('@ref:' . $schema . ':', $ref, "$slug: domainObjectRef is not a reference to a seeded $schema");
			$target = substr($ref, strlen('@ref:' . $schema . ':'));
			$slugs  = array_map(static fn (array $object): string => (string)$object['@self']['slug'], self::seeds(mock: $mock, schema: $schema));
			$this->assertContains($target, $slugs, "$slug: no seeded $schema has the slug $target");

			$this->assertGreaterThan(0, (float)$entry['hours'], "$slug: a row with no hours shows nothing on the host");
		}
	}//end testEverySeededTimeEntryPointsAtASeededHost()

	/**
	 * No seeded time entry carries a generated placeholder.
	 *
	 * @return void
	 */
	public function testNoSeededTimeEntryCarriesAPlaceholderReference(): void {
		foreach (self::seeds(mock: self::mock(), schema: 'TimeEntry') as $entry) {
			$this->assertStringNotContainsString('Voorbeeld', (string)$entry['domainObjectType'], $entry['@self']['slug']);
			$this->assertStringNotContainsString('Voorbeeld', (string)$entry['domainObjectRef'], $entry['@self']['slug']);
		}
	}//end testNoSeededTimeEntryCarriesAPlaceholderReference()
}//end class
