<?php

/**
 * Unit tests for PayrollRunCommand and ProformaPayslipCommand.
 *
 * Pins the options passed to the services, the refusals, every printed line
 * and the exit codes of both commands.
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

use InvalidArgumentException;
use OCA\Humaniq\Command\PayrollRunCommand;
use OCA\Humaniq\Command\ProformaPayslipCommand;
use OCA\Humaniq\Service\PayrollRunService;
use OCA\Humaniq\Service\ProformaPayslipService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The payroll-run and pro-forma commands print and exit exactly as pinned.
 *
 * @covers \OCA\Humaniq\Command\PayrollRunCommand
 * @covers \OCA\Humaniq\Command\ProformaPayslipCommand
 */
class PayrollRunAndProformaCommandsTest extends TestCase {

	/**
	 * --period is required; the run report prints every line; only calculated/exists exit 0.
	 *
	 * @return void
	 */
	public function testPayrollRun(): void {
		$service = $this->createMock(PayrollRunService::class);
		$service->expects(self::exactly(2))->method('runFor')->willReturnCallback(
			static fn (string $period, ?string $administration, bool $recalculate): array => ($administration === 'ADM-2' && $recalculate === true)
				? [
					'period' => $period, 'administrationId' => 'ADM-2', 'runId' => 'run-9', 'status' => 'calculated', 'message' => 'Berekend.',
					'computed' => [['employee' => 'Sanne', 'payslipId' => 'ps-1']],
					'skipped' => [['employee' => 'Kees', 'reason' => 'geen contract']],
					'totals' => ['totalGross' => 3800, 'totalLoonheffing' => 718.83, 'totalEmployerCharges' => 650.94, 'totalNet' => 3081.17],
				]
				: [
					'period' => $period, 'administrationId' => 'ADM-001', 'runId' => null, 'status' => 'refused', 'message' => 'Al geboekt.',
					'computed' => [], 'skipped' => [], 'totals' => null,
				]
		);

		$tester = new CommandTester(new PayrollRunCommand($service));
		self::assertSame(1, $tester->execute(['--period' => '  ']));
		self::assertSame("--period is verplicht (JJJJ-MM).\n", $tester->getDisplay());

		self::assertSame(0, $tester->execute(['--period' => ' 2026-02 ', '--administration' => ' ADM-2 ', '--recalculate' => true]));
		self::assertSame(
			"Humaniq payroll run\n"
			."  periode        : 2026-02\n"
			."  administratie  : ADM-2\n"
			."  run            : run-9\n"
			."  status         : calculated — Berekend.\n"
			."    berekend     : Sanne (loonstrook ps-1)\n"
			."    overgeslagen : Kees — geen contract\n"
			."  totalen        : bruto 3800.00 / loonheffing 718.83 / werkgeverslasten 650.94 / netto 3081.17\n",
			$tester->getDisplay()
		);

		self::assertSame(1, $tester->execute(['--period' => '2026-02', '--administration' => ' ']));
		self::assertSame("Humaniq payroll run\n  periode        : 2026-02\n  administratie  : ADM-001\n  run            : geen\n  status         : refused — Al geboekt.\n", $tester->getDisplay());

	}//end testPayrollRun()

	/**
	 * The pro-forma breakdown prints every figure; a refused input prints the refusal and exits 1.
	 *
	 * @return void
	 */
	public function testProformaPayslip(): void {
		$breakdown = [
			'input' => ['period' => '2026-03', 'table' => 'groen', 'bijzonder' => 250.0],
			'grossPay' => 3610.4, 'loonheffing' => 716.83, 'arbeidskorting' => 0.0, 'volksverzekeringen' => 200.5, 'zvw' => 220.2,
			'werknemersverzekeringen' => 400.1, 'employerCharges' => 620.75, 'vakantiegeldReserved' => 288.83, 'nettoPay' => 2893.57, 'appliedTaxRate' => 19.855,
		];
		$service = $this->createMock(ProformaPayslipService::class);
		$service->expects(self::exactly(3))->method('simulate')->willReturnCallback(
			static function (array $params) use ($breakdown): array {
				self::assertSame(['gross', 'table', 'loonheffingskorting', 'dateOfBirth', 'period', 'parttime', 'bijzonder', 'aof', 'whk'], array_keys($params));
				self::assertTrue($params['loonheffingskorting']);
				if ($params['gross'] === 'veel') {
					throw new InvalidArgumentException('Bruto maandsalaris moet een getal zijn.');
				}

				return ($params['bijzonder'] === '0') ? array_replace_recursive($breakdown, ['input' => ['bijzonder' => 0.0]]) : $breakdown;
			}
		);

		$tester = new CommandTester(new ProformaPayslipCommand($service));
		self::assertSame(0, $tester->execute(['--gross' => '4200', '--table' => 'groen', '--period' => '2026-03', '--bijzonder' => '250', '--aof' => 'hoog', '--whk' => '2.1', '--parttime' => '0.8', '--date-of-birth' => '1958-01-01']));
		$lines = "Humaniq pro-forma loonstrook (niets opgeslagen)\n"
			."  periode                 : 2026-03\n"
			."  tabel                   : groen\n"
			."  bruto                   : 3610.40\n"
			."  loonheffing             : 716.83\n"
			."  arbeidskorting          : 0.00\n"
			."  volksverzekeringen      : 200.50\n"
			."  zvw                     : 220.20\n"
			."  werknemersverzekeringen : 400.10\n"
			."  werkgeverslasten        : 620.75\n"
			."  vakantiegeldreservering : 288.83\n"
			."  netto                   : 2893.57\n"
			."  toegepast tarief        : 19.86%\n";
		self::assertSame($lines."  let op: bijzondere beloning is een combinedLoon-schatting, geen wettelijk bijzonder tarief.\n", $tester->getDisplay());

		self::assertSame(0, $tester->execute(['--gross' => '4200', '--bijzonder' => '0']));
		self::assertSame($lines, $tester->getDisplay());

		self::assertSame(1, $tester->execute(['--gross' => 'veel']));
		self::assertSame("Bruto maandsalaris moet een getal zijn.\n", $tester->getDisplay());

	}//end testProformaPayslip()
}//end class
