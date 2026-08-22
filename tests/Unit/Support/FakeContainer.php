<?php

/**
 * Fake PSR container for the hours-process unit tests
 *
 * Returns pre-registered doubles for the two ids the listeners resolve
 * lazily (`OCA\OpenRegister\Service\ObjectService`,
 * `OCA\OpenRegister\Db\SchemaMapper`) and throws for anything else — a
 * container that silently hands out nulls would turn a wiring mistake into
 * a green test.
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

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Map-backed PSR-11 container double.
 */
class FakeContainer implements ContainerInterface {

	/**
	 * @param array<string, mixed> $entries Service map keyed by id.
	 */
	public function __construct(private array $entries = []) {

	}//end __construct()

	/**
	 * Register one entry.
	 *
	 * @param string $id The service id.
	 * @param mixed $service The service double.
	 *
	 * @return void
	 */
	public function set(string $id, mixed $service): void {
		$this->entries[$id] = $service;
	}//end set()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id The service id.
	 *
	 * @return mixed
	 */
	public function get(string $id): mixed {
		if ($this->has($id) === false) {
			throw new class ('Service not registered in FakeContainer: ' . $id) extends \RuntimeException implements NotFoundExceptionInterface {
			};
		}

		return $this->entries[$id];
	}//end get()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id The service id.
	 *
	 * @return bool
	 */
	public function has(string $id): bool {
		return array_key_exists($id, $this->entries);
	}//end has()

}//end class
