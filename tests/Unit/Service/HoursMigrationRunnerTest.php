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
 * @package  OCA\Humaniq\Tests\Unit\Service
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

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HoursMigrationRunner;
use OCP\BackgroundJob\IJobList;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Humaniq\Service\HoursMigrationRunner
 */
class HoursMigrationRunnerTest extends TestCase {

	private const JOB_CLASS = 'OCA\Humaniq\BackgroundJob\CompleteHoursMigrationJob';

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

	/**
	 * A failing admin-group lookup is logged and the pass STILL runs with no
	 * acting user — the runner degrades to the sessionless posture rather
	 * than letting infrastructure trouble abort the migration.
	 *
	 * @return void
	 */
	public function testFailingAdminResolutionIsLoggedAndThePassStillRuns(): void {
		$this->setUserCalls = [];
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$session->method('setUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->setUserCalls[] = $user?->getUID();
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willThrowException(new \RuntimeException('directory down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with(self::stringContains('could not resolve an acting admin user'));

		$runner = new HoursMigrationRunner(
			$session,
			$groupManager,
			$this->createMock(IJobList::class),
			$logger
		);

		$result = $runner->runAsActingUser(static fn (): array => ['processed' => 1, 'entriesCreated' => 0, 'unresolvableUserLinks' => 0]);

		self::assertSame(1, $result['processed']);
		self::assertSame([], $this->setUserCalls, 'No impersonation may happen when no admin resolves.');
	}//end testFailingAdminResolutionIsLoggedAndThePassStillRuns()

	/**
	 * The whole acting-user outcome: who the pass runs as, what it
	 * returns, the session writes and what is logged, for every way the
	 * admin lookup can go.
	 *
	 * @return void
	 */
	public function testTheWholeActingUserOutcome(): void {
		$cases = [
			'session-user'     => ['session' => 'anna', 'group' => 'admins', 'admins' => ['root' => 'root']],
			'first-admin'      => ['session' => null, 'group' => 'admins', 'admins' => ['zed' => 'zed', 'amy' => 'amy']],
			'no-admin-users'   => ['session' => null, 'group' => 'admins', 'admins' => []],
			'no-admin-group'   => ['session' => null, 'group' => 'none', 'admins' => []],
			'group-throws'     => ['session' => null, 'group' => 'throws', 'admins' => []],
			'users-throw'      => ['session' => null, 'group' => 'users-throw', 'admins' => []],
			'pass-throws'      => ['session' => null, 'group' => 'admins', 'admins' => ['root' => 'root'], 'fail' => true],
		];
		$got = [];
		foreach ($cases as $name => $case) {
			$this->setUserCalls = [];
			$logged = [];
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn(($case['session'] === null ? null : $this->user($case['session'])));
			$session->method('setUser')->willReturnCallback(
				function (?IUser $user): void {
					$this->setUserCalls[] = $user?->getUID();
				}
			);
			$groupManager = $this->createMock(IGroupManager::class);
			$group = $this->createMock(IGroup::class);
			$users = array_map(fn (string $uid): IUser => $this->user($uid), $case['admins']);
			if ($case['group'] === 'users-throw') {
				$group->method('getUsers')->willThrowException(new \RuntimeException('users down'));
			} else {
				$group->method('getUsers')->willReturn($users);
			}

			match ($case['group']) {
				'none'   => $groupManager->method('get')->willReturn(null),
				'throws' => $groupManager->method('get')->willThrowException(new \RuntimeException('directory down')),
				default  => $groupManager->method('get')->willReturn($group),
			};
			$logger = $this->createMock(LoggerInterface::class);
			$logger->method('warning')->willReturnCallback(
				static function (string $message, array $context) use (&$logged): void {
					$logged[] = [$message, $context];
				}
			);
			$runner = new HoursMigrationRunner($session, $groupManager, $this->createMock(IJobList::class), $logger);
			$fail = ($case['fail'] ?? false);
			$error = null;
			$result = null;
			try {
				$result = $runner->runAsActingUser(
					function () use ($fail): array {
						$seen = $this->setUserCalls;
						if ($fail === true) {
							throw new \RuntimeException('pass failed');
						}

						return ['processed' => 1, 'setUserBeforePass' => $seen];
					}
				);
			} catch (\RuntimeException $e) {
				$error = $e->getMessage();
			}

			$got[$name] = ['result' => $result, 'error' => $error, 'setUser' => $this->setUserCalls, 'logged' => $logged];
		}

		self::assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/hours-migration-acting-user.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}//end testTheWholeActingUserOutcome()

}//end class
