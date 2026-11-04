<?php

/**
 * ResolvesSchemaSlug.
 *
 * Reads the schema slug of an object event.
 *
 * @category Support
 * @package  OCA\Humaniq\Support
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
 * @spec exclude helper shared by services and checks, no behavioural spec surface.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Support;

/**
 * Reads the schema slug of an object event.
 */
trait ResolvesSchemaSlug {
	/**
	 * Resolve a schema id to its slug via OpenRegister's SchemaMapper.
	 *
	 * Returns an empty string when OpenRegister is absent or the schema cannot
	 * be resolved, so the listener no-ops safely.
	 *
	 * @param string $schemaId The OpenRegister schema id/uuid carried by the object.
	 *
	 * @return string The schema slug, or '' when unresolvable.
	 */
	private function resolveSchemaSlug(string $schemaId): string {
		if ($schemaId === '') {
			return '';
		}

		try {
			$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
			$schema = $schemaMapper->find($schemaId);
			return (string)$schema->getSlug();
		} catch (\Throwable $e) {
			return '';
		}

	}//end resolveSchemaSlug()
}//end class
