<?php

/**
 * Unit tests for the occ commands that print one outcome line per processed item.
 *
 * Pins, for the leave and interview calendar syncs, the GL-post and net-pay
 * runs, the compensation effectuation and the offer-signature sync: the option
 * read and passed to the service, the heading, the empty-run line, every
 * outcome line (failures as errors), the summary and the exit code.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Command;

use OCA\Humaniq\Command\CalendarSyncCommand;
use OCA\Humaniq\Command\CompEffectuateCommand;
use OCA\Humaniq\Command\GlPostRunCommand;
use OCA\Humaniq\Command\InterviewCalendarSyncCommand;
use OCA\Humaniq\Command\NetPayRunCommand;
use OCA\Humaniq\Command\OfferSyncSignatureCommand;
use OCA\Humaniq\Service\CompAdjustmentService;
use OCA\Humaniq\Service\InterviewCalendarService;
use OCA\Humaniq\Service\LeaveCalendarService;
use OCA\Humaniq\Service\OfferEsignService;
use OCA\Humaniq\Service\PayrollGLPostService;
use OCA\Humaniq\Service\PayrollNetPayService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The outcome-reporting commands print and exit exactly as pinned.
 */
class OutcomeCommandsTest extends TestCase {

	/**
	 * Calendar sync outcomes: a run-level line and per-source lines.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function syncOutcomes(): array {
		return [
			['type' => null, 'sourceId' => null, 'status' => 'skipped-no-calendar', 'message' => 'geen agenda'],
			['type' => 'LeaveRequest', 'sourceId' => 'lr-1', 'status' => 'upserted', 'message' => 'ok'],
			['type' => 'SickLeaveCase', 'sourceId' => null, 'status' => 'failed', 'message' => 'kapot'],
		];

	}//end syncOutcomes()

	/**
	 * The two calendar syncs.
	 *
	 * @return void
	 */
	public function testCalendarSyncs(): void {
		foreach ([[LeaveCalendarService::class, CalendarSyncCommand::class, 'leave'], [InterviewCalendarService::class, InterviewCalendarSyncCommand::class, 'interview']] as [$serviceClass, $commandClass, $kind]) {
			$service = $this->createMock($serviceClass);
			$service->expects(self::exactly(2))->method('sync')
				->willReturnCallback(static fn (?string $from): array => ($from === '2026-01-01' ? self::syncOutcomes() : [self::syncOutcomes()[1]]));

			$tester = new CommandTester(new $commandClass($service));
			self::assertSame(1, $tester->execute(['--from' => ' 2026-01-01 ']));
			self::assertSame(
				"Humaniq ".$kind." calendar sync\n"
				."  run: skipped-no-calendar — geen agenda\n"
				."  LeaveRequest lr-1: upserted — ok\n"
				."  run: failed — kapot\n"
				."  3 uitkomst(en), 1 mislukt.\n",
				$tester->getDisplay()
			);

			self::assertSame(0, $tester->execute(['--from' => '  ']));
			self::assertStringEndsWith("  1 uitkomst(en), 0 mislukt.\n", $tester->getDisplay());
		}

	}//end testCalendarSyncs()

	/**
	 * The GL-post and net-pay runs.
	 *
	 * @return void
	 */
	public function testPayrollRunHandOffs(): void {
		$cases = [
			[PayrollGLPostService::class, 'postApprovedRuns', GlPostRunCommand::class, 'Humaniq payroll GL-post', 'goedgekeurde'],
			[PayrollNetPayService::class, 'processPayableRuns', NetPayRunCommand::class, 'Humaniq payroll net pay', 'betaalbare'],
		];
		foreach ($cases as [$serviceClass, $method, $commandClass, $heading, $adjective]) {
			$service = $this->createMock($serviceClass);
			$service->method($method)->willReturnCallback(
				static fn (?string $period): array => match ($period) {
					'2026-01' => [
						['runId' => 'run-1', 'status' => 'posted', 'message' => 'ok'],
						['runId' => 'run-2', 'status' => 'failed', 'message' => 'kapot'],
					],
					'2026-02' => [['runId' => 'run-3', 'status' => 'skipped-no-shillinq', 'message' => 'geen shillinq']],
					default => [],
				}
			);

			$tester = new CommandTester(new $commandClass($service));
			self::assertSame(1, $tester->execute(['--period' => '2026-01']));
			self::assertSame($heading."\n  run run-1: posted — ok\n  run run-2: failed — kapot\n  2 run(s) verwerkt, 1 mislukt.\n", $tester->getDisplay());

			self::assertSame(0, $tester->execute(['--period' => '2026-02']));
			self::assertStringEndsWith("  1 run(s) verwerkt, 0 mislukt.\n", $tester->getDisplay());

			self::assertSame(0, $tester->execute(['--period' => '2026-03']));
			self::assertSame($heading."\n  geen ".$adjective." loonruns geselecteerd voor periode 2026-03.\n", $tester->getDisplay());

			self::assertSame(0, $tester->execute([]));
			self::assertSame($heading."\n  geen ".$adjective." loonruns geselecteerd.\n", $tester->getDisplay());
		}

	}//end testPayrollRunHandOffs()

	/**
	 * The compensation-cycle effectuation.
	 *
	 * @return void
	 */
	public function testCompEffectuate(): void {
		$service = $this->createMock(CompAdjustmentService::class);
		$service->expects(self::exactly(3))->method('effectuateCycle')->willReturnCallback(
			static fn (string $cycle, ?string $date, bool $dryRun): array => match ([$cycle, $date, $dryRun]) {
				['c-1', '2026-04-01', true] => [
					['adjustmentId' => 'adj-1', 'status' => 'effectuated', 'message' => 'ok'],
					['status' => 'failed', 'message' => 'kapot'],
				],
				['c-1', null, false] => [['adjustmentId' => 'adj-2', 'status' => 'not-due', 'message' => 'later']],
				default => [],
			}
		);

		$tester = new CommandTester(new CompEffectuateCommand($service));
		self::assertSame(1, $tester->execute(['--cycle' => ' ']));
		self::assertSame("--cycle is verplicht.\n", $tester->getDisplay());

		self::assertSame(1, $tester->execute(['--cycle' => ' c-1 ', '--date' => '2026-04-01', '--dry-run' => true]));
		self::assertSame(
			"Humaniq compensation-cycle effectuation (dry-run)\n  aanpassing adj-1: effectuated — ok\n  aanpassing onbekend: failed — kapot\n  2 aanpassing(en) verwerkt, 1 mislukt.\n",
			$tester->getDisplay()
		);

		self::assertSame(0, $tester->execute(['--cycle' => 'c-1', '--date' => ' ']));
		self::assertSame("Humaniq compensation-cycle effectuation\n  aanpassing adj-2: not-due — later\n  1 aanpassing(en) verwerkt, 0 mislukt.\n", $tester->getDisplay());

		self::assertSame(0, $tester->execute(['--cycle' => 'c-2']));
		self::assertSame("Humaniq compensation-cycle effectuation\n  geen aanpassingen gevonden voor cyclus c-2.\n", $tester->getDisplay());

	}//end testCompEffectuate()

	/**
	 * The offer-signature sync, where a usage error also fails.
	 *
	 * @return void
	 */
	public function testOfferSignatureSync(): void {
		$service = $this->createMock(OfferEsignService::class);
		$service->method('syncSignatureStatus')->willReturnCallback(
			static fn (?string $applicationId): array => match ($applicationId) {
				null => [
					['applicationId' => 'app-1', 'status' => 'signed', 'message' => 'ok'],
					['applicationId' => 'app-2', 'status' => 'usage-error', 'message' => 'fout'],
				],
				'app-3' => [['applicationId' => 'app-3', 'status' => 'pending', 'message' => 'wacht']],
				default => [],
			}
		);

		$tester = new CommandTester(new OfferSyncSignatureCommand($service));
		self::assertSame(1, $tester->execute(['--application' => ' ']));
		self::assertSame(
			"Humaniq offer-signature sync\n  application app-1: signed — ok\n  application app-2: usage-error — fout\n  2 sollicitatie(s) verwerkt, 1 mislukt.\n",
			$tester->getDisplay()
		);

		self::assertSame(0, $tester->execute(['--application' => ' app-3 ']));
		self::assertStringEndsWith("  1 sollicitatie(s) verwerkt, 0 mislukt.\n", $tester->getDisplay());

		self::assertSame(0, $tester->execute(['--application' => 'app-4']));
		self::assertSame("Humaniq offer-signature sync\n  geen sollicitaties met een actieve e-handtekeningaanvraag gevonden.\n", $tester->getDisplay());

	}//end testOfferSignatureSync()
}//end class
