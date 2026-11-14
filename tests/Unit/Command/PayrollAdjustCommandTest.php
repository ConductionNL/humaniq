<?php

/**
 * The occ retro-correction command passes every option on to the service.
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
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Command;

use OCA\Humaniq\Command\PayrollAdjustCommand;
use OCA\Humaniq\Service\RetroAdjustmentService;
use OCA\Humaniq\Service\RetroCorrection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * PayrollAdjustCommand over a RetroAdjustmentService double.
 *
 * @covers \OCA\Humaniq\Command\PayrollAdjustCommand
 * @uses   \OCA\Humaniq\Service\RetroCorrection
 * @uses \OCA\Humaniq\Command\CommandOptions
 */
class PayrollAdjustCommandTest extends TestCase {

	/**
	 * Gross, correction type, settlement period and apply all reach
	 * adjustFor(), the gross as a float.
	 *
	 * @return void
	 */
	public function testEveryOptionReachesTheService(): void {
		$service = $this->createMock(RetroAdjustmentService::class);
		$service->expects(self::once())
			->method('adjustFor')
			->with(
				self::callback(
					static function (RetroCorrection $correction): bool {
						self::assertSame(
							['2026-02', 'e1', 't1', 3800.0],
							[$correction->originalPeriod, $correction->employeeId, $correction->correctionRef, $correction->correctedGrossMonthlySalary]
						);
						return true;
					}
				),
				'x',
				'2026-04',
				true
			)
			->willReturn(
				[
					'adjustmentId' => 'adj-1',
					'originalPeriod' => '2026-02',
					'employeeId' => 'e1',
					'correctionRef' => 't1',
					'status' => 'applied',
					'message' => 'Correctie berekend en toegepast.',
					'idempotent' => false,
					'delta' => null,
				]
			);

		$tester = new CommandTester(new PayrollAdjustCommand($service));

		self::assertSame(
			0,
			$tester->execute(
				[
					'--original-period' => '2026-02',
					'--employee' => 'e1',
					'--correction-ref' => 't1',
					'--gross' => '3800',
					'--correction-type' => 'x',
					'--settlement-period' => '2026-04',
					'--apply' => true,
				]
			)
		);
		self::assertStringContainsString('applied', $tester->getDisplay());

	}//end testEveryOptionReachesTheService()

	/**
	 * A missing required option is refused before the service, the first missing one named.
	 *
	 * @return void
	 */
	public function testMissingRequiredOptionsAreRefusedInOrder(): void {
		$service = $this->createMock(RetroAdjustmentService::class);
		$service->expects(self::never())->method('adjustFor');
		$cases = [
			[[], '--original-period is verplicht (JJJJ-MM).'],
			[['--original-period' => ' '], '--original-period is verplicht (JJJJ-MM).'],
			[['--original-period' => '2026-02'], '--employee is verplicht.'],
			[['--original-period' => '2026-02', '--employee' => 'e1', '--correction-ref' => '  '], '--correction-ref is verplicht.'],
		];

		foreach ($cases as [$options, $message]) {
			$tester = new CommandTester(new PayrollAdjustCommand($service));
			self::assertSame(1, $tester->execute($options));
			self::assertSame($message."\n", $tester->getDisplay());
		}

	}//end testMissingRequiredOptionsAreRefusedInOrder()

	/**
	 * Blank or non-numeric optional values reach the service as null; the full report prints, with the delta.
	 *
	 * @return void
	 */
	public function testOptionalValuesAndTheFullReport(): void {
		$service = $this->createMock(RetroAdjustmentService::class);
		$service->expects(self::once())
			->method('adjustFor')
			->with(
				self::callback(
					static function (RetroCorrection $correction): bool {
						self::assertSame(
							['2026-02', 'e1', 't1', null],
							[$correction->originalPeriod, $correction->employeeId, $correction->correctionRef, $correction->correctedGrossMonthlySalary]
						);
						return true;
					}
				),
				null,
				null,
				false
			)
			->willReturn(
				[
					'adjustmentId' => null,
					'originalPeriod' => '2026-02',
					'employeeId' => 'e1',
					'correctionRef' => 't1',
					'status' => 'computed',
					'message' => 'Berekend.',
					'idempotent' => true,
					'delta' => ['gross' => 1, 'loonheffing' => 2.5, 'net' => -1.25, 'werknemersverzekeringen' => 0, 'zvw' => 0.1, 'volksverzekeringen' => 3, 'vakantiegeldReserved' => 0.08],
					'engineVersion' => 'nl-2026',
					'settlementPeriod' => '2026-04',
					'settlementLine' => 'regel',
				]
			);

		$tester = new CommandTester(new PayrollAdjustCommand($service));

		self::assertSame(0, $tester->execute(['--original-period' => ' 2026-02 ', '--employee' => ' e1', '--correction-ref' => 't1 ', '--gross' => 'veel', '--correction-type' => ' ', '--settlement-period' => '']));
		self::assertSame(
			"Humaniq TWK-correctie\n"
			."  originele periode : 2026-02\n"
			."  medewerker        : e1\n"
			."  correctionRef     : t1\n"
			."  correctie         : geen\n"
			."  status            : computed — Berekend.\n"
			."  idempotent        : ja (bestaande correctie bijgewerkt)\n"
			."  delta             : bruto 1.00 / loonheffing 2.50 / netto -1.25 / werkgeverslasten-wnv 0.00 / zvw 0.10 / vv 3.00 / vakantiegeld 0.08\n"
			."  engineVersion     : nl-2026\n"
			."  settlementPeriod  : 2026-04 (regel)\n",
			$tester->getDisplay()
		);

	}//end testOptionalValuesAndTheFullReport()

	/**
	 * Any status other than computed or applied exits 1.
	 *
	 * @return void
	 */
	public function testOtherStatusesExitOne(): void {
		$service = $this->createMock(RetroAdjustmentService::class);
		$service->method('adjustFor')->willReturn(
			['adjustmentId' => 'adj-2', 'originalPeriod' => 'p', 'employeeId' => 'e', 'correctionRef' => 'c', 'status' => 'failed', 'message' => 'm', 'idempotent' => false, 'delta' => null]
		);

		$tester = new CommandTester(new PayrollAdjustCommand($service));

		self::assertSame(1, $tester->execute(['--original-period' => 'p', '--employee' => 'e', '--correction-ref' => 'c', '--gross' => '12.5']));
		self::assertStringEndsWith("  idempotent        : nee (nieuwe correctie)\n", $tester->getDisplay());

	}//end testOtherStatusesExitOne()

}//end class
