<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An in-memory Nextcloud locking provider for tests: an exclusive lock is
 * granted once until it is released, as the database and Redis providers
 * grant it.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service\Support;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Exclusive locks held in memory, with a log of every call.
 */
class InMemoryLocks implements ILockingProvider {

	/**
	 * The paths held now.
	 *
	 * @var array<string, true>
	 */
	public array $held = [];

	/**
	 * Every acquire and release, in order, as `acquire <path>`, `release <path>`.
	 *
	 * @var list<string>
	 */
	public array $calls = [];

	/**
	 * @param string      $path         The lock path.
	 * @param int         $type         The lock type.
	 * @param string|null $readablePath A readable path for the error.
	 *
	 * @return void
	 *
	 * @throws LockedException When the path is held.
	 */
	public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
		$this->calls[] = 'acquire ' . $path;
		if (isset($this->held[$path]) === true) {
			throw new LockedException($path);
		}

		$this->held[$path] = true;
	}//end acquireLock()

	/**
	 * @param string $path The lock path.
	 * @param int    $type The lock type.
	 *
	 * @return void
	 */
	public function releaseLock(string $path, int $type): void {
		$this->calls[] = 'release ' . $path;
		unset($this->held[$path]);
	}//end releaseLock()

	/**
	 * @param string $path The lock path.
	 * @param int    $type The lock type.
	 *
	 * @return bool
	 */
	public function isLocked(string $path, int $type): bool {
		return isset($this->held[$path]);
	}//end isLocked()

	/**
	 * @param string $path       The lock path.
	 * @param int    $targetType The lock type to change to.
	 *
	 * @return void
	 */
	public function changeLock(string $path, int $targetType): void {
	}//end changeLock()

	/**
	 * @return void
	 */
	public function releaseAll(): void {
		$this->held = [];
	}//end releaseAll()
}//end class
