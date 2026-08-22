<?php

/**
 * Fake OpenRegister SchemaMapper for the hours-process unit tests
 *
 * `find($id)` returns an object whose `getSlug()` echoes the id — the fake
 * store writes the schema SLUG into each entity's schema field, so identity
 * mapping reproduces exactly what the listeners' `resolveSchemaSlug()`
 * needs without a lookup table that could drift.
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

/**
 * Identity-mapping SchemaMapper double.
 */
class FakeSchemaMapper {

	/**
	 * Mirror of SchemaMapper::find() — identity slug resolution.
	 *
	 * @param mixed $id The schema id (the fake stores slugs there).
	 *
	 * @return object An object exposing getSlug().
	 */
	public function find(mixed $id): object {
		$slug = (string)$id;

		return new class ($slug) {

			/**
			 * @param string $slug The slug to echo.
			 */
			public function __construct(private readonly string $slug) {

			}//end __construct()

			/**
			 * @return string The slug.
			 */
			public function getSlug(): string {
				return $this->slug;
			}//end getSlug()

		};
	}//end find()

}//end class
