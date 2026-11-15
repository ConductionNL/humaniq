<?php

/**
 * OpenRegister property-access value object stubs — test-only.
 *
 * `FieldReadAccess` asks `PropertyRbacHandler::canReadProperty(SchemaProperty
 * $property, PropertyAccessRequest $request)`, the shape on openregister main.
 * In the standalone PHPUnit suite openregister is absent, so these declare the
 * two value objects with openregister's public fields.
 *
 * One deliberate difference: openregister types `SchemaProperty::$schema` as
 * `OCA\OpenRegister\Db\Schema`. The suite has no Schema class and its schema
 * mappers answer anonymous schema doubles, so the stand-in takes any object.
 *
 * Loaded ONLY from tests/bootstrap.php, behind a class_exists() check, so the
 * real openregister classes always win on a live instance. Never in
 * composer.json's autoload map.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * One property of a schema, as a property-level access rule names it.
 */
final class SchemaProperty {

	/**
	 * Constructor.
	 *
	 * @param object $schema Schema containing the property definition.
	 * @param string $name   The property name.
	 */
	public function __construct(
		public readonly object $schema,
		public readonly string $name,
	) {

	}//end __construct()
}//end class

/**
 * The object a property rule is checked against, and whether it is being created.
 */
final class PropertyAccessRequest {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $object   Object data for conditional matching.
	 * @param bool                 $isCreate Whether this is a create operation.
	 */
	public function __construct(
		public readonly array $object,
		public readonly bool $isCreate = false,
	) {

	}//end __construct()
}//end class
