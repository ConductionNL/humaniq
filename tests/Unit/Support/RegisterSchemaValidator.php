<?php

/**
 * Validates a payload against a schema declared in lib/Settings/register.d.
 *
 * A service that writes into OpenRegister is only proven when the exact
 * payload it builds passes the schema it lands in. This loads that schema
 * from the real register fragment and runs Opis JSON Schema, the validator
 * OpenRegister's ValidateObject uses, after the two translations OpenRegister
 * applies before validating: a relation (`$ref` to a schema name) is a plain
 * uuid string, and `nullable: true` admits null.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * Register-fragment schema validation for test payloads.
 */
final class RegisterSchemaValidator {

	/**
	 * The schema as declared in the register fragments.
	 *
	 * @param string $schemaName The schema key, for example 'CompAdjustment'.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(string $schemaName): array {
		foreach (glob(dirname(__DIR__, 3) . '/lib/Settings/register.d/*.json') as $file) {
			$fragment = json_decode((string)file_get_contents($file), true);
			$schema = ($fragment['components']['schemas'][$schemaName] ?? null);
			if (is_array($schema) === true) {
				return $schema;
			}
		}

		throw new RuntimeException('Schema ' . $schemaName . ' is not declared in lib/Settings/register.d.');
	}//end schema()

	/**
	 * The validation errors for a payload, empty when it is valid.
	 *
	 * @param string $schemaName The schema key.
	 * @param array<string, mixed> $payload The object as the service would save it.
	 *
	 * @return array<string, mixed>
	 */
	public static function errors(string $schemaName, array $payload): array {
		return self::errorsAgainst(self::schema($schemaName), $payload);
	}//end errors()

	/**
	 * The validation errors for a payload against a declared schema, for a
	 * sibling app's schema kept as a fixture.
	 *
	 * @param array<string, mixed> $declared The schema as a register fragment declares it.
	 * @param array<string, mixed> $payload The object as the service would save it.
	 *
	 * @return array<string, mixed>
	 */
	public static function errorsAgainst(array $declared, array $payload): array {
		$jsonSchema = self::translate(
			[
				'type' => 'object',
				'required' => ($declared['required'] ?? []),
				'properties' => ($declared['properties'] ?? []),
			]
		);

		$result = (new Validator())->validate(
			json_decode((string)json_encode($payload)),
			json_decode((string)json_encode($jsonSchema))
		);
		if ($result->isValid() === true) {
			return [];
		}

		return (new ErrorFormatter())->format($result->error());
	}//end errorsAgainst()

	/**
	 * Apply OpenRegister's relation and nullable translations recursively.
	 *
	 * @param array<string, mixed> $node A schema node.
	 *
	 * @return array<string, mixed>
	 */
	private static function translate(array $node): array {
		if (isset($node['$ref']) === true && is_string($node['$ref']) === true && str_starts_with($node['$ref'], '#') === false) {
			unset($node['$ref']);
		}

		if (($node['nullable'] ?? false) === true && isset($node['type']) === true && is_string($node['type']) === true) {
			$node['type'] = [$node['type'], 'null'];
			if (isset($node['enum']) === true && is_array($node['enum']) === true && in_array(null, $node['enum'], true) === false) {
				$node['enum'][] = null;
			}
		}

		unset($node['nullable']);
		foreach ($node as $key => $value) {
			if (is_array($value) === false || str_starts_with((string)$key, 'x-') === true) {
				continue;
			}

			if ($key === 'properties') {
				foreach ($value as $property => $child) {
					$node['properties'][$property] = is_array($child) === true ? self::translate($child) : $child;
				}

				continue;
			}

			if ($key === 'items') {
				$node['items'] = self::translate($value);
			}
		}

		// Opis rejects an empty `required` array's cousin, an empty `properties`
		// list encoded as a JSON array; keep it an object.
		if (isset($node['properties']) === true && $node['properties'] === []) {
			$node['properties'] = new \stdClass();
		}

		return $node;
	}//end translate()

}//end class
