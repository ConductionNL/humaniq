<?php

/**
 * The occ year-transition preflight reports the same answer as the page.
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
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Command;

use OCA\Humaniq\Command\PayrollYearTransitionCommand;
use OCA\Humaniq\Payroll\PackRepository;
use OCA\Humaniq\Payroll\PackValidator;
use OCA\Humaniq\Service\YearTransitionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * PayrollYearTransitionCommand over YearTransitionService.
 */
class PayrollYearTransitionCommandTest extends TestCase {

	/**
	 * The bundled year: pack, tables, both bundled, self-test passed, exit 0.
	 *
	 * @return void
	 */
	public function testTheBundledYearIsReportedWithItsOrigins(): void {
		$tester = $this->tester();

		self::assertSame(0, $tester->execute(['--year' => '2026']));
		$out = $tester->getDisplay();
		self::assertMatchesRegularExpression('/pack\s+: nl-2026@\S+ \(bundled\)/', $out);
		self::assertMatchesRegularExpression('/tables\s+: nl-2026 \(bundled\)/', $out);
		self::assertStringContainsString('passed', $out);
	}//end testTheBundledYearIsReportedWithItsOrigins()

	/**
	 * A year with nothing to pay it with: exit 1, said plainly.
	 *
	 * @return void
	 */
	public function testAYearWithoutAPackFails(): void {
		$tester = $this->tester();

		self::assertSame(1, $tester->execute(['--year' => '2028']));
		self::assertStringContainsString('No pack resolves for NL 2028', $tester->getDisplay());
	}//end testAYearWithoutAPackFails()

	/**
	 * The command over the real bundled-only resolver.
	 *
	 * @return CommandTester
	 */
	private function tester(): CommandTester {
		return new CommandTester(new PayrollYearTransitionCommand(new YearTransitionService(new PackRepository(), new PackValidator())));
	}//end tester()

}//end class
