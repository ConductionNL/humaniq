<?php

/**
 * Documents Sync Signatures Command Test
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

use OCA\Humaniq\Command\DocumentsSyncSignaturesCommand;
use OCA\Humaniq\Service\HrDocumentSigningService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests for DocumentsSyncSignaturesCommand.
 *
 * @covers \OCA\Humaniq\Command\DocumentsSyncSignaturesCommand
 */
class DocumentsSyncSignaturesCommandTest extends TestCase {

	/**
	 * The mocked signing service.
	 *
	 * @var HrDocumentSigningService&MockObject
	 */
	private HrDocumentSigningService&MockObject $service;

	/**
	 * The command under test.
	 *
	 * @var DocumentsSyncSignaturesCommand
	 */
	private DocumentsSyncSignaturesCommand $command;

	/**
	 * Build the command around a mocked service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(HrDocumentSigningService::class);
		$this->command = new DocumentsSyncSignaturesCommand(service: $this->service);
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
	 * The command registers under its occ name with a --document option.
	 *
	 * @return void
	 */
	public function testConfigureDeclaresNameAndDocumentOption(): void {
		self::assertSame('humaniq:documents:sync-signatures', $this->command->getName());
		self::assertTrue($this->command->getDefinition()->getOption('document')->isValueRequired());
	}//end testConfigureDeclaresNameAndDocumentOption()

	/**
	 * Without --document every document is synced; nothing running exits 0.
	 *
	 * @return void
	 */
	public function testNoRunningRequestsExitsZero(): void {
		$this->service->expects(self::once())->method('syncSignatures')->with(null)->willReturn([]);
		$output = new BufferedOutput();

		self::assertSame(0, $this->runWith([], $output));
		self::assertSame("No generated documents with a running signing request.\n", $output->fetch());
	}//end testNoRunningRequestsExitsZero()

	/**
	 * The --document id is trimmed and each result is printed; all read means 0.
	 *
	 * @return void
	 */
	public function testSyncedResultsArePrintedAndExitZero(): void {
		$this->service->expects(self::once())
			->method('syncSignatures')
			->with('doc-7')
			->willReturn([
				['signingRequestId' => 'sr-1', 'status' => 'completed', 'message' => 'contract written'],
				['signingRequestId' => 'sr-2', 'status' => 'pending', 'message' => 'waiting'],
			]);
		$output = new BufferedOutput();

		self::assertSame(0, $this->runWith(['--document' => ' doc-7 '], $output));
		self::assertSame("  sr-1: completed (contract written)\n  sr-2: pending (waiting)\n", $output->fetch());
	}//end testSyncedResultsArePrintedAndExitZero()

	/**
	 * A not-found request makes the command exit 1, after printing all results.
	 *
	 * @return void
	 */
	public function testNotFoundRequestExitsOne(): void {
		$this->service->method('syncSignatures')->willReturn([
			['signingRequestId' => 'sr-3', 'status' => 'not-found', 'message' => 'unknown request'],
			['signingRequestId' => 'sr-4', 'status' => 'completed', 'message' => 'ok'],
		]);
		$output = new BufferedOutput();

		self::assertSame(1, $this->runWith([], $output));
		$text = $output->fetch();
		self::assertStringContainsString('  sr-3: not-found (unknown request)', $text);
		self::assertStringContainsString('  sr-4: completed (ok)', $text);
	}//end testNotFoundRequestExitsOne()

}//end class
