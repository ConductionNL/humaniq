<?php

/**
 * Unit tests for MigrateUserPreferences.
 *
 * This is the half of the app-id rename that fails silently. `oc_preferences`
 * is namespaced by app id just like `oc_appconfig`, but per-user reads carry a
 * default — so a preference the rename cut off does not surface as an error,
 * it surfaces as the app behaving as though the user had never chosen
 * anything. For this app the key is `active_administration_id`, and the
 * fallback is "the first administration the user can see": in a
 * multi-administratie install, a user who is not migrated silently lands on a
 * DIFFERENT LEGAL EMPLOYER's HR and payroll surface, with every page still
 * rendering perfectly.
 *
 * The load-bearing assertion in this file is
 * `testEnumeratesByUserNotByValue()`. The obvious implementation —
 * `IConfig::getUsersForUserValue(app, key, value)` — requires the caller to
 * already know the value, which is fine for a boolean flag and useless for an
 * open-ended administration id. An implementation that reached for it would
 * migrate nothing at all while reporting success, so the test pins the
 * enumeration strategy itself rather than only its result.
 *
 * The remaining tests pin the same non-destructive contract as
 * MigrateAppConfigKeys: never clobber, never delete, never throw.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/app-identity/spec.md#REQ-AID-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Repair;

use OCA\Humaniq\Repair\MigrateUserPreferences;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for MigrateUserPreferences.
 *
 * @spec openspec/specs/app-identity/spec.md#REQ-AID-002
 */
class MigrateUserPreferencesTest extends TestCase {

	/**
	 * The single per-user key this app stores.
	 *
	 * @var string
	 */
	private const KEY = 'active_administration_id';

	/**
	 * A fake IOutput that records what the step told the operator.
	 *
	 * Named `outputDouble` because PHPUnit\Framework\TestCase::output() is
	 * final and cannot be overridden.
	 *
	 * @return IOutput An IOutput double exposing `infos` and `warnings` arrays.
	 */
	private function outputDouble(): IOutput {
		return new class implements IOutput {

			/**
			 * @var string[]
			 */
			public array $infos = [];

			/**
			 * @var string[]
			 */
			public array $warnings = [];

			/**
			 * @param string $message The debug message.
			 *
			 * @return void
			 */
			public function debug(string $message): void {
			}//end debug()

			/**
			 * @param string $message The info message.
			 *
			 * @return void
			 */
			public function info($message): void {
				$this->infos[] = $message;
			}//end info()

			/**
			 * @param string $message The warning message.
			 *
			 * @return void
			 */
			public function warning($message): void {
				$this->warnings[] = $message;
			}//end warning()

			/**
			 * @param int $max The step count.
			 *
			 * @return void
			 */
			public function startProgress($max = 0): void {
			}//end startProgress()

			/**
			 * @param int    $step        The step reached.
			 * @param string $description The step description.
			 *
			 * @return void
			 */
			public function advance($step = 1, $description = ''): void {
			}//end advance()

			/**
			 * @return void
			 */
			public function finishProgress(): void {
			}//end finishProgress()

		};
	}//end outputDouble()

	/**
	 * An IUserManager double whose seen-user walk visits the given uids.
	 *
	 * @param string[] $uids The uids to visit.
	 *
	 * @return IUserManager The double.
	 */
	private function userManager(array $uids): IUserManager {
		$double = $this->createMock(IUserManager::class);
		$double->method('callForSeenUsers')->willReturnCallback(
			function (\Closure $callback) use ($uids): void {
				foreach ($uids as $uid) {
					$user = $this->createMock(IUser::class);
					$user->method('getUID')->willReturn($uid);
					$callback($user);
				}
			}
		);

		return $double;
	}//end userManager()

	/**
	 * An IConfig double backed by a two-namespace per-user in-memory store.
	 *
	 * @param array<string, string> $old      uid => value under `hrmq`.
	 * @param array<string, string> $new      uid => value under `humaniq`.
	 * @param string[]              $writes   Receives "uid=value" for each write.
	 * @param string|null           $throwsOn uid whose write throws, or null.
	 *
	 * @return IConfig The double.
	 */
	private function config(array $old, array $new, array &$writes, ?string $throwsOn = null): IConfig {
		$double = $this->createMock(IConfig::class);
		$double->method('getUserValue')->willReturnCallback(
			static function (string $userId, string $app, string $key, $default = '') use ($old, $new) {
				if ($app === 'hrmq') {
					return $old[$userId] ?? $default;
				}

				return $new[$userId] ?? $default;
			}
		);
		$double->method('setUserValue')->willReturnCallback(
			static function (string $userId, string $app, string $key, $value) use (&$writes, $throwsOn): void {
				if ($throwsOn !== null && $userId === $throwsOn) {
					throw new \RuntimeException('write refused');
				}

				$writes[] = $app . ':' . $userId . '=' . $value;
			}
		);

		return $double;
	}//end config()

	/**
	 * The active administration is carried across for every seen user.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-002
	 */
	public function testCopiesTheActiveAdministrationForEachUser(): void {
		$writes = [];

		(new MigrateUserPreferences(
			$this->config(['alice' => 'ADM-001', 'bob' => 'ADM-002'], [], $writes),
			$this->userManager(['alice', 'bob']),
			$this->createMock(LoggerInterface::class)
		))->run($this->outputDouble());

		$this->assertSame(
			['humaniq:alice=ADM-001', 'humaniq:bob=ADM-002'],
			$writes
		);
	}//end testCopiesTheActiveAdministrationForEachUser()

	/**
	 * Enumeration walks users; it never asks for users by value.
	 *
	 * `getUsersForUserValue()` needs the value up front, so it can only
	 * enumerate a closed set. `active_administration_id` holds an open-ended
	 * administration id, so an implementation reaching for that call would
	 * migrate nothing while still reporting success. This test pins the
	 * strategy, not just the outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-002
	 */
	public function testEnumeratesByUserNotByValue(): void {
		$writes = [];
		$config = $this->config(['alice' => 'ADM-001'], [], $writes);
		$config->expects($this->never())->method('getUsersForUserValue');

		$userManager = $this->userManager(['alice']);
		$userManager->expects($this->once())->method('callForSeenUsers');

		(new MigrateUserPreferences(
			$config,
			$userManager,
			$this->createMock(LoggerInterface::class)
		))->run($this->outputDouble());

		$this->assertSame(['humaniq:alice=ADM-001'], $writes);
	}//end testEnumeratesByUserNotByValue()

	/**
	 * A preference already set under the new app id is never overwritten.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-002
	 */
	public function testExistingNewPreferenceIsNotClobbered(): void {
		$writes = [];
		$output = $this->outputDouble();

		(new MigrateUserPreferences(
			$this->config(['alice' => 'ADM-001'], ['alice' => 'ADM-999'], $writes),
			$this->userManager(['alice']),
			$this->createMock(LoggerInterface::class)
		))->run($output);

		$this->assertSame([], $writes);
		$this->assertStringContainsString('1 already set under humaniq', $output->infos[0]);
	}//end testExistingNewPreferenceIsNotClobbered()

	/**
	 * A user who stored nothing needs no migration and produces no write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-002
	 */
	public function testUsersWithoutAStoredPreferenceAreSkipped(): void {
		$writes = [];
		$output = $this->outputDouble();

		(new MigrateUserPreferences(
			$this->config([], [], $writes),
			$this->userManager(['alice', 'bob']),
			$this->createMock(LoggerInterface::class)
		))->run($output);

		$this->assertSame([], $writes);
		$this->assertStringContainsString('nothing to do', $output->infos[0]);
	}//end testUsersWithoutAStoredPreferenceAreSkipped()

	/**
	 * A throwing write is logged and the remaining users still migrate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-002
	 */
	public function testAThrowingWriteIsLoggedAndTheWalkContinues(): void {
		$writes = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		(new MigrateUserPreferences(
			$this->config(
				['alice' => 'ADM-001', 'bob' => 'ADM-002'],
				[],
				$writes,
				throwsOn: 'alice'
			),
			$this->userManager(['alice', 'bob']),
			$logger
		))->run($this->outputDouble());

		$this->assertSame(['humaniq:bob=ADM-002'], $writes);
	}//end testAThrowingWriteIsLoggedAndTheWalkContinues()

	/**
	 * An unusable user enumeration warns the operator and never throws.
	 *
	 * The operator has to learn that the preferences were left behind — a
	 * silent return here would reproduce the exact failure this step exists to
	 * prevent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-002
	 */
	public function testUnusableUserEnumerationWarnsRatherThanThrows(): void {
		$writes = [];
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForSeenUsers')->willThrowException(new \RuntimeException('db gone'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$output = $this->outputDouble();
		(new MigrateUserPreferences(
			$this->config(['alice' => 'ADM-001'], [], $writes),
			$userManager,
			$logger
		))->run($output);

		$this->assertSame([], $writes);
		$this->assertStringContainsString('left in place', $output->warnings[0]);
	}//end testUnusableUserEnumerationWarnsRatherThanThrows()

}//end class
