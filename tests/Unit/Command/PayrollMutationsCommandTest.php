<?php

/**
 * Unit tests for PayrollMutationsCommand.
 *
 * Pins the --to / --from handling, the refused diff and persist outcomes, the
 * printed report (unchanged lines left out) and the exit codes.
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

use OCA\Humaniq\Command\PayrollMutationsCommand;
use OCA\Humaniq\Service\PayrollMutationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * PayrollMutationsCommand over a PayrollMutationService double.
 *
 * @covers \OCA\Humaniq\Command\PayrollMutationsCommand
 * @uses \OCA\Humaniq\Command\CommandOptions
 */
class PayrollMutationsCommandTest extends TestCase {

	/**
	 * A mutation report.
	 *
	 * @return array<string, mixed>
	 */
	private static function report(): array {
		return [
			'fromPeriod' => null,
			'fromRunId' => null,
			'toPeriod' => '2026-02',
			'toRunId' => 'run-2',
			'administrationId' => 'ADM-1',
			'lines' => [
				['classification' => 'entered', 'employeeId' => 'emp-1', 'grossPayDelta' => 3800, 'nettoPayDelta' => 2900.5, 'loonheffingDelta' => 700, 'employerCostDelta' => 650.25],
				['classification' => 'unchanged', 'employeeId' => 'emp-2', 'grossPayDelta' => 0, 'nettoPayDelta' => 0, 'loonheffingDelta' => 0, 'employerCostDelta' => 0],
				['classification' => 'left', 'employeeId' => 'emp-3', 'grossPayDelta' => -100, 'nettoPayDelta' => -80, 'loonheffingDelta' => -20, 'employerCostDelta' => -15],
			],
			'enteredCount' => 1,
			'leftCount' => 1,
			'changedCount' => 0,
			'unchangedCount' => 1,
			'grossDelta' => 3700,
			'netDelta' => 2820.5,
			'loonheffingDelta' => 680,
			'employerCostDelta' => 635.25,
			'totalWageCostDelta' => 4335.25,
		];

	}//end report()

	/**
	 * The printed report.
	 *
	 * @return string
	 */
	private static function printed(): string {
		return "Humaniq payroll mutations\n"
			."  van periode    : (geen — eerste run) (-)\n"
			."  naar periode   : 2026-02 (run-2)\n"
			."  administratie  : ADM-1\n"
			."\n"
			."  entered    emp-1                                bruto +3800.00  netto +2900.50  loonheffing +700.00  werkgeverslasten +650.25\n"
			."  left       emp-3                                bruto -100.00  netto -80.00  loonheffing -20.00  werkgeverslasten -15.00\n"
			."\n"
			."  ingestroomd    : 1\n"
			."  uitgestroomd   : 1\n"
			."  gewijzigd      : 0\n"
			."  ongewijzigd    : 1\n"
			."  brutoΔ         : +3700.00\n"
			."  nettoΔ         : +2820.50\n"
			."  loonheffingΔ   : +680.00\n"
			."  werkgeverslastenΔ: +635.25\n"
			."  totale loonkostenΔ: +4335.25\n";

	}//end printed()

	/**
	 * --to is required.
	 *
	 * @return void
	 */
	public function testToIsRequired(): void {
		$service = $this->createMock(PayrollMutationService::class);
		$service->expects(self::never())->method('diff');

		$tester = new CommandTester(new PayrollMutationsCommand($service));

		self::assertSame(1, $tester->execute(['--to' => '  ']));
		self::assertSame("--to is verplicht (de te beoordelen loonrun-id).\n", $tester->getDisplay());

	}//end testToIsRequired()

	/**
	 * A refused diff prints its message and exits 1.
	 *
	 * @return void
	 */
	public function testRefusedDiff(): void {
		$service = $this->createMock(PayrollMutationService::class);
		$service->expects(self::once())->method('diff')->with('run-2', 'run-1')->willReturn(['status' => 'not-found', 'message' => 'Onbekende run.']);
		$service->expects(self::never())->method('persist');

		$tester = new CommandTester(new PayrollMutationsCommand($service));

		self::assertSame(1, $tester->execute(['--to' => ' run-2 ', '--from' => ' run-1 ', '--persist' => true]));
		self::assertSame("Onbekende run.\n", $tester->getDisplay());

	}//end testRefusedDiff()

	/**
	 * The report prints without the unchanged lines; without --persist nothing is stored.
	 *
	 * @return void
	 */
	public function testReportWithoutPersist(): void {
		$service = $this->createMock(PayrollMutationService::class);
		$service->expects(self::once())->method('diff')->with('run-2', null)->willReturn(['status' => 'ok', 'report' => self::report()]);
		$service->expects(self::never())->method('persist');

		$tester = new CommandTester(new PayrollMutationsCommand($service));

		self::assertSame(0, $tester->execute(['--to' => 'run-2', '--from' => ' ']));
		self::assertSame(self::printed(), $tester->getDisplay());

	}//end testReportWithoutPersist()

	/**
	 * --persist stores the report; a refused store exits 1 after the report.
	 *
	 * @return void
	 */
	public function testPersist(): void {
		$service = $this->createMock(PayrollMutationService::class);
		$service->method('diff')->willReturn(['status' => 'ok', 'report' => self::report()]);
		$service->expects(self::exactly(2))
			->method('persist')
			->with(self::report())
			->willReturnOnConsecutiveCalls(['status' => 'ok', 'reportId' => 'rep-1'], ['status' => 'error', 'message' => 'Opslaan mislukt.']);

		$tester = new CommandTester(new PayrollMutationsCommand($service));

		self::assertSame(0, $tester->execute(['--to' => 'run-2', '--persist' => true]));
		self::assertSame(self::printed()."  opgeslagen als : rep-1\n", $tester->getDisplay());

		self::assertSame(1, $tester->execute(['--to' => 'run-2', '--persist' => true]));
		self::assertSame(self::printed()."Opslaan mislukt.\n", $tester->getDisplay());

	}//end testPersist()
}//end class
