<?php

/**
 * OpenRegister lifecycle interface/value-object test stub
 *
 * TEST-ONLY shape of `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` and
 * `OCA\OpenRegister\Lifecycle\GuardResult` — the two OpenRegister classes humaniq's
 * lifecycle guards (`NoSelfApprovalGuard`, `PayrollRunApprovedGuard`) implement
 * and return. In a real Nextcloud instance the OpenRegister app ships the real
 * classes and this file is never loaded (tests/bootstrap.php only requires it
 * when the real classes are absent). This exists so the standalone PHPUnit
 * suite (a bare php:8.3-cli container with no Nextcloud/OpenRegister installed)
 * can exercise the guards' real decision logic.
 *
 * Loaded ONLY from tests/bootstrap.php, guarded by interface_exists()/
 * class_exists() checks — NEVER from composer.json's autoload map, so it can
 * never shadow the real OpenRegister classes in a live instance (the exact
 * hazard flagged for the OCP dev-stubs: stubs belong in the test bootstrap
 * only, never in a path that is autoloaded in production).
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

/**
 * Test-stub mirror of OpenRegister's LifecycleGuardInterface.
 */
interface LifecycleGuardInterface {
	/**
	 * @param array<string, mixed> $object The loaded object payload at its current state.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult
	 */
	public function check(array $object, string $action, string $userId): GuardResult;
}//end interface

/**
 * Test-stub mirror of OpenRegister's GuardResult value object.
 */
final class GuardResult {
	/**
	 * @var bool
	 */
	private bool $allowed;

	/**
	 * @var string|null
	 */
	private ?string $message;

	/**
	 * @param bool $allowed Whether the transition should be allowed.
	 * @param string|null $message Optional deny message.
	 */
	private function __construct(bool $allowed, ?string $message) {
		$this->allowed = $allowed;
		$this->message = $message;
	}//end __construct()

	/**
	 * @return self
	 */
	public static function allow(): self {
		return new self(true, null);
	}//end allow()

	/**
	 * @param string $message Human-readable reason.
	 *
	 * @return self
	 */
	public static function deny(string $message): self {
		return new self(false, $message);
	}//end deny()

	/**
	 * @return bool
	 */
	public function isAllowed(): bool {
		return $this->allowed;
	}//end isAllowed()

	/**
	 * @return string|null
	 */
	public function getMessage(): ?string {
		return $this->message;
	}//end getMessage()
}//end class
