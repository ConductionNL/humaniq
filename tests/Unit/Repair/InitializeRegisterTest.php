<?php

/**
 * InitializeRegister repair step test
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
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Repair;

use OCA\Humaniq\Repair\InitializeRegister;
use OCA\Humaniq\Service\RegisterImporter;
use OCA\Humaniq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * What the repair step reports for each answer of the register import.
 *
 * @covers \OCA\Humaniq\Repair\InitializeRegister
 * @uses   \OCA\Humaniq\Service\SettingsService
 * @uses   \OCA\Humaniq\Service\RegisterImporter
 * @uses   \OCA\Humaniq\Service\InternalWriteMarker
 * @uses   \OCA\Humaniq\Service\RegisterConfigReader
 * @uses   \OCA\Humaniq\Service\OpenRegisterAvailability
 */
class InitializeRegisterTest extends TestCase {

	/**
	 * Without OpenRegister the step warns and imports nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItWarnsAndSkips(): void {
		$seen = $this->runStep(['installed' => false]);

		self::assertSame([['info', 'Initializing humaniq register...'], ['warning', 'OpenRegister is not installed or enabled. Skipping humaniq register import.']], $seen['output']);
		self::assertSame(['Humaniq: OpenRegister not available, skipping register initialization'], $seen['log']);
		self::assertSame([], $seen['forced']);
	}//end testWithoutOpenRegisterItWarnsAndSkips()

	/**
	 * A clean import reports its version, and the import is not forced.
	 *
	 * @return void
	 */
	public function testAnImportReportsItsVersion(): void {
		$seen = $this->runStep(['result' => ['registers' => [1], 'version' => '1.2.3']]);

		self::assertSame([['info', 'Initializing humaniq register...'], ['info', 'Humaniq register imported successfully (version: 1.2.3).']], $seen['output']);
		self::assertSame([false], $seen['forced']);
	}//end testAnImportReportsItsVersion()

	/**
	 * The version-unchanged skip is reported as up to date.
	 *
	 * @return void
	 */
	public function testAnUnchangedRegisterIsReportedUpToDate(): void {
		$seen = $this->runStep(['result' => []]);

		self::assertSame([['info', 'Initializing humaniq register...'], ['info', 'Humaniq register already up-to-date (version-unchanged skip).']], $seen['output']);
	}//end testAnUnchangedRegisterIsReportedUpToDate()

	/**
	 * An import with errors is reported as an issue.
	 *
	 * @return void
	 */
	public function testAnImportWithErrorsIsReportedAsAnIssue(): void {
		$seen = $this->runStep(['result' => ['errors' => ['boom']]]);

		self::assertSame([['info', 'Initializing humaniq register...'], ['warning', 'Humaniq register import issue: Configuration import completed with errors.']], $seen['output']);
	}//end testAnImportWithErrorsIsReportedAsAnIssue()

	/**
	 * Run the step over one scenario.
	 *
	 * @param array<string, mixed> $scenario Whether OpenRegister is installed and what the import answers.
	 *
	 * @return array{output: list<array{0: string, 1: string}>, log: list<string>, forced: list<bool>}
	 */
	private function runStep(array $scenario): array {
		$importer = new class($scenario['result'] ?? []) {
			/**
			 * @var list<bool>
			 */
			public array $forced = [];

			/**
			 * @param array<string, mixed> $result What the import answers.
			 */
			public function __construct(private array $result) {
			}

			/**
			 * @param string $appId The app id.
			 * @param array<mixed> $data The register config.
			 * @param string $version The version.
			 * @param bool $force Whether the import is forced.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->forced[] = $force;
				return $this->result;
			}
		};
		$logger = new class extends AbstractLogger {
			/**
			 * @var list<string>
			 */
			public array $lines = [];

			/**
			 * @param mixed $level The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = (string)$message;
			}
		};
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($scenario['installed'] ?? true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($importer);
		$output = [];
		$io = $this->createMock(IOutput::class);
		$io->method('info')->willReturnCallback(static function (string $message) use (&$output): void {
			$output[] = ['info', $message];
		});
		$io->method('warning')->willReturnCallback(static function (string $message) use (&$output): void {
			$output[] = ['warning', $message];
		});

		$settings = new SettingsService($this->createMock(IAppConfig::class), $appManager);
		(new InitializeRegister($settings, new RegisterImporter($settings, $container, new NullLogger()), $logger))->run($io);

		return ['output' => $output, 'log' => $logger->lines, 'forced' => $importer->forced];

	}//end runStep()

}//end class
