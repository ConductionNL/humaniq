<?php

/**
 * OpenRegister ObjectFindOptions stub — test-only.
 *
 * `ObjectService::find()` and `findSilent()` take the register, schema,
 * extend, files, render and audit settings as one `ObjectFindOptions`. In the
 * standalone PHPUnit suite openregister is absent, so this declares the value
 * object with openregister's public fields and defaults. The real class types
 * `register` and `schema` against openregister's Register and Schema
 * interfaces as well; the suite only ever passes slugs and ids.
 *
 * Loaded ONLY from tests/bootstrap.php, behind a class_exists() check, so the
 * real openregister class always wins on a live instance. Never in
 * composer.json's autoload map.
 *
 * @category Test
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
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

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * How one object is read.
 */
final class ObjectFindOptions {

	/**
	 * Constructor.
	 *
	 * @param object|string|int|null $register The register.
	 * @param object|string|int|null $schema   The schema.
	 * @param array|null             $extend   Properties to extend.
	 * @param bool                   $files    Whether to include files.
	 * @param bool                   $render   Whether to render the object.
	 * @param bool                   $audit    Whether to write an audit entry.
	 */
	public function __construct(
		public readonly object|string|int|null $register=null,
		public readonly object|string|int|null $schema=null,
		public readonly ?array $extend=[],
		public readonly bool $files=false,
		public readonly bool $render=true,
		public readonly bool $audit=true,
	) {

	}//end __construct()
}//end class
