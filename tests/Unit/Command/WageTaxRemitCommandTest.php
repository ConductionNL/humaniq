<?php

/**
 * Wage Tax Remit Command Test
 *
 * The wiring of payroll-wage-tax-remittance-shillinq, asserted from the
 * caller: info.xml registers the command, and the command, on the real
 * service, puts the run's confirmed return into shillinq as a draft payable.
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

use OCA\Humaniq\Command\WageTaxRemitCommand;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\WageTaxRemittanceService;
use OCA\Humaniq\Tests\Unit\Service\WageTaxRemittanceServiceTest;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests for WageTaxRemitCommand.
 */
class WageTaxRemitCommandTest extends TestCase {

	/**
	 * The command runs the real service and the payable lands in shillinq.
	 *
	 * @return void
	 */
	public function testTheCommandHandsTheRunsReturnToShillinq(): void {
		$store = new FakeObjectStore();
		WageTaxRemittanceServiceTest::seedMay($store);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getWageTaxPayeeId')->willReturn('payee-belastingdienst');
		$settings->method('getGlPostAccountWageTaxLiability')->willReturn('1701');
		$service = new WageTaxRemittanceService(
			container: new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $store]),
			appManager: $appManager,
			settingsService: $settings,
			logger: new NullLogger(),
		);
		$command = new WageTaxRemitCommand(service: $service);
		$output = new BufferedOutput();

		$exit = $command->run(new ArrayInput(['--period' => '2026-05'], $command->getDefinition()), $output);

		self::assertSame(0, $exit);
		self::assertSame('humaniq:wagetax:remit', $command->getName());
		$store->setSchema('APTransaction');
		$payables = $store->findAll();
		self::assertCount(1, $payables);
		self::assertSame('1234000000006050', $payables[0]['invoiceNumber']);
		self::assertStringContainsString('created', $output->fetch());
	}//end testTheCommandHandsTheRunsReturnToShillinq()

	/**
	 * A failed hand-off makes the command exit 1.
	 *
	 * @return void
	 */
	public function testAFailedHandOffExitsOne(): void {
		$store = new FakeObjectStore();
		WageTaxRemittanceServiceTest::seedMay($store);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getWageTaxPayeeId')->willReturn('');
		$service = new WageTaxRemittanceService(
			container: new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $store]),
			appManager: $appManager,
			settingsService: $settings,
			logger: new NullLogger(),
		);
		$command = new WageTaxRemitCommand(service: $service);

		self::assertSame(1, $command->run(new ArrayInput([], $command->getDefinition()), new BufferedOutput()));
	}//end testAFailedHandOffExitsOne()

	/**
	 * Nextcloud only runs a command info.xml registers.
	 *
	 * @return void
	 */
	public function testInfoXmlRegistersTheCommand(): void {
		// Read the file as a string: under a booted Nextcloud (CI's pgsql cell)
		// OC::boot() blocks libxml's own file loading, so simplexml_load_file()
		// returns false there however correct info.xml is.
		$xml = simplexml_load_string((string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml'));
		self::assertNotFalse($xml);
		$commands = array_map('strval', iterator_to_array($xml->commands->command, false));
		self::assertContains(WageTaxRemitCommand::class, $commands);
	}//end testInfoXmlRegistersTheCommand()
}//end class
