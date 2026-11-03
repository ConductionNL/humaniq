<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The hand-off lock runs the hand-off only while it holds Nextcloud's
 * exclusive lock, and refuses it when the lock is taken.
 *
 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HandoffLock;
use OCA\Humaniq\Tests\Unit\Service\Support\InMemoryLocks;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * HandoffLock.
 *
 * @covers \OCA\Humaniq\Service\HandoffLock
 */
class HandoffLockTest extends TestCase {

	/**
	 * A free lock is taken exclusively on a short path, the work runs
	 * inside it, and the lock is released afterwards.
	 *
	 * @return void
	 */
	public function testAFreeLockRunsTheWorkAndReleasesIt(): void {
		$locks = new InMemoryLocks();
		$seen = null;

		$result = (new HandoffLock($this->container($locks)))->holding('glpost/HRMQ-LOON-2026-05-6f1c2a9e-0b7d-4c3e-9a51-2d8f4e6b7c10', function () use ($locks, &$seen): array {
			$seen = array_keys($locks->held);
			return ['status' => 'posted'];
		});

		$this->assertSame(['status' => 'posted'], $result);
		$this->assertCount(1, $seen, 'The work runs while the lock is held.');
		$this->assertSame(['acquire ' . $seen[0], 'release ' . $seen[0]], $locks->calls);
		$this->assertSame([], $locks->held);
		$this->assertStringStartsWith('humaniq/handoff/', $seen[0]);
		$this->assertLessThanOrEqual(64, strlen($seen[0]), 'The path fits the lock table key.');
	}//end testAFreeLockRunsTheWorkAndReleasesIt()

	/**
	 * A taken lock refuses the hand-off: the work does not run and the
	 * other process's lock stays.
	 *
	 * @return void
	 */
	public function testATakenLockRefusesTheWork(): void {
		$locks = new InMemoryLocks();
		$lock = new HandoffLock($this->container($locks));
		$ran = false;

		$inner = $lock->holding('netpay/N-1', function () use ($lock, &$ran): array {
			return ['inner' => $lock->holding('netpay/N-1', function () use (&$ran): array {
				$ran = true;
				return [];
			})];
		});

		$this->assertSame(['inner' => null], $inner);
		$this->assertFalse($ran, 'The second hand-off does not run.');
		$this->assertSame([], $locks->held);
		$this->assertCount(3, $locks->calls, 'One acquire succeeds, one is refused, one release.');
	}//end testATakenLockRefusesTheWork()

	/**
	 * Two keys take two locks; one key always takes the same lock.
	 *
	 * @return void
	 */
	public function testEachKeyHasItsOwnLock(): void {
		$locks = new InMemoryLocks();
		$lock = new HandoffLock($this->container($locks));

		$outer = $lock->holding('glpost/A', fn (): array => ['inner' => $lock->holding('glpost/B', fn (): array => ['ran' => true])]);
		$lock->holding('glpost/A', fn (): array => []);

		$this->assertSame(['inner' => ['ran' => true]], $outer);
		$paths = array_map(static fn (string $call): string => substr($call, (strpos($call, ' ') + 1)), $locks->calls);
		$this->assertSame($paths[0], $paths[4], 'The same key gives the same path.');
		$this->assertNotSame($paths[0], $paths[1]);
	}//end testEachKeyHasItsOwnLock()

	/**
	 * A lock that cannot be taken for another reason refuses the hand-off
	 * too.
	 *
	 * @return void
	 */
	public function testALockThatCannotBeTakenRefusesTheWork(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willThrowException(new RuntimeException('lock table unavailable'));
		$locks->expects($this->never())->method('releaseLock');

		$result = (new HandoffLock($this->container($locks)))->holding('glpost/X', static fn (): array => ['ran' => true]);

		$this->assertNull($result);
	}//end testALockThatCannotBeTakenRefusesTheWork()

	/**
	 * The lock is released when the work throws, and the error reaches
	 * the caller.
	 *
	 * @return void
	 */
	public function testTheLockIsReleasedWhenTheWorkThrows(): void {
		$locks = new InMemoryLocks();

		try {
			(new HandoffLock($this->container($locks)))->holding('glpost/X', static function (): array {
				throw new RuntimeException('shillinq down');
			});
			$this->fail('The error reaches the caller.');
		} catch (RuntimeException $e) {
			$this->assertSame('shillinq down', $e->getMessage());
		}

		$this->assertSame([], $locks->held);
		$this->assertCount(2, $locks->calls);
	}//end testTheLockIsReleasedWhenTheWorkThrows()

	/**
	 * A release that fails does not change the hand-off's outcome.
	 *
	 * @return void
	 */
	public function testAFailedReleaseKeepsTheOutcome(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())->method('acquireLock')->with($this->stringStartsWith('humaniq/handoff/'), ILockingProvider::LOCK_EXCLUSIVE);
		$locks->expects($this->once())->method('releaseLock')->with($this->stringStartsWith('humaniq/handoff/'), ILockingProvider::LOCK_EXCLUSIVE)->willThrowException(new RuntimeException('gone'));

		$result = (new HandoffLock($this->container($locks)))->holding('glpost/X', static fn (): array => ['status' => 'posted']);

		$this->assertSame(['status' => 'posted'], $result);
	}//end testAFailedReleaseKeepsTheOutcome()

	/**
	 * Without a locking provider the work runs under the claim alone.
	 *
	 * @return void
	 */
	public function testWithoutALockingProviderTheWorkRuns(): void {
		$missing = $this->createMock(ContainerInterface::class);
		$missing->method('get')->willThrowException(new class extends RuntimeException implements NotFoundExceptionInterface {
		});
		$other = $this->createMock(ContainerInterface::class);
		$other->method('get')->willReturn(new \stdClass());

		$this->assertSame(['ran' => true], (new HandoffLock($missing))->holding('glpost/X', static fn (): array => ['ran' => true]));
		$this->assertSame(['ran' => true], (new HandoffLock($other))->holding('glpost/X', static fn (): array => ['ran' => true]));
	}//end testWithoutALockingProviderTheWorkRuns()

	/**
	 * A container that answers the locking provider.
	 *
	 * @param ILockingProvider $locks The provider.
	 *
	 * @return ContainerInterface
	 */
	private function container(ILockingProvider $locks): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ILockingProvider::class)->willReturn($locks);

		return $container;
	}//end container()
}//end class
