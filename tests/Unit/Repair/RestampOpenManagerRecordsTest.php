<?php

/**
 * RestampOpenManagerRecords unit tests
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
 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Repair;

use OCA\Humaniq\Repair\RestampOpenManagerRecords;
use OCA\Humaniq\Service\ManagerStampRepair;
use OCA\Humaniq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The repair step runs the restamp pass for real and never aborts an upgrade.
 *
 * @covers \OCA\Humaniq\Repair\RestampOpenManagerRecords
 */
class RestampOpenManagerRecordsTest extends TestCase {

	/**
	 * A fake IOutput that records what the step told the operator.
	 *
	 * @return IOutput An IOutput double exposing `infos` and `warnings` arrays.
	 *                 Named `outputDouble` because PHPUnit\Framework\TestCase::output()
	 *                 is final and cannot be overridden.
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
	 * An IAppConfig double holding the done marker.
	 *
	 * @param bool $done Whether the marker is set.
	 *
	 * @return IAppConfig The double.
	 */
	private function appConfig(bool $done): IAppConfig {
		$double = $this->createMock(IAppConfig::class);
		$double->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false): bool => ($app === 'humaniq' && $key === 'manager_restamp_done') ? $done : $default
		);

		return $double;
	}//end appConfig()

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
	 * The step has a name.
	 *
	 * @return void
	 */
	public function testStepIsNamed(): void {
		$step = new RestampOpenManagerRecords($this->createMock(ManagerStampRepair::class), $this->settings(true), $this->createMock(LoggerInterface::class), $this->appConfig(false));

		self::assertStringContainsString('manager', $step->getName());
	}//end testStepIsNamed()

	/**
	 * The step writes (no dry run) and reports the counts per schema.
	 *
	 * @return void
	 */
	public function testTheStepRestampsAndReportsCounts(): void {
		$repair = $this->createMock(ManagerStampRepair::class);
		$repair->expects(self::once())->method('run')->with(false)->willReturn([
			'EmployeeChangeRequest' => ['inspected' => 3, 'open' => 2, 'changed' => 1, 'failed' => 0],
		]);

		$out = $this->outputDouble();
		(new RestampOpenManagerRecords($repair, $this->settings(true), $this->createMock(LoggerInterface::class), $this->appConfig(false)))->run($out);

		self::assertSame(['EmployeeChangeRequest: inspected=3 open=2 restamped=1 failed=0'], $out->infos);
		self::assertSame([], $out->warnings);
	}//end testTheStepRestampsAndReportsCounts()

	/**
	 * Without OpenRegister the step warns and does not run the pass.
	 *
	 * @return void
	 */
	public function testOpenRegisterAbsentSkipsThePass(): void {
		$repair = $this->createMock(ManagerStampRepair::class);
		$repair->expects(self::never())->method('run');

		$out = $this->outputDouble();
		(new RestampOpenManagerRecords($repair, $this->settings(false), $this->createMock(LoggerInterface::class), $this->appConfig(false)))->run($out);

		self::assertCount(1, $out->warnings);
		self::assertSame([], $out->infos);
	}//end testOpenRegisterAbsentSkipsThePass()

	/**
	 * A failing pass is warned and logged, never rethrown.
	 *
	 * @return void
	 */
	public function testAFailingPassDoesNotAbortTheUpgrade(): void {
		$repair = $this->createMock(ManagerStampRepair::class);
		$repair->method('run')->willThrowException(new \RuntimeException('boom'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');

		$out = $this->outputDouble();
		(new RestampOpenManagerRecords($repair, $this->settings(true), $logger, $this->appConfig(false)))->run($out);

		self::assertCount(1, $out->warnings);
		self::assertStringContainsString('boom', $out->warnings[0]);
	}//end testAFailingPassDoesNotAbortTheUpgrade()

	/**
	 * A pass in which nothing failed sets the done marker.
	 *
	 * @return void
	 */
	public function testACleanPassSetsTheDoneMarker(): void {
		$repair = $this->createMock(ManagerStampRepair::class);
		$repair->method('run')->willReturn(['Expense' => ['inspected' => 1, 'open' => 1, 'changed' => 1, 'failed' => 0]]);
		$config = $this->appConfig(false);
		$config->expects(self::once())->method('setValueBool')->with('humaniq', 'manager_restamp_done', true);

		(new RestampOpenManagerRecords($repair, $this->settings(true), $this->createMock(LoggerInterface::class), $config))->run($this->outputDouble());
	}//end testACleanPassSetsTheDoneMarker()

	/**
	 * A pass with a failed record warns and leaves the marker unset, so the
	 * step runs again.
	 *
	 * @return void
	 */
	public function testAPassWithFailuresLeavesTheMarkerUnset(): void {
		$repair = $this->createMock(ManagerStampRepair::class);
		$repair->method('run')->willReturn([
			'Expense' => ['inspected' => 3, 'open' => 2, 'changed' => 1, 'failed' => 1],
			'SideActivity' => ['inspected' => 1, 'open' => 1, 'changed' => 0, 'failed' => 1],
		]);
		$config = $this->appConfig(false);
		$config->expects(self::never())->method('setValueBool');

		$out = $this->outputDouble();
		(new RestampOpenManagerRecords($repair, $this->settings(true), $this->createMock(LoggerInterface::class), $config))->run($out);

		self::assertSame(['Expense: inspected=3 open=2 restamped=1 failed=1', 'SideActivity: inspected=1 open=1 restamped=0 failed=1'], $out->infos);
		self::assertSame(['2 records could not be restamped; the step runs again on the next upgrade or repair.'], $out->warnings);
	}//end testAPassWithFailuresLeavesTheMarkerUnset()

	/**
	 * Once the marker is set, the step does not run the pass again.
	 *
	 * @return void
	 */
	public function testTheStepRunsOnlyOnce(): void {
		$repair = $this->createMock(ManagerStampRepair::class);
		$repair->expects(self::never())->method('run');

		$out = $this->outputDouble();
		(new RestampOpenManagerRecords($repair, $this->settings(true), $this->createMock(LoggerInterface::class), $this->appConfig(true)))->run($out);

		self::assertSame([], $out->infos);
		self::assertSame([], $out->warnings);
	}//end testTheStepRunsOnlyOnce()

}//end class
