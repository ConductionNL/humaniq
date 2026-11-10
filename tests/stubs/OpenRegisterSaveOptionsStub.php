<?php

/**
 * OpenRegister ObjectSaveOptions stubs — test-only.
 *
 * `ObjectService::saveObject()` takes the object, one `ObjectSaveOptions` and
 * the `_rbac` / `_multitenancy` flags. The options carry the register and
 * schema (`ObjectFindTarget`), the uuid, extend and uploaded files, the
 * `SavePolicy` (silent, validation, failIfExists, dedupOverride) and the
 * `SaveActor` (currentUser, owner, unowned). In the standalone PHPUnit suite
 * openregister is absent, so these declare the value objects with
 * openregister's public fields and defaults. The real classes type the
 * register, schema and user against openregister's Register and Schema and
 * Nextcloud's IUser; the suite only passes slugs and doubles.
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
 * How a save behaves.
 */
final class SavePolicy {

	/**
	 * Constructor.
	 *
	 * @param bool $silent        Whether to skip events.
	 * @param bool $validation    Whether to validate.
	 * @param bool $failIfExists  Whether an existing uuid is refused.
	 * @param bool $dedupOverride Whether the dedup check is overridden.
	 */
	public function __construct(
		public readonly bool $silent=false,
		public readonly bool $validation=true,
		public readonly bool $failIfExists=false,
		public readonly bool $dedupOverride=false,
	) {

	}//end __construct()
}//end class

/**
 * Who a save is made as.
 */
final class SaveActor {

	/**
	 * Constructor.
	 *
	 * @param object|null $currentUser The acting user.
	 * @param string|null $owner       The owner to set.
	 * @param bool        $unowned     Whether the object is left unowned.
	 */
	public function __construct(
		public readonly ?object $currentUser=null,
		public readonly ?string $owner=null,
		public readonly bool $unowned=false,
	) {

	}//end __construct()
}//end class

/**
 * Everything about one save except the object and the access flags.
 */
final class ObjectSaveOptions {

	/**
	 * Constructor.
	 *
	 * @param ObjectFindTarget $target        The register and schema.
	 * @param string|null      $uuid          The uuid, or null for a new object.
	 * @param array|null       $extend        Properties to extend.
	 * @param array|null       $uploadedFiles Uploaded files.
	 * @param SavePolicy       $policy        How the save behaves.
	 * @param SaveActor        $actor         Who the save is made as.
	 */
	public function __construct(
		public readonly ObjectFindTarget $target=new ObjectFindTarget(),
		public readonly ?string $uuid=null,
		public readonly ?array $extend=[],
		public readonly ?array $uploadedFiles=null,
		public readonly SavePolicy $policy=new SavePolicy(),
		public readonly SaveActor $actor=new SaveActor(),
	) {

	}//end __construct()
}//end class
