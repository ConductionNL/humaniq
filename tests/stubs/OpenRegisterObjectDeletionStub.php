<?php

/**
 * OpenRegister object deletion value object stubs — test-only.
 *
 * `ObjectService::deleteObject()` takes an `ObjectDeletion`: the uuid, the
 * `ObjectFindTarget` (register and schema) it is in, the acting user and the
 * permanent flag. In the standalone PHPUnit suite openregister is absent, so
 * these declare the two value objects with openregister's public fields and
 * defaults. The real classes type the register, schema and user against
 * openregister's Register and Schema and Nextcloud's IUser; the suite only
 * passes slugs.
 *
 * Loaded ONLY from tests/bootstrap.php, behind a class_exists() check, so the
 * real openregister classes always win on a live instance. Never in
 * composer.json's autoload map.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

if (class_exists('OCA\OpenRegister\Service\Object\ObjectFindTarget') === false) {
	/**
	 * The register and schema an object is looked up in.
	 */
	final class ObjectFindTarget {

		/**
		 * Constructor.
		 *
		 * @param object|string|int|null $register The register.
		 * @param object|string|int|null $schema   The schema.
		 */
		public function __construct(
			public readonly object|string|int|null $register=null,
			public readonly object|string|int|null $schema=null,
		) {

		}//end __construct()
	}//end class
}//end if

/**
 * One object to delete.
 */
final class ObjectDeletion {

	/**
	 * Constructor.
	 *
	 * @param string           $uuid        The object.
	 * @param ObjectFindTarget $target      The register and schema it is in.
	 * @param object|null      $currentUser The acting user.
	 * @param bool             $permanent   Whether to delete permanently.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly ObjectFindTarget $target=new ObjectFindTarget(),
		public readonly ?object $currentUser=null,
		public readonly bool $permanent=false,
	) {

	}//end __construct()

	/**
	 * Whether the deletion names both a register and a schema.
	 *
	 * @return bool
	 */
	public function isScoped(): bool {
		return $this->target->register !== null && $this->target->schema !== null;
	}//end isScoped()
}//end class
