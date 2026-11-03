<?php

/**
 * LoadsAllRows.
 *
 * Loads every object of a schema, up to the class's LIMIT, as plain arrays,
 * for a class that also uses ConvertsRowsToArrays and offers objectService(),
 * register() and $logger.
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
 * @spec exclude object row plumbing shared by services and jobs, no behavioural spec surface.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Support;

use ReflectionClass;

/**
 * All objects of a schema as arrays. A failing read is logged and gives an
 * empty list.
 */
trait LoadsAllRows {
	/**
	 * Load all objects of a schema (capped), as plain arrays.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()->setRegister($this->register())->setSchema($schema)->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning((new ReflectionClass($this))->getShortName() . ': kon ' . $schema . ' niet laden: ' . $e->getMessage());
			return [];
		}

		return array_map(fn (mixed $row): array => $this->toArray($row), array_values(is_array($rows) === true ? $rows : []));
	}//end loadAll()
}//end class
