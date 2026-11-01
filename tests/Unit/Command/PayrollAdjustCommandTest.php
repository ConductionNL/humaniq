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

}//end class
