<?php

/**
 * OpenRegister object patch value object stubs — test-only.
 *
 * `ObjectService::patchObject()` takes an `ObjectPatch`: the object id, the
 * partial data, the `ObjectFindTarget` (register and schema) it is in and the
 * acting user. In the standalone PHPUnit suite openregister is absent, so
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
 * One partial update of an object.
 */
final class ObjectPatch {

	/**
	 * Constructor.
	 *
	 * @param string               $objectId    The object.
	 * @param array<string, mixed> $data        The fields to change.
	 * @param ObjectFindTarget     $target      The register and schema it is in.
	 * @param object|null          $currentUser The acting user.
	 */
	public function __construct(
		public readonly string $objectId,
		public readonly array $data,
		public readonly ObjectFindTarget $target=new ObjectFindTarget(),
		public readonly ?object $currentUser=null,
	) {

	}//end __construct()
}//end class
