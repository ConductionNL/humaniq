<?php

/**
 * Golden test for `occ humaniq:roster:check`.
 *
 * Runs the command with every option combination (roster, period, period with
 * an administration, neither) against a check that answers with an
 * unresolved register, nothing checked, a clean roster, and violations of
 * both severities. Each case's exit code, styled console output and the check call
 * it made are compared with tests/fixtures/golden/roster-check-command.json.
 * Set RECORD_GOLDEN=1 to rewrite that file.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Command
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
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Command;

use OCA\Humaniq\Command\RosterCheckCommand;
use OCA\Humaniq\Service\RosterCheckService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Golden cases for the roster check command.
 *
 * @covers \OCA\Humaniq\Command\RosterCheckCommand
 */
class RosterCheckCommandGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/roster-check-command.json';

	/**
	 * Every report the check can answer with.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function reports(): array {
		$violation = static fn (string $severity, string $id): array => ['objectType' => 'RosterAssignment', 'objectId' => $id, 'severity' => $severity, 'ruleId' => 'atw-' . $id, 'statement' => 'Rust ' . $id];
		return [
			'unresolved, no message' => ['registerResolved' => false],
			'unresolved, message' => ['registerResolved' => false, 'error' => 'Register weg.'],
			'nothing found' => ['rostersChecked' => 0, 'assignmentsChecked' => 0, 'violations' => [], 'mandatoryViolations' => 0],
			'clean' => ['registerResolved' => true, 'rostersChecked' => 2, 'assignmentsChecked' => 14, 'violations' => [], 'mandatoryViolations' => 0],
			'advisory only' => ['rostersChecked' => 1, 'assignmentsChecked' => 3, 'violations' => [$violation('advisory', 'a1')], 'mandatoryViolations' => 0],
			'mixed' => ['rostersChecked' => 3, 'assignmentsChecked' => 30, 'violations' => [$violation('mandatory', 'm1'), $violation('advisory', 'a2'), $violation('mandatory', 'm3')], 'mandatoryViolations' => 2],
		];

	}//end reports()

	/**
	 * Every option combination.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function options(): array {
		return [
			'roster' => ['--roster' => ' r-1 '],
			'roster wins over period' => ['--roster' => 'r-2', '--period' => '2026-06', '--administration' => 'ADM-1'],
			'period' => ['--period' => '2026-06'],
			'period and administration' => ['--period' => ' 2026-07 ', '--administration' => ' ADM-1 ', '--jurisdiction' => 'BE'],
			'period and blank administration' => ['--period' => '2026-07', '--administration' => '  '],
			'neither' => [],
			'blank roster' => ['--roster' => '  '],
		];

	}//end options()

	/**
	 * Every case, compared with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach (self::options() as $optionName => $options) {
			foreach (self::reports() as $reportName => $report) {
				$calls = [];
				$service = $this->createMock(RosterCheckService::class);
				$service->method('checkRoster')->willReturnCallback(
					static function (...$args) use (&$calls, $report): array {
						$calls[] = ['checkRoster', $args];
						return $report;
					}
				);
				$service->method('checkPeriod')->willReturnCallback(
					static function (...$args) use (&$calls, $report): array {
						$calls[] = ['checkPeriod', $args];
						return $report;
					}
				);
				$tester = new CommandTester(new RosterCheckCommand($service));
				$code = $tester->execute($options, ['decorated' => true]);
				$actual[$optionName . ' | ' . $reportName] = ['exit' => $code, 'output' => explode("\n", $tester->getDisplay()), 'calls' => $calls];
			}
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped('recorded');
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(array_keys($golden), array_keys($actual));
		foreach ($golden as $case => $expected) {
			$this->assertSame(json_encode($expected, $flags), json_encode($actual[$case], $flags), $case);
		}

	}//end testGolden()

}//end class
