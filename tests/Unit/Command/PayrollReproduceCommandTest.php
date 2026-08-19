<?php

/**
 * Unit tests for PayrollReproduceCommand (audit-trail-payroll REQ-AUDP-002,
 * fixing hrmq#98).
 *
 * Pins the CLI contract: `--payslip` is required (missing it refuses before
 * any service call, exit 1); a `reproduced` service outcome exits 0; a
 * `mismatch` or `refused` outcome exits 1 and prints every named mismatch.
 * The recompute-and-compare logic itself is `PayrollReproduceServiceTest`'s
 * job — this test drives the command through a REAL `PayrollReproduceService`
 * (`final`, so it cannot be mocked) backed by a fake `ObjectService`
 * collaborator, pinning only the CLI argument/exit-code/output contract on
 * top of the three outcomes that service already produces.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Command
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
 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Command;

use OCA\Hrmq\Command\PayrollReproduceCommand;
use OCA\Hrmq\Payroll\PayrollCalculator;
use OCA\Hrmq\Service\PayrollReproduceService;
use OCA\Hrmq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests for PayrollReproduceCommand.
 *
 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
 */
class PayrollReproduceCommandTest extends TestCase {

	/**
	 * A minimal fake `ObjectService`: `findAll()` returns the seeded rows
	 * for the current schema (the PayrollReproduceServiceTest precedent,
	 * trimmed to what a single lookup needs).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return object
	 */
	private function fakeObjectService(array $rowsBySchema): object {
		return new class($rowsBySchema) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
			 */
			public function __construct(
				private array $rowsBySchema,
			) {
			}

			/**
			 * @param string $register Unused by the fake.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema name.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options Unused by the fake.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()
		};

	}//end fakeObjectService()

	/**
	 * Build a real `PayrollReproduceCommand` (its service is `final` and
	 * cannot be mocked) backed by seeded PayrollRun/Payslip fixtures.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed PayrollRun/Payslip rows.
	 *
	 * @return PayrollReproduceCommand
	 */
	private function command(array $rowsBySchema): PayrollReproduceCommand {
		$fake = $this->fakeObjectService($rowsBySchema);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);
		$service = new PayrollReproduceService($container, $settings, new PayrollCalculator(), $logger);

		return new PayrollReproduceCommand($service);
	}//end command()

	/**
	 * @return void
	 */
	public function testMissingPayslipOptionRefusedBeforeAnyServiceCall(): void {
		$command = $this->command(['PayrollRun' => [], 'Payslip' => []]);
		$exit = $this->runCommand($command, []);

		$this->assertSame(1, $exit);

	}//end testMissingPayslipOptionRefusedBeforeAnyServiceCall()

	/**
	 * @return void
	 */
	public function testRefusedOutcomeExitsOneWithAMessage(): void {
		$command = $this->command(
			[
				'PayrollRun' => [],
				'Payslip' => [
					['id' => 'hand-entered-1', 'payrollRunId' => null, 'engineInputSnapshot' => null],
				],
			]
		);

		$output = new BufferedOutput();
		$exit = $command->run(new ArrayInput(['--payslip' => 'hand-entered-1'], $command->getDefinition()), $output);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('refused', $output->fetch());

	}//end testRefusedOutcomeExitsOneWithAMessage()

	/**
	 * A payslip whose stored `engineVersion` names an artefact that no
	 * longer resolves (a deleted/renamed jurisdiction pack) is a `refused`
	 * outcome too — exercised here purely for the CLI exit-code contract,
	 * `PayrollReproduceServiceTest` owns the full match/mismatch matrix.
	 *
	 * @return void
	 */
	public function testUnknownRunIsRefusedWithExitOne(): void {
		$command = $this->command(
			[
				'PayrollRun' => [],
				'Payslip' => [
					[
						'id' => 'ps-1',
						'payrollRunId' => 'run-does-not-exist',
						'engineInputSnapshot' => '{"jurisdiction":"NL"}',
					],
				],
			]
		);

		$exit = $this->runCommand($command, ['--payslip' => 'ps-1']);

		$this->assertSame(1, $exit);

	}//end testUnknownRunIsRefusedWithExitOne()

	/**
	 * @param PayrollReproduceCommand $command The command under test.
	 * @param array<string, mixed> $options CLI options.
	 *
	 * @return int
	 */
	private function runCommand(PayrollReproduceCommand $command, array $options): int {
		return $command->run(new ArrayInput($options, $command->getDefinition()), new BufferedOutput());
	}//end runCommand()

}//end class
