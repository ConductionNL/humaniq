<?php

/**
 * Schema-shape tests for the IntegrationAccount governance catalog.
 *
 * hris-api-public adds no controller or service (the "API" it documents is
 * OpenRegister's existing `/api/objects/{register}/{schema}` surface), so this
 * is the change's only PHP test: it pins the shape of the new
 * `IntegrationAccount` schema fragment
 * (`lib/Settings/register.d/hr-integrations.json`) — required fields, the
 * `status` enum and its `actief` default, and the gate-28 discipline that
 * every property carries a title + description AND that `grantedSchemas`'
 * description states plainly it does NOT enforce access (design.md D2).
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Settings
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
 * @spec openspec/changes/hris-api-public/specs/hris-api-public/spec.md#REQ-HRIS-003
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Pins the IntegrationAccount schema fragment's shape.
 *
 * @spec openspec/changes/hris-api-public/specs/hris-api-public/spec.md#REQ-HRIS-003
 */
class IntegrationAccountSchemaTest extends TestCase {

	/**
	 * The decoded IntegrationAccount schema definition.
	 *
	 * @var array<string, mixed>
	 */
	private array $schema;

	/**
	 * Load and decode the IntegrationAccount schema from its fragment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$path = dirname(__DIR__, 3) . '/lib/Settings/register.d/hr-integrations.json';
		$this->assertFileExists($path, 'The hr-integrations.json fragment must exist.');

		$decoded = json_decode(file_get_contents($path), true);
		$this->assertIsArray($decoded, 'The fragment must be valid JSON.');
		$this->assertArrayHasKey('IntegrationAccount', $decoded['components']['schemas'] ?? [], 'The fragment must define an IntegrationAccount schema.');

		$this->schema = $decoded['components']['schemas']['IntegrationAccount'];

	}//end setUp()

	/**
	 * The required fields are exactly name, purpose, nextcloudUserId,
	 * grantedSchemas — matching the "a catalog record validates" scenario.
	 *
	 * @return void
	 */
	public function testRequiredFields(): void {
		$this->assertSame(
			['name', 'purpose', 'nextcloudUserId', 'grantedSchemas'],
			$this->schema['required'],
			'IntegrationAccount must require name, purpose, nextcloudUserId and grantedSchemas.'
		);

	}//end testRequiredFields()

	/**
	 * status is an enum of exactly actief/ingetrokken and defaults to actief.
	 *
	 * @return void
	 */
	public function testStatusEnumAndDefault(): void {
		$status = $this->schema['properties']['status'];
		$this->assertSame(['actief', 'ingetrokken'], $status['enum'], 'status enum must be actief/ingetrokken.');
		$this->assertSame('actief', $status['default'], 'status must default to actief.');

	}//end testStatusEnumAndDefault()

	/**
	 * nextcloudUserId is a plain string, never a $ref (ADR-062 rule 7).
	 *
	 * @return void
	 */
	public function testNextcloudUserIdIsPlainString(): void {
		$property = $this->schema['properties']['nextcloudUserId'];
		$this->assertSame('string', $property['type'], 'nextcloudUserId must be a plain string.');
		$this->assertArrayNotHasKey('$ref', $property, 'nextcloudUserId must never be a $ref.');

	}//end testNextcloudUserIdIsPlainString()

	/**
	 * grantedSchemas is a string array whose description states plainly that
	 * it does NOT grant or enforce access (design.md D2 — gate-28).
	 *
	 * @return void
	 */
	public function testGrantedSchemasIsInformationalStringArray(): void {
		$property = $this->schema['properties']['grantedSchemas'];
		$this->assertSame('array', $property['type'], 'grantedSchemas must be an array.');
		$this->assertSame('string', $property['items']['type'], 'grantedSchemas items must be strings.');

		$description = strtolower($property['description']);
		$this->assertStringContainsString('does not', $description, 'grantedSchemas description must state it does not grant/enforce access.');
		$this->assertStringContainsString('enforce', $description, 'grantedSchemas description must mention enforcement.');
		$this->assertStringContainsString('rbac', $description, 'grantedSchemas description must point to OpenRegister RBAC as the real grant.');

	}//end testGrantedSchemasIsInformationalStringArray()

	/**
	 * Gate-28: every property carries a non-empty title and description.
	 *
	 * @return void
	 */
	public function testEveryPropertyHasTitleAndDescription(): void {
		foreach ($this->schema['properties'] as $name => $property) {
			$this->assertArrayHasKey('title', $property, "Property {$name} must have a title.");
			$this->assertArrayHasKey('description', $property, "Property {$name} must have a description.");
			$this->assertNotEmpty($property['title'], "Property {$name} title must be non-empty.");
			$this->assertNotEmpty($property['description'], "Property {$name} description must be non-empty.");
		}

	}//end testEveryPropertyHasTitleAndDescription()

}//end class
