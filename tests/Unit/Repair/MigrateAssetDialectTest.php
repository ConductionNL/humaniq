<?php

/**
 * Unit tests for MigrateAssetDialect.
 *
 * The repair step is the UNCONDITIONAL half of this migration's delivery: the
 * `occ humaniq:assets:migrate-dialect` command is opt-in and a human has to know
 * to run it, whereas this runs on every upgrade. It was the one new class in
 * the change with no direct test, which the coverage ratchet correctly caught.
 *
 * Three behaviours are worth pinning, and all three are refusals rather than
 * happy paths:
 *
 *  1. **OpenRegister absent → warn and return.** humaniq owns no tables; with
 *     OpenRegister missing there is nothing to migrate and the migration must
 *     not be attempted, let alone throw. An upgrade that dies here would take
 *     the whole instance down for a data fix that was never applicable.
 *  2. **A throwing migration is caught, warned and logged — never rethrown.**
 *     Same reason: `IRepairStep::run()` throwing aborts `occ upgrade`. A
 *     failed dialect migration is a data problem to fix afterwards, not a
 *     reason to leave the instance un-upgraded.
 *  3. **The report reaches the operator**, per-schema counts AND per-row skip
 *     reasons. A migration that rewrites nothing logs identically to one that
 *     had nothing to do, so the counts are the only thing distinguishing them.
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
 * @spec openspec/changes/archive/2026-08-20-hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Repair;

use OCA\Humaniq\Repair\MigrateAssetDialect;
use OCA\Humaniq\Service\AssetDialectMigrationService;
use OCA\Humaniq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for MigrateAssetDialect.
 *
 * @spec openspec/changes/archive/2026-08-20-hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-008
 */
class MigrateAssetDialectTest extends TestCase {

	/**
	 * A fake IOutput that records what the step told the operator.
	 *
	 * @return IOutput An IOutput double exposing `infos` and `warnings` arrays.
	 *         Named `outputDouble` because PHPUnit\Framework\TestCase::output()
	 *         is final and cannot be overridden.
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
			 * @param int $step The step reached.
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
	 * A SettingsService double reporting OpenRegister's availability.
	 *
	 * @param bool $available What `isOpenRegisterAvailable()` returns.
	 *
	 * @return SettingsService The double.
	 */
	private function settings(bool $available): SettingsService {
		$double = $this->createMock(SettingsService::class);
		$double->method('isOpenRegisterAvailable')->willReturn($available);
		return $double;
	}//end settings()

	/**
	 * The step names itself, so an operator reading `occ upgrade` output can
	 * tell which repair step spoke.
	 *
	 * @return void
	 */
	public function testStepIsNamed(): void {
		$step = new MigrateAssetDialect(
			$this->createMock(AssetDialectMigrationService::class),
			$this->settings(true),
			$this->createMock(LoggerInterface::class)
		);

		self::assertStringContainsString('Asset', $step->getName());
	}//end testStepIsNamed()

	/**
	 * REFUSAL 1. With OpenRegister absent the step warns and returns without
	 * touching the migration at all — humaniq owns no tables, so there is nothing
	 * to migrate, and an upgrade must not die over a data fix that does not
	 * apply.
	 *
	 * @return void
	 */
	public function testOpenRegisterAbsentSkipsWithoutRunningTheMigration(): void {
		$migration = $this->createMock(AssetDialectMigrationService::class);
		$migration->expects(self::never())->method('migrate');

		$out = $this->outputDouble();
		(new MigrateAssetDialect($migration, $this->settings(false), $this->createMock(LoggerInterface::class)))->run($out);

		self::assertCount(1, $out->warnings);
		self::assertStringContainsString('OpenRegister', $out->warnings[0]);
		self::assertSame([], $out->infos);
	}//end testOpenRegisterAbsentSkipsWithoutRunningTheMigration()

	/**
	 * REFUSAL 2. A throwing migration is caught, warned and logged — never
	 * rethrown. `IRepairStep::run()` throwing aborts `occ upgrade`, and a
	 * failed dialect migration is a data problem to fix afterwards, not a
	 * reason to leave the instance un-upgraded.
	 *
	 * @return void
	 */
	public function testAThrowingMigrationDoesNotAbortTheUpgrade(): void {
		$migration = $this->createMock(AssetDialectMigrationService::class);
		$migration->method('migrate')->willThrowException(new \RuntimeException('register unreachable'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');

		$out = $this->outputDouble();
		(new MigrateAssetDialect($migration, $this->settings(true), $logger))->run($out);

		self::assertCount(1, $out->warnings);
		self::assertStringContainsString('register unreachable', $out->warnings[0]);
	}//end testAThrowingMigrationDoesNotAbortTheUpgrade()

	/**
	 * The per-schema counts AND every per-row skip reason reach the operator.
	 *
	 * The counts are the only thing separating "rewrote nothing because there
	 * was nothing to do" from "rewrote nothing because every row was refused",
	 * and this migration has produced both.
	 *
	 * @return void
	 */
	public function testReportCountsAndSkipReasonsAreBothSurfaced(): void {
		$migration = $this->createMock(AssetDialectMigrationService::class);
		$migration->method('migrate')->willReturn([
			'Asset' => [
				'inspected'      => 4,
				'rewritten'      => 3,
				'alreadyCurrent' => 1,
				'skipped'        => 0,
				'skipReasons'    => [],
			],
			'AssetAssignment' => [
				'inspected'      => 2,
				'rewritten'      => 0,
				'alreadyCurrent' => 1,
				'skipped'        => 1,
				'skipReasons'    => [['id' => 'aa-1', 'reason' => 'conflicting values, refusing to guess']],
			],
		]);

		$out = $this->outputDouble();
		(new MigrateAssetDialect($migration, $this->settings(true), $this->createMock(LoggerInterface::class)))->run($out);

		$joined = implode("\n", $out->infos);
		self::assertStringContainsString('Asset: inspected=4 rewritten=3 alreadyCurrent=1 skipped=0', $joined);
		self::assertStringContainsString('AssetAssignment: inspected=2 rewritten=0 alreadyCurrent=1 skipped=1', $joined);
		self::assertStringContainsString('skipped aa-1: conflicting values, refusing to guess', $joined);
		self::assertSame([], $out->warnings);
	}//end testReportCountsAndSkipReasonsAreBothSurfaced()
}//end class
