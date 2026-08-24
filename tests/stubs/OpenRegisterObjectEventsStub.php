<?php

/**
 * OpenRegister object-event / ObjectEntity test stubs
 *
 * TEST-ONLY mirrors of the OpenRegister classes the hours-process listeners
 * consume: the six object lifecycle events (`ObjectCreatingEvent`,
 * `ObjectUpdatingEvent`, `ObjectDeletingEvent` pre-save;
 * `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`
 * post-save) and a minimal `OCA\OpenRegister\Db\ObjectEntity`. In a real
 * Nextcloud instance the OpenRegister app ships the real classes and this
 * file is never loaded (tests/bootstrap.php only requires it when they are
 * absent), so the standalone PHPUnit suite (bare php-cli container, no
 * Nextcloud/OpenRegister) can exercise the listeners' real decision logic —
 * the same rule as OpenRegisterLifecycleStub.php.
 *
 * The stub events mirror the REAL API exactly (constructors, getters,
 * `setModifiedData()`/`getModifiedData()`, `stopPropagation()`/`setErrors()`
 * per the StoppableEventInterface contract) — a stub whose shape drifts from
 * the class it doubles tests nothing (the doubles-pinned lesson), so keep
 * this file in lockstep with `openregister/lib/Event/`.
 *
 * Loaded ONLY from tests/bootstrap.php, guarded by class_exists() — NEVER
 * from composer.json's autoload map.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests
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

namespace OCA\OpenRegister\Db {

	if (class_exists('OCA\OpenRegister\Db\ObjectEntity') === false) {
		/**
		 * Test-stub mirror of OpenRegister's ObjectEntity (payload surface only).
		 */
		class ObjectEntity {

			/**
			 * @var array<string, mixed>|null
			 */
			private ?array $object = null;

			/**
			 * @var string|null
			 */
			private ?string $schema = null;

			/**
			 * @var string|null
			 */
			private ?string $uuid = null;

			/**
			 * @param array<string, mixed>|null $object The payload.
			 *
			 * @return void
			 */
			public function setObject(?array $object): void {
				$this->object = $object;
			}//end setObject()

			/**
			 * @return array<string, mixed>|null
			 */
			public function getObject(): ?array {
				return $this->object;
			}//end getObject()

			/**
			 * @param string|null $schema The schema id.
			 *
			 * @return void
			 */
			public function setSchema(?string $schema): void {
				$this->schema = $schema;
			}//end setSchema()

			/**
			 * @return string|null
			 */
			public function getSchema(): ?string {
				return $this->schema;
			}//end getSchema()

			/**
			 * @param string|null $uuid The object uuid.
			 *
			 * @return void
			 */
			public function setUuid(?string $uuid): void {
				$this->uuid = $uuid;
			}//end setUuid()

			/**
			 * @return string|null
			 */
			public function getUuid(): ?string {
				return $this->uuid;
			}//end getUuid()

		}//end class
	}//end if
}//end namespace

namespace OCA\OpenRegister\Event {

	use OCA\OpenRegister\Db\ObjectEntity;
	use OCP\EventDispatcher\Event;

	if (class_exists('OCA\OpenRegister\Event\ObjectCreatingEvent') === false) {
		/**
		 * Shared stub base carrying the pre-save hook surface (rejection +
		 * modified data), mirroring the real events' StoppableEventInterface
		 * behaviour.
		 */
		abstract class StubPreSaveEvent extends Event {

			/**
			 * @var bool
			 */
			private bool $propagationStopped = false;

			/**
			 * @var array<string, mixed>
			 */
			private array $errors = [];

			/**
			 * @var array<string, mixed>
			 */
			private array $modifiedData = [];

			/**
			 * @return bool
			 */
			public function isPropagationStopped(): bool {
				return $this->propagationStopped;
			}//end isPropagationStopped()

			/**
			 * @return void
			 */
			public function stopPropagation(): void {
				$this->propagationStopped = true;
			}//end stopPropagation()

			/**
			 * @param array<string, mixed> $errors The error details.
			 *
			 * @return void
			 */
			public function setErrors(array $errors): void {
				$this->errors = $errors;
			}//end setErrors()

			/**
			 * @return array<string, mixed>
			 */
			public function getErrors(): array {
				return $this->errors;
			}//end getErrors()

			/**
			 * @param array<string, mixed> $data The modified data.
			 *
			 * @return void
			 */
			public function setModifiedData(array $data): void {
				$this->modifiedData = $data;
			}//end setModifiedData()

			/**
			 * @return array<string, mixed>
			 */
			public function getModifiedData(): array {
				return $this->modifiedData;
			}//end getModifiedData()

		}//end class

		/**
		 * Test-stub mirror of ObjectCreatingEvent.
		 */
		class ObjectCreatingEvent extends StubPreSaveEvent {

			/**
			 * @param ObjectEntity $object The entity being created.
			 */
			public function __construct(
				private readonly ObjectEntity $object,
			) {
				parent::__construct();
			}//end __construct()

			/**
			 * @return ObjectEntity
			 */
			public function getObject(): ObjectEntity {
				return $this->object;
			}//end getObject()

		}//end class

		/**
		 * Test-stub mirror of ObjectUpdatingEvent.
		 */
		class ObjectUpdatingEvent extends StubPreSaveEvent {

			/**
			 * @param ObjectEntity $newObject The entity after update.
			 * @param ObjectEntity|null $oldObject The entity before update.
			 */
			public function __construct(
				private readonly ObjectEntity $newObject,
				private readonly ?ObjectEntity $oldObject = null,
			) {
				parent::__construct();
			}//end __construct()

			/**
			 * @return ObjectEntity
			 */
			public function getNewObject(): ObjectEntity {
				return $this->newObject;
			}//end getNewObject()

			/**
			 * @return ObjectEntity|null
			 */
			public function getOldObject(): ?ObjectEntity {
				return $this->oldObject;
			}//end getOldObject()

		}//end class

		/**
		 * Test-stub mirror of ObjectDeletingEvent.
		 */
		class ObjectDeletingEvent extends StubPreSaveEvent {

			/**
			 * @param ObjectEntity $object The entity being deleted.
			 */
			public function __construct(
				private readonly ObjectEntity $object,
			) {
				parent::__construct();
			}//end __construct()

			/**
			 * @return ObjectEntity
			 */
			public function getObject(): ObjectEntity {
				return $this->object;
			}//end getObject()

		}//end class

		/**
		 * Test-stub mirror of ObjectCreatedEvent.
		 */
		class ObjectCreatedEvent extends Event {

			/**
			 * @param ObjectEntity $object The created entity.
			 */
			public function __construct(
				private readonly ObjectEntity $object,
			) {
				parent::__construct();
			}//end __construct()

			/**
			 * @return ObjectEntity
			 */
			public function getObject(): ObjectEntity {
				return $this->object;
			}//end getObject()

		}//end class

		/**
		 * Test-stub mirror of ObjectDeletedEvent.
		 */
		class ObjectDeletedEvent extends Event {

			/**
			 * @param ObjectEntity $object The deleted entity.
			 */
			public function __construct(
				private readonly ObjectEntity $object,
			) {
				parent::__construct();
			}//end __construct()

			/**
			 * @return ObjectEntity
			 */
			public function getObject(): ObjectEntity {
				return $this->object;
			}//end getObject()

		}//end class
	}//end if

	if (class_exists('OCA\OpenRegister\Event\ObjectUpdatedEvent') === false) {
		/**
		 * Test-stub mirror of ObjectUpdatedEvent.
		 */
		class ObjectUpdatedEvent extends Event {

			/**
			 * @param ObjectEntity $newObject The entity after update.
			 * @param ObjectEntity|null $oldObject The entity before update.
			 */
			public function __construct(
				private readonly ObjectEntity $newObject,
				private readonly ?ObjectEntity $oldObject = null,
			) {
				parent::__construct();
			}//end __construct()

			/**
			 * @return ObjectEntity
			 */
			public function getNewObject(): ObjectEntity {
				return $this->newObject;
			}//end getNewObject()

			/**
			 * @return ObjectEntity|null
			 */
			public function getOldObject(): ?ObjectEntity {
				return $this->oldObject;
			}//end getOldObject()

		}//end class
	}//end if
}//end namespace

namespace OCA\OpenRegister\Exception {

	if (class_exists('OCA\OpenRegister\Exception\FolderAccessDeniedException') === false) {
		/**
		 * Standalone double of OpenRegister's folder-access denial, so the
		 * HoursMigrationRunner deferral classification is testable without a
		 * live server. Mirrors only the surface humaniq consumes (the type).
		 */
		class FolderAccessDeniedException extends \Exception {
		}//end class
	}//end if
}//end namespace
