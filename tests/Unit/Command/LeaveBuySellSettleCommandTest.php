<?php

/**
 * Leave Buy/Sell Settle Command Test
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

use OCA\Humaniq\Command\LeaveBuySellSettleCommand;
use OCA\Humaniq\Service\LeaveBuySellSettlementService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests for LeaveBuySellSettleCommand.
 *
 * @covers \OCA\Humaniq\Command\LeaveBuySellSettleCommand
 */
class LeaveBuySellSettleCommandTest extends TestCase {

	/**
	 * The mocked settlement service.
	 *
	 * @var LeaveBuySellSettlementService&MockObject
	 */
	private LeaveBuySellSettlementService&MockObject $service;

	/**
	 * The command under test.
	 *
	 * @var LeaveBuySellSettleCommand
	 */
	private LeaveBuySellSettleCommand $command;

	/**
	 * Build the command around a mocked service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(LeaveBuySellSettlementService::class);
		$this->command = new LeaveBuySellSettleCommand(service: $this->service);
	}//end setUp()

	/**
	 * Run the command with the given options.
	 *
	 * @param array<string, string> $options CLI options.
	 * @param BufferedOutput        $output  Output buffer.
	 *
	 * @return int The exit code.
	 */
	private function runWith(array $options, BufferedOutput $output): int {
		return $this->command->run(new ArrayInput($options, $this->command->getDefinition()), $output);
	}//end runWith()

	/**
	 * The command registers under its occ name with a required-value --id.
	 *
	 * @return void
	 */
	public function testConfigureDeclaresNameAndIdOption(): void {
		self::assertSame('humaniq:leave:settle', $this->command->getName());
		self::assertTrue($this->command->getDefinition()->getOption('id')->isValueRequired());
	}//end testConfigureDeclaresNameAndIdOption()

	/**
	 * A missing or blank --id is refused without calling the service.
	 *
	 * @return void
	 */
	public function testMissingIdIsRefused(): void {
		$this->service->expects(self::never())->method('settle');

		$output = new BufferedOutput();
		self::assertSame(1, $this->runWith([], $output));
		self::assertSame("--id is verplicht.\n", $output->fetch());

		$blank = new BufferedOutput();
		self::assertSame(1, $this->runWith(['--id' => '  '], $blank));
		self::assertSame("--id is verplicht.\n", $blank->fetch());
	}//end testMissingIdIsRefused()

	/**
	 * A settled transaction exits 0 and the trimmed id reaches the service.
	 *
	 * @return void
	 */
	public function testSettledExitsZero(): void {
		$this->service->expects(self::once())
			->method('settle')
			->with('tx-1')
			->willReturn(['transactionId' => 'tx-1', 'status' => 'settled', 'message' => '8 uur verrekend.']);
		$output = new BufferedOutput();

		self::assertSame(0, $this->runWith(['--id' => ' tx-1 '], $output));
		self::assertSame(
			"Humaniq leave buy/sell settlement\n  transactie tx-1: settled — 8 uur verrekend.\n",
			$output->fetch()
		);
	}//end testSettledExitsZero()

	/**
	 * An already-settled transaction is a no-op that still exits 0.
	 *
	 * @return void
	 */
	public function testAlreadySettledExitsZero(): void {
		$this->service->method('settle')
			->willReturn(['transactionId' => 'tx-2', 'status' => 'already-settled', 'message' => 'Al verrekend.']);
		$output = new BufferedOutput();

		self::assertSame(0, $this->runWith(['--id' => 'tx-2'], $output));
		self::assertStringContainsString('transactie tx-2: already-settled', $output->fetch());
	}//end testAlreadySettledExitsZero()

	/**
	 * A refused outcome exits 1; a missing transactionId prints "onbekend".
	 *
	 * @return void
	 */
	public function testRefusedOutcomeExitsOneAndUnknownIdIsLabelled(): void {
		$this->service->method('settle')
			->willReturn(['status' => 'refused', 'message' => 'Niet goedgekeurd.']);
		$output = new BufferedOutput();

		self::assertSame(1, $this->runWith(['--id' => 'tx-3'], $output));
		self::assertStringContainsString('  transactie onbekend: refused — Niet goedgekeurd.', $output->fetch());
	}//end testRefusedOutcomeExitsOneAndUnknownIdIsLabelled()

}//end class
