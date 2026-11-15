<?php

/**
 * OpenRegister lifecycle transition guard test stub
 *
 * Mirrors `OCA\OpenRegister\Lifecycle\LifecycleTransitionGuardInterface` and
 * `OCA\OpenRegister\Lifecycle\LifecycleGuardContext`, which OpenRegister calls
 * with the stored object as well as the incoming data. Loaded ONLY from
 * tests/bootstrap.php when the real classes are absent; never autoloaded in
 * production, so it cannot shadow the real OpenRegister classes.
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

namespace OCA\OpenRegister\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;

if (interface_exists('OCA\OpenRegister\Lifecycle\LifecycleTransitionGuardInterface') === false) {
	/**
	 * Test-stub mirror of OpenRegister's LifecycleTransitionGuardInterface.
	 */
	interface LifecycleTransitionGuardInterface extends LifecycleGuardInterface {
		/**
		 * Authorise (or deny) a transition, knowing the stored object.
		 *
		 * @param LifecycleGuardContext $context The incoming data, the stored object, the action and the caller.
		 *
		 * @return GuardResult
		 */
		public function checkTransition(LifecycleGuardContext $context): GuardResult;
	}//end interface
}

if (class_exists('OCA\OpenRegister\Lifecycle\LifecycleGuardContext') === false) {
	/**
	 * Test-stub mirror of OpenRegister's LifecycleGuardContext.
	 */
	final class LifecycleGuardContext {
		/**
		 * @param array<string, mixed> $object The incoming data.
		 * @param ObjectEntity         $stored The stored object.
		 * @param string               $action The lifecycle action.
		 * @param string               $userId The caller.
		 */
		public function __construct(
			public readonly array $object,
			public readonly ObjectEntity $stored,
			public readonly string $action,
			public readonly string $userId,
		) {
		}//end __construct()

		/**
		 * The stored object's uuid.
		 *
		 * @return string
		 */
		public function uuid(): string {
			return (string)$this->stored->getUuid();
		}//end uuid()

		/**
		 * The stored object's data.
		 *
		 * @return array<string, mixed>
		 */
		public function storedData(): array {
			return ($this->stored->getObject() ?? []);
		}//end storedData()
	}//end class
}
