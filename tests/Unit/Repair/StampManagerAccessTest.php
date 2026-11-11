<?php

/**
 * Unit tests for the StampManagerAccess repair step (mss-team-scope
 * REQ-MSS-009): the backfill runs once, reports per schema, and runs again
 * while a record failed.
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
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Repair;

use OCA\Humaniq\Repair\StampManagerAccess;
use OCA\Humaniq\Service\ManagerAccessStamps;
use OCA\Humaniq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StampManagerAccessTest extends TestCase {

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

	private function appConfig(bool $done): IAppConfig {
		$double = $this->createMock(IAppConfig::class);
		$double->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false): bool => ($app === 'humaniq' && $key === StampManagerAccess::DONE_KEY) ? $done : $default
		);

		return $double;
	}//end appConfig()

	private function step(ManagerAccessStamps $stamps, IAppConfig $config): StampManagerAccess {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		return new StampManagerAccess($stamps, $settings, $this->createMock(LoggerInterface::class), $config);
	}//end step()

	public function testACleanPassReportsAndSetsTheDoneMarker(): void {
		$stamps = $this->createMock(ManagerAccessStamps::class);
		$stamps->expects(self::once())->method('run')->with(false)->willReturn(['SickLeaveCase' => ['inspected' => 3, 'changed' => 2, 'failed' => 0]]);
		$config = $this->appConfig(false);
		$config->expects(self::once())->method('setValueBool')->with('humaniq', 'manager_access_stamp_done', true);

		$out = $this->outputDouble();
		$this->step($stamps, $config)->run($out);

		self::assertSame(['SickLeaveCase: inspected=3 stamped=2 failed=0'], $out->infos);
		self::assertSame([], $out->warnings);
	}//end testACleanPassReportsAndSetsTheDoneMarker()

	public function testAPassWithFailuresLeavesTheMarkerUnset(): void {
		$stamps = $this->createMock(ManagerAccessStamps::class);
		$stamps->method('run')->willReturn(['Employee' => ['inspected' => 2, 'changed' => 1, 'failed' => 1]]);
		$config = $this->appConfig(false);
		$config->expects(self::never())->method('setValueBool');

		$out = $this->outputDouble();
		$this->step($stamps, $config)->run($out);

		self::assertSame(['1 records could not be stamped; the step runs again on the next upgrade or repair.'], $out->warnings);
	}//end testAPassWithFailuresLeavesTheMarkerUnset()

	public function testTheStepRunsOnlyOnce(): void {
		$stamps = $this->createMock(ManagerAccessStamps::class);
		$stamps->expects(self::never())->method('run');

		$this->step($stamps, $this->appConfig(true))->run($this->outputDouble());
	}//end testTheStepRunsOnlyOnce()

}//end class
