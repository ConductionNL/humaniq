<?php

/**
 * Rules Seed Test-Data Command Test
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

use OCA\Humaniq\Command\RulesSeedTestDataCommand;
use OCA\Humaniq\Service\RuleTestDataSeeder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests for RulesSeedTestDataCommand.
 *
 * @covers \OCA\Humaniq\Command\RulesSeedTestDataCommand
 */
class RulesSeedTestDataCommandTest extends TestCase {

	/**
	 * The command reports every counter the seeder returns and exits 0.
	 *
	 * @return void
	 */
	public function testReportsSeederCounters(): void {
		$seeder = $this->createMock(RuleTestDataSeeder::class);
		$seeder->expects(self::once())->method('seed')->willReturn([
			'providerObjectsCreated' => 3,
			'providerFieldsAdded'    => 5,
			'alreadyCompliant'       => 12,
		]);
		$command = new RulesSeedTestDataCommand(seeder: $seeder);
		$output = new BufferedOutput();

		$exit = $command->run(new ArrayInput([], $command->getDefinition()), $output);

		self::assertSame(0, $exit);
		self::assertSame('humaniq:rules:seed-testdata', $command->getName());
		self::assertSame(
			"Humaniq rules test-data seeder\n"
			. "  provider objects created : 3\n"
			. "  provider fields backfilled : 5\n"
			. "  already compliant          : 12\n"
			. "Run occ humaniq:rules:audit to confirm 100% compliance.\n",
			$output->fetch()
		);
	}//end testReportsSeederCounters()

	/**
	 * Missing provider counters default to zero.
	 *
	 * @return void
	 */
	public function testMissingProviderCountersDefaultToZero(): void {
		$seeder = $this->createMock(RuleTestDataSeeder::class);
		$seeder->method('seed')->willReturn(['alreadyCompliant' => 0]);
		$command = new RulesSeedTestDataCommand(seeder: $seeder);
		$output = new BufferedOutput();

		self::assertSame(0, $command->run(new ArrayInput([], $command->getDefinition()), $output));
		$text = $output->fetch();
		self::assertStringContainsString('provider objects created : 0', $text);
		self::assertStringContainsString('provider fields backfilled : 0', $text);
		self::assertStringContainsString('already compliant          : 0', $text);
	}//end testMissingProviderCountersDefaultToZero()

}//end class
