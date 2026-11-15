<?php

/**
 * Unit tests for WkrAssessCommand.
 *
 * Pins the --all run (empty, mixed and clean outcomes), the --administration /
 * --year validation, the printed assessment and the exit codes.
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

use OCA\Humaniq\Command\WkrAssessCommand;
use OCA\Humaniq\Service\WkrService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * WkrAssessCommand over a WkrService double.
 *
 * @covers \OCA\Humaniq\Command\WkrAssessCommand
 */
class WkrAssessCommandTest extends TestCase {

	/**
	 * A successful outcome.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, mixed>
	 */
	private static function ok(string $administrationId): array {
		return [
			'status' => 'ok',
			'assessment' => [
				'administrationId' => $administrationId,
				'year' => '2026',
				'fiscaleLoonsom' => 100000,
				'vrijeRuimte' => 1920.5,
				'vrijeRuimteUsed' => 2000,
				'vrijeRuimteRemaining' => -79.5,
				'excess' => 79.5,
				'eindheffingDue' => 63.6,
				'status' => 'overschreden',
			],
		];

	}//end ok()

	/**
	 * The printed lines of one successful outcome.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return string
	 */
	private static function printed(string $administrationId): string {
		return "Humaniq WKR-beoordeling\n"
			."  administratie      : ".$administrationId."\n"
			."  jaar               : 2026\n"
			."  fiscale loonsom    : 100000.00\n"
			."  vrije ruimte       : 1920.50\n"
			."  vrije ruimte gebruikt: 2000.00\n"
			."  vrije ruimte resterend: -79.50\n"
			."  overschrijding      : 79.50\n"
			."  eindheffing verschuldigd: 63.60\n"
			."  status             : overschreden\n"
			."\n";

	}//end printed()

	/**
	 * --all with no pairs says so and exits 0.
	 *
	 * @return void
	 */
	public function testAllWithoutPairs(): void {
		$service = $this->createMock(WkrService::class);
		$service->expects(self::once())->method('assessAll')->willReturn([]);
		$service->expects(self::never())->method('assess');

		$tester = new CommandTester(new WkrAssessCommand($service));

		self::assertSame(0, $tester->execute(['--all' => true, '--administration' => 'ADM-1']));
		self::assertSame("Geen (administratie, jaar)-paren gevonden in de loonstroken.\n", $tester->getDisplay());

	}//end testAllWithoutPairs()

	/**
	 * --all prints every outcome and exits 1 when any failed.
	 *
	 * @return void
	 */
	public function testAllPrintsEveryOutcome(): void {
		$service = $this->createMock(WkrService::class);
		$service->method('assessAll')->willReturn([self::ok('ADM-1'), ['status' => 'error', 'message' => 'Kapot.'], self::ok('ADM-2')]);

		$tester = new CommandTester(new WkrAssessCommand($service));

		self::assertSame(1, $tester->execute(['--all' => true]));
		self::assertSame(self::printed('ADM-1')."Kapot.\n".self::printed('ADM-2'), $tester->getDisplay());

		$clean = $this->createMock(WkrService::class);
		$clean->method('assessAll')->willReturn([self::ok('ADM-1')]);
		self::assertSame(0, (new CommandTester(new WkrAssessCommand($clean)))->execute(['--all' => true]));

	}//end testAllPrintsEveryOutcome()

	/**
	 * A missing administration or an invalid year is refused before the service.
	 *
	 * @return void
	 */
	public function testAdministrationAndYearAreRequired(): void {
		$service = $this->createMock(WkrService::class);
		$service->expects(self::never())->method('assess');
		$cases = [
			[[], '--administration is verplicht (of gebruik --all).'],
			[['--administration' => '  ', '--year' => '2026'], '--administration is verplicht (of gebruik --all).'],
			[['--administration' => 'ADM-1'], '--year is verplicht en moet een geldig jaartal zijn.'],
			[['--administration' => 'ADM-1', '--year' => 'twee'], '--year is verplicht en moet een geldig jaartal zijn.'],
			[['--administration' => 'ADM-1', '--year' => '0'], '--year is verplicht en moet een geldig jaartal zijn.'],
			[['--administration' => 'ADM-1', '--year' => '-2026'], '--year is verplicht en moet een geldig jaartal zijn.'],
		];

		foreach ($cases as [$options, $message]) {
			$tester = new CommandTester(new WkrAssessCommand($service));
			self::assertSame(1, $tester->execute($options));
			self::assertSame($message."\n", $tester->getDisplay());
		}

	}//end testAdministrationAndYearAreRequired()

	/**
	 * One assessment: trimmed administration and integer year reach the service; the outcome sets the exit code.
	 *
	 * @return void
	 */
	public function testOneAssessment(): void {
		$service = $this->createMock(WkrService::class);
		$service->expects(self::exactly(2))
			->method('assess')
			->with('ADM-1', 2026)
			->willReturnOnConsecutiveCalls(self::ok('ADM-1'), ['status' => 'not-found', 'message' => 'Geen loonstroken.']);

		$tester = new CommandTester(new WkrAssessCommand($service));
		self::assertSame(0, $tester->execute(['--administration' => ' ADM-1 ', '--year' => '2026.7']));
		self::assertSame(self::printed('ADM-1'), $tester->getDisplay());

		self::assertSame(1, $tester->execute(['--administration' => 'ADM-1', '--year' => '2026']));
		self::assertSame("Geen loonstroken.\n", $tester->getDisplay());

	}//end testOneAssessment()
}//end class
