<?php

/**
 * Unit tests for AssetsMigrateDialectCommand.
 *
 * Pins the CLI contract: the command delegates to
 * `AssetDialectMigrationService::migrate()` exactly once and prints the
 * per-schema counts plus every skip reason it returns. The mapping/
 * idempotency logic itself is `AssetDialectMigrationServiceTest`'s job.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Command
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
 * @spec openspec/changes/hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-008
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Command;

use OCA\Hrmq\Command\AssetsMigrateDialectCommand;
use OCA\Hrmq\Service\AssetDialectMigrationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests for AssetsMigrateDialectCommand.
 *
 * @spec openspec/changes/hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-008
 */
class AssetsMigrateDialectCommandTest extends TestCase {

	/**
	 * A full report drives the printed output: per-schema counts and every
	 * skip reason.
	 *
	 * @return void
	 */
	public function testDelegatesToServiceAndPrintsCountsAndSkipReasons(): void {
		$service = $this->createMock(AssetDialectMigrationService::class);
		$service->expects($this->once())->method('migrate')->willReturn([
			'Asset' => [
				'inspected' => 4,
				'rewritten' => 3,
				'alreadyCurrent' => 1,
				'skipped' => 3,
				'skipReasons' => [
					['id' => 'bus-1', 'reason' => 'status uitgegeven -> issued blocked by OpenRegister lifecycle guard: boom'],
				],
			],
			'AssetAssignment' => [
				'inspected' => 2,
				'rewritten' => 1,
				'alreadyCurrent' => 1,
				'skipped' => 0,
				'skipReasons' => [],
			],
		]);

		$command = new AssetsMigrateDialectCommand($service);
		$output = new BufferedOutput();
		$exit = $command->run(new ArrayInput([], $command->getDefinition()), $output);

		self::assertSame(0, $exit);
		$text = $output->fetch();
		self::assertStringContainsString('Asset', $text);
		self::assertStringContainsString('inspected=4', $text);
		self::assertStringContainsString('rewritten=3', $text);
		self::assertStringContainsString('alreadyCurrent=1', $text);
		self::assertStringContainsString('skipped=3', $text);
		self::assertStringContainsString('bus-1', $text);
		self::assertStringContainsString('AssetAssignment', $text);
		self::assertStringContainsString('inspected=2', $text);

	}//end testDelegatesToServiceAndPrintsCountsAndSkipReasons()

	/**
	 * A report with no skips at all is a clean, non-zero exit-0 run.
	 *
	 * @return void
	 */
	public function testEmptyMigrationExitsZero(): void {
		$service = $this->createMock(AssetDialectMigrationService::class);
		$service->method('migrate')->willReturn([
			'Asset' => ['inspected' => 0, 'rewritten' => 0, 'alreadyCurrent' => 0, 'skipped' => 0, 'skipReasons' => []],
			'AssetAssignment' => ['inspected' => 0, 'rewritten' => 0, 'alreadyCurrent' => 0, 'skipped' => 0, 'skipReasons' => []],
		]);

		$command = new AssetsMigrateDialectCommand($service);
		$exit = $command->run(new ArrayInput([], $command->getDefinition()), new BufferedOutput());

		self::assertSame(0, $exit);

	}//end testEmptyMigrationExitsZero()

}//end class
