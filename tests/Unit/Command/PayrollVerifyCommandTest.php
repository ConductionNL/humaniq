<?php

/**
 * Unit tests for PayrollVerifyCommand.
 *
 * Pins the --period / --administration / --jurisdiction handling, the
 * no-runs, clean and violation reports, and the exit codes.
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

use OCA\Humaniq\Command\PayrollVerifyCommand;
use OCA\Humaniq\Service\RuleAuditService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * PayrollVerifyCommand over a RuleAuditService double.
 *
 * @covers \OCA\Humaniq\Command\PayrollVerifyCommand
 * @uses \OCA\Humaniq\Command\CommandOptions
 */
class PayrollVerifyCommandTest extends TestCase {

	/**
	 * --period is required.
	 *
	 * @return void
	 */
	public function testPeriodIsRequired(): void {
		$service = $this->createMock(RuleAuditService::class);
		$service->expects(self::never())->method('auditPayrollRunScope');

		$tester = new CommandTester(new PayrollVerifyCommand($service));

		self::assertSame(1, $tester->execute(['--period' => ' ']));
		self::assertSame("--period is verplicht (JJJJ-MM).\n", $tester->getDisplay());

	}//end testPeriodIsRequired()

	/**
	 * No runs in the period exits 1; the options reach the audit trimmed.
	 *
	 * @return void
	 */
	public function testNoRuns(): void {
		$service = $this->createMock(RuleAuditService::class);
		$service->expects(self::once())
			->method('auditPayrollRunScope')
			->with('2026-02', 'ADM-1', ['jurisdiction' => 'DE'])
			->willReturn(['runsChecked' => 0, 'payslipsChecked' => 0, 'violations' => [], 'mandatoryViolations' => 0]);

		$tester = new CommandTester(new PayrollVerifyCommand($service));

		self::assertSame(1, $tester->execute(['--period' => ' 2026-02 ', '--administration' => ' ADM-1 ', '--jurisdiction' => 'DE']));
		self::assertSame(
			"Humaniq payroll verify\n  periode           : 2026-02\n  runs gecontroleerd: 0\n  loonstroken       : 0\n  geen loonruns gevonden voor deze periode.\n",
			$tester->getDisplay()
		);

	}//end testNoRuns()

	/**
	 * A clean run exits 0; a blank administration reaches the audit as null.
	 *
	 * @return void
	 */
	public function testCleanRun(): void {
		$service = $this->createMock(RuleAuditService::class);
		$service->expects(self::once())
			->method('auditPayrollRunScope')
			->with('2026-02', null, self::anything())
			->willReturn(['runsChecked' => 1, 'payslipsChecked' => 2, 'violations' => [], 'mandatoryViolations' => 0]);

		$tester = new CommandTester(new PayrollVerifyCommand($service));

		self::assertSame(0, $tester->execute(['--period' => '2026-02', '--administration' => '']));
		self::assertStringEndsWith("  loonstroken       : 2\n  geen overtredingen — de run voldoet aan het corpus.\n", $tester->getDisplay());

	}//end testCleanRun()

	/**
	 * Every violation is listed; any mandatory one exits 1, only advisory ones exit 0.
	 *
	 * @return void
	 */
	public function testViolations(): void {
		$mandatory = ['objectType' => 'Payslip', 'objectId' => 'ps-1', 'severity' => 'mandatory', 'ruleId' => 'nl-a', 'statement' => 'Fout.'];
		$advisory  = ['objectType' => 'PayrollRun', 'objectId' => 'run-1', 'severity' => 'recommended', 'ruleId' => 'nl-b', 'statement' => 'Let op.'];
		$service   = $this->createMock(RuleAuditService::class);
		$service->method('auditPayrollRunScope')->willReturnOnConsecutiveCalls(
			['runsChecked' => 1, 'payslipsChecked' => 1, 'violations' => [$mandatory, $advisory], 'mandatoryViolations' => 1],
			['runsChecked' => 1, 'payslipsChecked' => 1, 'violations' => [$advisory], 'mandatoryViolations' => 0]
		);

		$tester = new CommandTester(new PayrollVerifyCommand($service));

		self::assertSame(1, $tester->execute(['--period' => '2026-02']));
		self::assertStringEndsWith(
			"  loonstroken       : 1\n    Payslip ps-1 [mandatory] nl-a: Fout.\n    PayrollRun run-1 [recommended] nl-b: Let op.\n  2 overtreding(en), waarvan 1 verplicht.\n",
			$tester->getDisplay()
		);

		self::assertSame(0, $tester->execute(['--period' => '2026-02']));
		self::assertStringEndsWith("  1 overtreding(en), waarvan 0 verplicht.\n", $tester->getDisplay());

	}//end testViolations()
}//end class
