<?php

/**
 * Unit tests for MigrateAppConfigKeys.
 *
 * The step carries `oc_appconfig` across the `hrmq` -> `humaniq` app-id
 * rename. Nextcloud namespaces app config by app id, and there is no in-place
 * app-id upgrade — the new id is discovered as a different app — so without
 * this step every stored admin setting and every imported register/schema id
 * simply becomes unreachable.
 *
 * What is worth pinning here is not the happy path but the four refusals,
 * because each of them is a way the migration could quietly do damage:
 *
 *  1. **Reserved keys are never copied.** `AppManager::enableApp()` writes
 *     `enabled` as type MIXED; copying it with `setValueString()` stores type
 *     STRING and the next `app:enable` dies with a permanent
 *     `AppConfigTypeConflictException` — permanent because the conflict is hit
 *     before the app can run anything that would repair it.
 *  2. **An existing new-namespace value is never clobbered**, so an admin edit
 *     made after the rename survives, and a second run is a no-op.
 *  3. **A throwing write is logged and the loop continues.** `IRepairStep::run()`
 *     throwing aborts the install; one uncopyable setting is not worth that.
 *  4. **An unreadable old namespace degrades to "nothing to do"** rather than
 *     taking the upgrade down.
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
 * @spec openspec/specs/app-identity/spec.md#REQ-AID-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Repair;

use OCA\Humaniq\Repair\MigrateAppConfigKeys;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for MigrateAppConfigKeys.
 *
 * @spec openspec/specs/app-identity/spec.md#REQ-AID-001
 */
class MigrateAppConfigKeysTest extends TestCase {

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
	 * An IAppConfig double backed by a two-namespace in-memory store.
	 *
	 * @param array<string, string> $old      Values stored under `hrmq`.
	 * @param array<string, string> $new      Values stored under `humaniq`.
	 * @param string[]              $writes   Receives "key=value" for each write.
	 * @param string|null           $throwsOn Key whose write throws, or null.
	 *
	 * @return IAppConfig The double.
	 */
	private function appConfig(array $old, array $new, array &$writes, ?string $throwsOn = null): IAppConfig {
		$double = $this->createMock(IAppConfig::class);
		$double->method('getKeys')->willReturnCallback(
			static function (string $app) use ($old): array {
				return ($app === 'hrmq') ? array_keys($old) : [];
			}
		);
		$double->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($old, $new): string {
				if ($app === 'hrmq') {
					return $old[$key] ?? $default;
				}

				return $new[$key] ?? $default;
			}
		);
		$double->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$writes, $throwsOn): bool {
				if ($throwsOn !== null && $key === $throwsOn) {
					throw new \RuntimeException('write refused');
				}

				$writes[] = $app . ':' . $key . '=' . $value;
				return true;
			}
		);

		return $double;
	}//end appConfig()

	/**
	 * An empty old namespace reports "nothing to do" and writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-001
	 */
	public function testEmptyOldNamespaceIsANoOp(): void {
		$writes = [];
		$output = $this->outputDouble();

		(new MigrateAppConfigKeys(
			$this->appConfig([], [], $writes),
			$this->createMock(LoggerInterface::class)
		))->run($output);

		$this->assertSame([], $writes);
		$this->assertStringContainsString('nothing to do', $output->infos[0]);
	}//end testEmptyOldNamespaceIsANoOp()

	/**
	 * Every non-reserved, non-empty key is copied into the humaniq namespace.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-001
	 */
	public function testCopiesStoredValuesToTheNewNamespace(): void {
		$writes = [];

		(new MigrateAppConfigKeys(
			$this->appConfig(
				['register' => 'hrmq', 'leave_accrual_enabled' => 'yes'],
				[],
				$writes
			),
			$this->createMock(LoggerInterface::class)
		))->run($this->outputDouble());

		$this->assertContains('humaniq:register=hrmq', $writes);
		$this->assertContains('humaniq:leave_accrual_enabled=yes', $writes);
	}//end testCopiesStoredValuesToTheNewNamespace()

	/**
	 * Nextcloud-reserved keys are skipped.
	 *
	 * Copying `enabled` through the typed string setter permanently breaks
	 * `app:enable` with an AppConfigTypeConflictException, so this is the one
	 * skip that protects the install rather than the data.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-001
	 */
	public function testReservedKeysAreNeverCopied(): void {
		$writes = [];

		(new MigrateAppConfigKeys(
			$this->appConfig(
				[
					'enabled' => 'yes',
					'installed_version' => '0.1.9',
					'types' => 'filesystem',
					'register' => 'hrmq',
				],
				[],
				$writes
			),
			$this->createMock(LoggerInterface::class)
		))->run($this->outputDouble());

		$this->assertSame(['humaniq:register=hrmq'], $writes);
	}//end testReservedKeysAreNeverCopied()

	/**
	 * A value already present under the new app id is never overwritten.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-001
	 */
	public function testExistingNewValueIsNotClobbered(): void {
		$writes = [];

		(new MigrateAppConfigKeys(
			$this->appConfig(
				['register' => 'hrmq'],
				['register' => 'edited-after-the-rename'],
				$writes
			),
			$this->createMock(LoggerInterface::class)
		))->run($this->outputDouble());

		$this->assertSame([], $writes);
	}//end testExistingNewValueIsNotClobbered()

	/**
	 * An empty old value is not carried across as an empty new value.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-001
	 */
	public function testEmptySourceValuesAreSkipped(): void {
		$writes = [];

		(new MigrateAppConfigKeys(
			$this->appConfig(['register' => '', 'calendar_uri' => 'shared'], [], $writes),
			$this->createMock(LoggerInterface::class)
		))->run($this->outputDouble());

		$this->assertSame(['humaniq:calendar_uri=shared'], $writes);
	}//end testEmptySourceValuesAreSkipped()

	/**
	 * A throwing write is logged and the remaining keys still migrate.
	 *
	 * A repair step that throws aborts the install, so one uncopyable setting
	 * must not stop the others.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-001
	 */
	public function testAThrowingWriteIsLoggedAndTheLoopContinues(): void {
		$writes = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		(new MigrateAppConfigKeys(
			$this->appConfig(
				['bad' => 'x', 'good' => 'y'],
				[],
				$writes,
				throwsOn: 'bad'
			),
			$logger
		))->run($this->outputDouble());

		$this->assertSame(['humaniq:good=y'], $writes);
	}//end testAThrowingWriteIsLoggedAndTheLoopContinues()

	/**
	 * An unreadable old namespace degrades to "nothing to do", never a throw.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-001
	 */
	public function testUnreadableOldNamespaceDegradesSoftly(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willThrowException(new \RuntimeException('db gone'));
		$appConfig->expects($this->never())->method('setValueString');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$output = $this->outputDouble();
		(new MigrateAppConfigKeys($appConfig, $logger))->run($output);

		$this->assertStringContainsString('nothing to do', $output->infos[0]);
	}//end testUnreadableOldNamespaceDegradesSoftly()

}//end class
