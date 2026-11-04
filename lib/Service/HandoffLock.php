<?php

/**
 * HandoffLock.
 *
 * An exclusive lock around one hand-off of a payroll run to another app,
 * keyed on the number the hand-off writes there.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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

namespace OCA\Humaniq\Service;

use Closure;
use OCP\Lock\ILockingProvider;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Holds Nextcloud's exclusive lock on a hand-off key while the hand-off
 * claims, writes and settles, so a process that stalls past the claim
 * window still keeps a second process from writing. A lock that is held
 * elsewhere, or cannot be taken, refuses the hand-off. With file locking
 * off (Nextcloud's no-op provider) every lock is granted and the pending
 * claim is the only guard, as before.
 *
 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
 */
final class HandoffLock {

	/**
	 * @param ContainerInterface $container The container that holds the locking provider.
	 *
	 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Runs the work while holding the lock on the key and answers its
	 * result, or answers null without running it when the lock is held by
	 * another process or cannot be taken. The lock is released afterwards,
	 * also when the work throws.
	 *
	 * @param string                       $key  The hand-off key, such as `glpost/HRMQ-LOON-2026-05-ADM-001`.
	 * @param Closure(): array<string, mixed> $work The hand-off.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
	 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
	 */
	public function holding(string $key, Closure $work): ?array {
		$locks = $this->provider();
		if ($locks === null) {
			return $work();
		}

		// The lock table's key column holds 64 characters; a hash keeps
		// any number short enough.
		$path = 'humaniq/handoff/' . hash('xxh128', $key);
		try {
			$locks->acquireLock($path, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable $e) {
			return null;
		}

		try {
			return $work();
		} finally {
			$this->release($locks, $path);
		}
	}//end holding()

	/**
	 * Nextcloud's locking provider, or null when the container has none.
	 *
	 * @return ILockingProvider|null
	 */
	private function provider(): ?ILockingProvider {
		try {
			$locks = $this->container->get(ILockingProvider::class);
		} catch (NotFoundExceptionInterface $e) {
			return null;
		}

		return ($locks instanceof ILockingProvider ? $locks : null);
	}//end provider()

	/**
	 * Releases the lock. A failed release leaves it to expire after the
	 * provider's lock TTL, and does not change the hand-off's outcome.
	 *
	 * @param ILockingProvider $locks The locking provider.
	 * @param string           $path  The lock path.
	 *
	 * @return void
	 */
	private function release(ILockingProvider $locks, string $path): void {
		try {
			$locks->releaseLock($path, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable $e) {
			return;
		}
	}//end release()
}//end class
