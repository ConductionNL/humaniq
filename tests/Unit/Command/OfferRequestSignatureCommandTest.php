<?php

/**
 * Offer Request Signature Command Test
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

use OCA\Humaniq\Command\OfferRequestSignatureCommand;
use OCA\Humaniq\Service\OfferEsignService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests for OfferRequestSignatureCommand.
 *
 * @covers \OCA\Humaniq\Command\OfferRequestSignatureCommand
 */
class OfferRequestSignatureCommandTest extends TestCase {

	/**
	 * The mocked offer service.
	 *
	 * @var OfferEsignService&MockObject
	 */
	private OfferEsignService&MockObject $service;

	/**
	 * The command under test.
	 *
	 * @var OfferRequestSignatureCommand
	 */
	private OfferRequestSignatureCommand $command;

	/**
	 * Build the command around a mocked service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(OfferEsignService::class);
		$this->command = new OfferRequestSignatureCommand(service: $this->service);
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
	 * The command registers under its occ name with a required-value option.
	 *
	 * @return void
	 */
	public function testConfigureDeclaresNameAndApplicationOption(): void {
		self::assertSame('humaniq:offer:request-signature', $this->command->getName());
		self::assertStringContainsString('KNOWN LIMITATION', $this->command->getDescription());
		$option = $this->command->getDefinition()->getOption('application');
		self::assertTrue($option->isValueRequired());
	}//end testConfigureDeclaresNameAndApplicationOption()

	/**
	 * Without --application the command refuses and never calls the service.
	 *
	 * @return void
	 */
	public function testMissingApplicationIsRefused(): void {
		$this->service->expects(self::never())->method('requestSignature');
		$output = new BufferedOutput();

		$exit = $this->runWith([], $output);

		self::assertSame(1, $exit);
		self::assertSame("--application is verplicht.\n", $output->fetch());
	}//end testMissingApplicationIsRefused()

	/**
	 * A whitespace-only --application counts as missing.
	 *
	 * @return void
	 */
	public function testBlankApplicationIsRefused(): void {
		$this->service->expects(self::never())->method('requestSignature');
		$output = new BufferedOutput();

		self::assertSame(1, $this->runWith(['--application' => '   '], $output));
		self::assertStringContainsString('--application is verplicht.', $output->fetch());
	}//end testBlankApplicationIsRefused()

	/**
	 * A requested outcome is printed and exits 0; the id is trimmed first.
	 *
	 * @return void
	 */
	public function testRequestedOutcomeExitsZero(): void {
		$this->service->expects(self::once())
			->method('requestSignature')
			->with('app-1')
			->willReturn(['applicationId' => 'app-1', 'status' => 'requested', 'message' => 'Ondertekenverzoek verstuurd.']);
		$output = new BufferedOutput();

		$exit = $this->runWith(['--application' => '  app-1 '], $output);

		self::assertSame(0, $exit);
		self::assertSame("application app-1: requested — Ondertekenverzoek verstuurd.\n", $output->fetch());
	}//end testRequestedOutcomeExitsZero()

	/**
	 * A skipped outcome is not a failure.
	 *
	 * @return void
	 */
	public function testSkippedOutcomeExitsZero(): void {
		$this->service->method('requestSignature')
			->willReturn(['applicationId' => 'app-2', 'status' => 'skipped-no-docudesk', 'message' => 'docudesk ontbreekt.']);
		$output = new BufferedOutput();

		self::assertSame(0, $this->runWith(['--application' => 'app-2'], $output));
		self::assertStringContainsString('app-2: skipped-no-docudesk', $output->fetch());
	}//end testSkippedOutcomeExitsZero()

	/**
	 * Failed and usage-error outcomes exit 1.
	 *
	 * @return void
	 */
	public function testFailureStatusesExitOne(): void {
		$this->service->method('requestSignature')
			->willReturnOnConsecutiveCalls(
				['applicationId' => 'app-3', 'status' => 'failed', 'message' => 'No authenticated user'],
				['applicationId' => 'app-3', 'status' => 'usage-error', 'message' => 'Onjuiste status.'],
			);

		$first = new BufferedOutput();
		self::assertSame(1, $this->runWith(['--application' => 'app-3'], $first));
		self::assertSame("application app-3: failed — No authenticated user\n", $first->fetch());

		$second = new BufferedOutput();
		self::assertSame(1, $this->runWith(['--application' => 'app-3'], $second));
		self::assertStringContainsString('usage-error — Onjuiste status.', $second->fetch());
	}//end testFailureStatusesExitOne()

}//end class
