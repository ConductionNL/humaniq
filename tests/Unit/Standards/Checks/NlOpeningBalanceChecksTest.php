<?php

/**
 * Unit tests for NlOpeningBalanceChecks.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlOpeningBalanceChecks;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Humaniq\Standards\Checks\NlOpeningBalanceChecks
 * @uses   \OCA\Humaniq\Payroll\PayPeriod
 */
class NlOpeningBalanceChecksTest extends TestCase {

	/**
	 * A payslip on or before the previous package's last period overlaps;
	 * the next period does not, also across grains (a four-week period that
	 * starts after the last month ends).
	 *
	 * @return void
	 */
	public function testAPeriodTheOldPackagePaidOverlaps(): void {
		$context = $this->context(lastPeriodInPrevious: '2026-06', firstPeriod: '2026-06');

		self::assertFalse(NlOpeningBalanceChecks::doesNotOverlap(payslip: $this->payslip('2026-06'), context: $context));
		self::assertFalse(NlOpeningBalanceChecks::doesNotOverlap(payslip: $this->payslip('2026-03'), context: $context));
		self::assertTrue(NlOpeningBalanceChecks::doesNotOverlap(payslip: $this->payslip('2026-07'), context: $context));
		// P07 runs from 15 June: inside June, so it overlaps; P08 starts 13 July.
		self::assertFalse(NlOpeningBalanceChecks::doesNotOverlap(payslip: $this->payslip('2026-P07'), context: $context));
		self::assertTrue(NlOpeningBalanceChecks::doesNotOverlap(payslip: $this->payslip('2026-P08'), context: $context));
		// Without a balance, or another year, there is nothing to overlap.
		self::assertTrue(NlOpeningBalanceChecks::doesNotOverlap(payslip: $this->payslip('2025-06'), context: $context));
		self::assertTrue(NlOpeningBalanceChecks::doesNotOverlap(payslip: $this->payslip('2026-06'), context: ['openingBalances' => ['byEmployeeYear' => [], 'firstPeriod' => []]]));
	}//end testAPeriodTheOldPackagePaidOverlaps()

	/**
	 * A first payslip of the year after the first period needs a balance; one
	 * in the first period, or a later payslip, does not.
	 *
	 * @return void
	 */
	public function testAMidYearStartWithoutABalanceIsFlaggedOnItsFirstPayslip(): void {
		$without = ['openingBalances' => ['byEmployeeYear' => [], 'firstPeriod' => ['emp-1' => [2026 => '2026-07']]]];
		self::assertFalse(NlOpeningBalanceChecks::hasBalanceWhenNeeded(payslip: $this->payslip('2026-07'), context: $without));
		self::assertTrue(NlOpeningBalanceChecks::hasBalanceWhenNeeded(payslip: $this->payslip('2026-08'), context: $without), 'only the first payslip of the year is judged');

		$with = $this->context(lastPeriodInPrevious: '2026-06', firstPeriod: '2026-07');
		self::assertTrue(NlOpeningBalanceChecks::hasBalanceWhenNeeded(payslip: $this->payslip('2026-07'), context: $with));

		foreach (['2026-01', '2026-P01', '2026-W01'] as $first) {
			$fromTheStart = ['openingBalances' => ['byEmployeeYear' => [], 'firstPeriod' => ['emp-1' => [2026 => $first]]]];
			self::assertTrue(NlOpeningBalanceChecks::hasBalanceWhenNeeded(payslip: $this->payslip($first), context: $fromTheStart), $first);
		}
	}//end testAMidYearStartWithoutABalanceIsFlaggedOnItsFirstPayslip()

	/**
	 * Both rules are registered on Payslip.
	 *
	 * @return void
	 */
	public function testBothRulesAreRegisteredOnPayslip(): void {
		self::assertSame(['nl-openingsbalans-overlap', 'nl-openingsbalans-ontbreekt'], array_keys(NlOpeningBalanceChecks::checks()['Payslip']));
		self::assertSame([], NlOpeningBalanceChecks::seedSpec());
	}//end testBothRulesAreRegisteredOnPayslip()

	/**
	 * A payslip of emp-1.
	 *
	 * @param string $period The period id.
	 *
	 * @return array<string, mixed>
	 */
	private function payslip(string $period): array {
		return ['employeeId' => 'emp-1', 'period' => $period];
	}//end payslip()

	/**
	 * An audit context with one 2026 balance for emp-1.
	 *
	 * @param string $lastPeriodInPrevious The previous package's last period.
	 * @param string $firstPeriod          emp-1's first humaniq period of 2026.
	 *
	 * @return array<string, mixed>
	 */
	private function context(string $lastPeriodInPrevious, string $firstPeriod): array {
		return [
			'openingBalances' => [
				'byEmployeeYear' => ['emp-1' => [2026 => ['employeeId' => 'emp-1', 'year' => 2026, 'lastPeriodInPrevious' => $lastPeriodInPrevious]]],
				'firstPeriod' => ['emp-1' => [2026 => $firstPeriod]],
			],
		];
	}//end context()
}//end class
