<?php

/**
 * Fake OpenRegister ObjectService for the hours-process unit tests
 *
 * An in-memory register the listeners/services under test read and write the
 * way they reach the real one: `setRegister()->setSchema()->findAll()`,
 * `find(id:, register:, schema:, _rbac:, _multitenancy:)` and
 * `saveObject(object:, register:, schema:, uuid:, _rbac:, _multitenancy:)`
 * — parameter NAMES mirror the real ObjectService because production code
 * calls with named arguments (a fake whose names drift refuses the calls,
 * the doubles-pinned lesson).
 *
 * State lives in a shared inner object ({@see state}) deliberately: the
 * production code CLONES the service before use (context isolation), and a
 * clone's copied arrays would silently hide writes from the test's
 * assertions — the shared object survives the clone by reference.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Support
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

namespace OCA\Hrmq\Tests\Unit\Support;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * In-memory ObjectService double with clone-surviving shared state.
 *
 * @SuppressWarnings(PHPMD) Test double mirroring an external API surface.
 */
class FakeObjectStore {

	/**
	 * Shared mutable state: `objects[schema][uuid] => payload`, plus a log
	 * of saveObject calls (`saves[] = [schema, uuid, payload]`).
	 *
	 * @var object
	 */
	public object $state;

	/**
	 * The context schema selected via setSchema().
	 *
	 * @var string
	 */
	private string $contextSchema = '';

	/**
	 * Constructor — fresh shared state.
	 */
	public function __construct() {
		$this->state = new class {

			/**
			 * @var array<string, array<string, array<string, mixed>>>
			 */
			public array $objects = [];

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * @var int
			 */
			public int $uuidCounter = 0;
		};
	}//end __construct()

	/**
	 * Seed one object.
	 *
	 * @param string $schema The schema slug.
	 * @param string $uuid The object uuid.
	 * @param array<string, mixed> $payload The payload (id is added).
	 *
	 * @return void
	 */
	public function seed(string $schema, string $uuid, array $payload): void {
		$payload['id'] = $uuid;
		$this->state->objects[$schema][$uuid] = $payload;
	}//end seed()

	/**
	 * Mirror of ObjectService::setRegister().
	 *
	 * @param mixed $register The register (ignored by the fake).
	 *
	 * @return self
	 */
	public function setRegister(mixed $register): self {
		return $this;
	}//end setRegister()

	/**
	 * Mirror of ObjectService::setSchema().
	 *
	 * @param mixed $schema The schema slug.
	 *
	 * @return self
	 */
	public function setSchema(mixed $schema): self {
		$this->contextSchema = (string)$schema;

		return $this;
	}//end setSchema()

	/**
	 * Mirror of ObjectService::findAll() — rows of the context schema,
	 * honouring `filters` on top-level equality.
	 *
	 * @param array<string, mixed> $config The query config.
	 * @param bool $_rbac RBAC flag (ignored).
	 * @param bool $_multitenancy Multitenancy flag (ignored).
	 *
	 * @return array<int, array<string, mixed>> The matching payloads.
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$rows = array_values($this->state->objects[$this->contextSchema] ?? []);
		$filters = ($config['filters'] ?? []);
		if (is_array($filters) === false || $filters === []) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end findAll()

	/**
	 * Mirror of ObjectService::find() — the parameter names match the real
	 * signature's, because callers use named arguments.
	 *
	 * @param int|string $id The object uuid.
	 * @param array<int, string>|null $_extend Extend list (ignored).
	 * @param bool $files Files flag (ignored).
	 * @param mixed $register The register (ignored).
	 * @param mixed $schema The schema slug.
	 * @param bool $_rbac RBAC flag (ignored).
	 * @param bool $_multitenancy Multitenancy flag (ignored).
	 *
	 * @return ObjectEntity|null The entity, or null.
	 */
	public function find(
		int | string $id,
		?array $_extend = [],
		bool $files = false,
		mixed $register = null,
		mixed $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): ?ObjectEntity {
		$schemaKey = (string)($schema ?? $this->contextSchema);
		$payload = ($this->state->objects[$schemaKey][(string)$id] ?? null);
		if ($payload === null) {
			return null;
		}

		return $this->entityFor((string)$id, $schemaKey, $payload);
	}//end find()

	/**
	 * Mirror of ObjectService::saveObject() — upserts into the store and
	 * logs the call.
	 *
	 * @param array<string, mixed>|object $object The payload.
	 * @param array<int, string>|null $extend Extend list (ignored).
	 * @param mixed $register The register (ignored).
	 * @param mixed $schema The schema slug.
	 * @param string|null $uuid The uuid (generated when null).
	 * @param bool $_rbac RBAC flag (ignored).
	 * @param bool $_multitenancy Multitenancy flag (ignored).
	 *
	 * @return ObjectEntity The saved entity.
	 */
	public function saveObject(
		array | object $object,
		?array $extend = [],
		mixed $register = null,
		mixed $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): ObjectEntity {
		$payload = (is_array($object) === true ? $object : (array)$object);
		$schemaKey = (string)($schema ?? $this->contextSchema);
		if ($uuid === null || $uuid === '') {
			$this->state->uuidCounter++;
			$uuid = sprintf('fake-%s-%04d', strtolower($schemaKey), $this->state->uuidCounter);
		}

		$payload['id'] = $uuid;
		$existing = ($this->state->objects[$schemaKey][$uuid] ?? []);
		$this->state->objects[$schemaKey][$uuid] = array_merge($existing, $payload);
		$this->state->saves[] = [
			'schema' => $schemaKey,
			'uuid' => $uuid,
			'payload' => $payload,
		];

		return $this->entityFor($uuid, $schemaKey, $this->state->objects[$schemaKey][$uuid]);
	}//end saveObject()

	/**
	 * Build an ObjectEntity (real or stub, whichever the run provides).
	 *
	 * @param string $uuid The uuid.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entityFor(string $uuid, string $schema, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setObject($payload);

		return $entity;
	}//end entityFor()

}//end class
