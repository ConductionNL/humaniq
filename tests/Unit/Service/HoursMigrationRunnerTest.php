<?php

/**
 * Unit tests for HoursMigrationRunner.
 *
 * Pins the two execution-context behaviours the migration relies on: acting-
 * user resolution (session wins; fallback admin impersonated AND restored;
 * no-user still runs) and the maintenance-mode deferral classification (only
 * FolderAccessDeniedException defers, the job is enqueued at most once).
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\HoursMigrationRunner;
use OCP\BackgroundJob\IJobList;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Hrmq\Service\HoursMigrationRunner
 */
class HoursMigrationRunnerTest extends TestCase {

	private const JOB_CLASS = 'OCA\Hrmq\BackgroundJob\CompleteHoursMigrationJob';

	/**
	 * setUser() calls observed on the session double, in order.
	 *
	 * @var array<int, string|null>
	 */
	private array $setUserCalls = [];

	/**
	 * Build the runner over doubles.
	 *
	 * @param IUser|null $sessionUser The user the session reports.
	 * @param IUser|null $adminUser The admin the group resolves (null = none).
	 * @param IJobList|null $jobList Job list double (fresh mock when null).
	 *
	 * @return HoursMigrationRunner
	 */
	private function runnerWith(?IUser $sessionUser, ?IUser $adminUser, ?IJobList $jobList = null): HoursMigrationRunner {
		$this->setUserCalls = [];

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($sessionUser);
		$session->method('setUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->setUserCalls[] = $user?->getUID();
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		if ($adminUser !== null) {
			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn([$adminUser]);
			$groupManager->method('get')->willReturn($group);
		} else {
			$groupManager->method('get')->willReturn(null);
		}

		return new HoursMigrationRunner(
			$session,
			$groupManager,
			($jobList ?? $this->createMock(IJobList::class)),
			$this->createMock(LoggerInterface::class)
		);
	}//end runnerWith()

	/**
	 * A user double with a UID.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * A real session user is never overridden.
	 *
	 * @return void
	 */
	public function testSessionUserWinsWithoutImpersonation(): void {
		$runner = $this->runnerWith(sessionUser: $this->user('alice'), adminUser: $this->user('admin'));

		$result = $runner->runAsActingUser(static fn (): array => ['processed' => 1, 'entriesCreated' => 0, 'unresolvableUserLinks' => 0]);

		self::assertSame(1, $result['processed']);
		self::assertSame([], $this->setUserCalls, 'An authenticated session must never be overridden.');
	}//end testSessionUserWinsWithoutImpersonation()

	/**
	 * Without a session the first admin is impersonated and the session restored.
	 *
	 * @return void
	 */
	public function testFallbackAdminIsImpersonatedAndRestored(): void {
		$runner = $this->runnerWith(sessionUser: null, adminUser: $this->user('admin'));

		$runner->runAsActingUser(static fn (): array => ['processed' => 0, 'entriesCreated' => 0, 'unresolvableUserLinks' => 0]);

		self::assertSame(['admin', null], $this->setUserCalls, 'Impersonate the admin, then restore the null session.');
	}//end testFallbackAdminIsImpersonatedAndRestored()

	/**
	 * The session is restored even when the pass throws.
	 *
	 * @return void
	 */
	public function testSessionRestoredWhenThePassThrows(): void {
		$runner = $this->runnerWith(sessionUser: null, adminUser: $this->user('admin'));

		try {
			$runner->runAsActingUser(static function (): array {
				throw new \RuntimeException('boom');
			});
			self::fail('The pass exception must propagate.');
		} catch (\RuntimeException) {
			// Expected.
		}

		self::assertSame(['admin', null], $this->setUserCalls, 'finally must restore the session on failure too.');
	}//end testSessionRestoredWhenThePassThrows()

	/**
	 * With no resolvable user at all the pass still runs, un-impersonated.
	 *
	 * @return void
	 */
	public function testPassStillRunsWithoutAnyResolvableUser(): void {
		$runner = $this->runnerWith(sessionUser: null, adminUser: null);

		$result = $runner->runAsActingUser(static fn (): array => ['processed' => 2, 'entriesCreated' => 0, 'unresolvableUserLinks' => 0]);

		self::assertSame(2, $result['processed']);
		self::assertSame([], $this->setUserCalls);
	}//end testPassStillRunsWithoutAnyResolvableUser()

	/**
	 * Only FolderAccessDeniedException defers; anything else is a real failure.
	 *
	 * @return void
	 */
	public function testOnlyFolderAccessDenialDefers(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('add');
		$runner = $this->runnerWith(sessionUser: null, adminUser: null, jobList: $jobList);

		self::assertFalse($runner->deferIfMaintenanceDenied(new \RuntimeException('boom'), self::JOB_CLASS));
	}//end testOnlyFolderAccessDenialDefers()

	/**
	 * A folder denial enqueues the completion job exactly once.
	 *
	 * @return void
	 */
	public function testFolderDenialEnqueuesTheJobOnce(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturn(false);
		$jobList->expects(self::once())->method('add')->with(self::JOB_CLASS);
		$runner = $this->runnerWith(sessionUser: null, adminUser: null, jobList: $jobList);

		$denial = new \OCA\OpenRegister\Exception\FolderAccessDeniedException('denied');

		self::assertTrue($runner->deferIfMaintenanceDenied($denial, self::JOB_CLASS));
	}//end testFolderDenialEnqueuesTheJobOnce()

	/**
	 * An already-queued job is not enqueued again.
	 *
	 * @return void
	 */
	public function testAlreadyQueuedJobIsNotDuplicated(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturn(true);
		$jobList->expects(self::never())->method('add');
		$runner = $this->runnerWith(sessionUser: null, adminUser: null, jobList: $jobList);

		$denial = new \OCA\OpenRegister\Exception\FolderAccessDeniedException('denied');

		self::assertTrue($runner->deferIfMaintenanceDenied($denial, self::JOB_CLASS));
	}//end testAlreadyQueuedJobIsNotDuplicated()
}//end class
