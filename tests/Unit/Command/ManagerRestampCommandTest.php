<?php

/**
 * ManagerRestampCommand unit tests
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
 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Command;

use OCA\Humaniq\Command\ManagerRestampCommand;
use OCA\Humaniq\Service\ManagerStampRepair;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The command passes the dry-run flag on and prints counts per schema.
 *
 * @covers \OCA\Humaniq\Command\ManagerRestampCommand
 */
class ManagerRestampCommandTest extends TestCase {

	/**
	 * Run the command, with or without --dry-run.
	 *
	 * @param bool $dryRun Whether to pass --dry-run.
	 *
	 * @return string The printed output.
	 */
	private function runWith(bool $dryRun): string {
		$repair = $this->createMock(ManagerStampRepair::class);
		$repair->expects(self::once())->method('run')->with($dryRun)->willReturn([
			'Expense' => ['inspected' => 4, 'open' => 2, 'changed' => 1, 'failed' => 0],
			'SideActivity' => ['inspected' => 0, 'open' => 0, 'changed' => 0, 'failed' => 0],
		]);

		$command = new ManagerRestampCommand($repair);
		$output = new BufferedOutput();
		$input = $dryRun === true ? ['--dry-run' => true] : [];
		self::assertSame(0, $command->run(new ArrayInput($input, $command->getDefinition()), $output));

		return $output->fetch();
	}//end runWith()

	/**
	 * A dry run says so and prints what would change.
	 *
	 * @return void
	 */
	public function testADryRunPrintsWhatWouldChange(): void {
		$text = $this->runWith(true);

		self::assertStringContainsString('dry run', $text);
		self::assertMatchesRegularExpression('/Expense\s+inspected=4 open=2 wouldRestamp=1 failed=0/', $text);
		self::assertMatchesRegularExpression('/SideActivity\s+inspected=0 open=0 wouldRestamp=0 failed=0/', $text);
	}//end testADryRunPrintsWhatWouldChange()

	/**
	 * A real run prints what changed.
	 *
	 * @return void
	 */
	public function testARunPrintsWhatChanged(): void {
		$text = $this->runWith(false);

		self::assertStringNotContainsString('dry run', $text);
		self::assertMatchesRegularExpression('/Expense\s+inspected=4 open=2 restamped=1 failed=0/', $text);
	}//end testARunPrintsWhatChanged()

	/**
	 * The command is named and takes the dry-run option.
	 *
	 * @return void
	 */
	public function testTheCommandIsNamed(): void {
		$command = new ManagerRestampCommand($this->createMock(ManagerStampRepair::class));

		self::assertSame('humaniq:manager:restamp', $command->getName());
		self::assertTrue($command->getDefinition()->hasOption('dry-run'));
	}//end testTheCommandIsNamed()

	/**
	 * A failed record makes the command exit 1 and say so.
	 *
	 * @return void
	 */
	public function testAFailedRecordExitsOne(): void {
		$repair = $this->createMock(ManagerStampRepair::class);
		$repair->method('run')->willReturn([
			'Expense' => ['inspected' => 4, 'open' => 2, 'changed' => 1, 'failed' => 1],
		]);

		$command = new ManagerRestampCommand($repair);
		$output = new BufferedOutput();

		self::assertSame(1, $command->run(new ArrayInput([], $command->getDefinition()), $output));
		$text = $output->fetch();
		self::assertMatchesRegularExpression('/Expense\s+inspected=4 open=2 restamped=1 failed=1/', $text);
		self::assertStringContainsString('1 records could not be restamped', $text);
	}//end testAFailedRecordExitsOne()

}//end class
