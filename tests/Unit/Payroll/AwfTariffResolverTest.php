<?php

/**
 * Unit tests for AwfTariffResolver.
 *
 * Pins the Awf tariff the run, the retro recalculation and the audit share
 * (filings-premium-differentiation D1), with the thresholds of the Handboek
 * Loonheffingen 2026 (maart 2026), paragraaf 7.2 and 7.2.2: low for a
 * permanent written contract, for a signed BBL praktijkovereenkomst without
 * an uitzendbeding, and for an employee under 21 at the start of the period
 * paid at most 52 hours in a month (48 in a four-week period); high once a
 * low contract ends within two months of the start of the employment.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll
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
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-101
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\AwfHoursReview;
use OCA\Humaniq\Payroll\AwfTariffResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AwfTariffResolver.
 *
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-101
 */
class AwfTariffResolverTest extends TestCase {

	/**
	 * A contract fixture without an explicit tariff, overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function contract(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'ct-1',
				'employeeId' => 'emp-1',
				'type' => 'permanent',
				'writtenContract' => true,
				'startDate' => '2022-01-01',
				'endDate' => null,
				'hoursPerWeek' => 36.0,
			],
			$overrides
		);

	}//end contract()

	/**
	 * @return void
	 */
	public function testAPermanentWrittenContractIsLow(): void {
		$this->assertSame(['tariff' => 'low', 'basis' => 'contract'], AwfTariffResolver::resolve($this->contract(), '1990-04-12', '2026-06', 156.0));

	}//end testAPermanentWrittenContractIsLow()

	/**
	 * @return void
	 */
	public function testAFixedTermContractIsHigh(): void {
		$this->assertSame(['tariff' => 'high', 'basis' => 'flex'], AwfTariffResolver::resolve($this->contract(['type' => 'temporary']), '1990-04-12', '2026-06', 156.0));
		$this->assertSame(['tariff' => 'high', 'basis' => 'flex'], AwfTariffResolver::resolve($this->contract(['writtenContract' => false]), '1990-04-12', '2026-06', 156.0));

	}//end testAFixedTermContractIsHigh()

	/**
	 * @return void
	 */
	public function testASignedBblContractIsLowAndOneWithAnUitzendbedingIsHigh(): void {
		$bbl = $this->contract(['type' => 'bbl', 'writtenContract' => true, 'bpvOvereenkomstOndertekend' => true]);
		$this->assertSame(['tariff' => 'low', 'basis' => 'bbl'], AwfTariffResolver::resolve($bbl, '2005-03-01', '2026-06', 120.0));
		$this->assertSame('low', AwfTariffResolver::contractTariff($bbl));

		$agency = array_merge($bbl, ['uitzendbedingVanToepassing' => true]);
		$this->assertSame(['tariff' => 'high', 'basis' => 'flex'], AwfTariffResolver::resolve($agency, '1990-04-12', '2026-06', 120.0));

		$unsigned = array_merge($bbl, ['bpvOvereenkomstOndertekend' => false]);
		$this->assertSame('high', AwfTariffResolver::contractTariff($unsigned));

	}//end testASignedBblContractIsLowAndOneWithAnUitzendbedingIsHigh()

	/**
	 * A 19-year-old on a temporary contract: 10 hours a week is 43,33 paid
	 * hours in a month (10 x 13/3), under the 52-hour norm; 16 hours a week
	 * is 69,33, over it.
	 *
	 * @return void
	 */
	public function testAYoungPartTimerUnderTheHoursNormIsLow(): void {
		$temporary = $this->contract(['type' => 'temporary', 'hoursPerWeek' => 10.0]);
		$this->assertSame(['tariff' => 'low', 'basis' => 'young-part-time'], AwfTariffResolver::resolve($temporary, '2007-02-10', '2026-06', 43.33));
		$this->assertSame(['tariff' => 'low', 'basis' => 'young-part-time'], AwfTariffResolver::resolve($temporary, '2007-02-10', '2026-06', 52.0));

		$sixteen = $this->contract(['type' => 'temporary', 'hoursPerWeek' => 16.0]);
		$this->assertSame(['tariff' => 'high', 'basis' => 'flex'], AwfTariffResolver::resolve($sixteen, '2007-02-10', '2026-06', 69.33));

	}//end testAYoungPartTimerUnderTheHoursNormIsLow()

	/**
	 * Age is taken on the first day of the period: 21 on 1 June is no longer
	 * under 21 for June; 21 on 2 June still is.
	 *
	 * @return void
	 */
	public function testAgeIsTakenOnTheFirstDayOfThePeriod(): void {
		$temporary = $this->contract(['type' => 'temporary', 'hoursPerWeek' => 10.0]);
		$this->assertSame('high', AwfTariffResolver::resolve($temporary, '2005-06-01', '2026-06', 40.0)['tariff']);
		$this->assertSame('low', AwfTariffResolver::resolve($temporary, '2005-06-02', '2026-06', 40.0)['tariff']);
		$this->assertSame('high', AwfTariffResolver::resolve($temporary, null, '2026-06', 40.0)['tariff']);
		$this->assertSame('high', AwfTariffResolver::resolve($temporary, '2007-02-10', '2026-06', null)['tariff']);

	}//end testAgeIsTakenOnTheFirstDayOfThePeriod()

	/**
	 * @return void
	 */
	public function testTheFourWeekNormIs48Hours(): void {
		$this->assertSame(52.0, AwfTariffResolver::hoursNorm('2026-06'));
		$this->assertSame(48.0, AwfTariffResolver::hoursNorm('2026-P06'));
		$temporary = $this->contract(['type' => 'temporary', 'hoursPerWeek' => 12.5]);
		$this->assertSame('high', AwfTariffResolver::resolve($temporary, '2007-02-10', '2026-P06', 50.0)['tariff']);
		$this->assertSame('low', AwfTariffResolver::resolve($temporary, '2007-02-10', '2026-P06', 48.0)['tariff']);

	}//end testTheFourWeekNormIs48Hours()

	/**
	 * @return void
	 */
	public function testAnExplicitTariffWinsButTheYoungExceptionStillApplies(): void {
		$this->assertSame(['tariff' => 'low', 'basis' => 'explicit'], AwfTariffResolver::resolve($this->contract(['type' => 'temporary', 'awfTariff' => 'low']), '1990-04-12', '2026-06', 156.0));
		$this->assertSame(['tariff' => 'low', 'basis' => 'contract'], AwfTariffResolver::resolve($this->contract(['awfTariff' => 'low']), '1990-04-12', '2026-06', 156.0));
		$this->assertSame(['tariff' => 'high', 'basis' => 'explicit'], AwfTariffResolver::resolve($this->contract(['awfTariff' => 'high']), '1990-04-12', '2026-06', 156.0));
		$this->assertSame(['tariff' => 'low', 'basis' => 'young-part-time'], AwfTariffResolver::resolve($this->contract(['awfTariff' => 'high']), '2008-01-01', '2026-06', 30.0));

	}//end testAnExplicitTariffWinsButTheYoungExceptionStillApplies()

	/**
	 * Handboek 7.2.2 voorbeeld 1: in on 1 January, out on 28 February:
	 * review. Ending on 1 March, the day the two months are complete: no.
	 *
	 * @return void
	 */
	public function testAContractEndingWithinTwoMonthsEndsEarly(): void {
		$early = $this->contract(['startDate' => '2026-01-01', 'endDate' => '2026-02-28']);
		$this->assertTrue(AwfTariffResolver::endsEarly($early, [$early]));
		$sixWeeks = $this->contract(['startDate' => '2026-01-01', 'endDate' => '2026-02-11']);
		$this->assertTrue(AwfTariffResolver::endsEarly($sixWeeks, [$sixWeeks]));
		$this->assertFalse(AwfTariffResolver::endsEarly($this->contract(['startDate' => '2026-01-01', 'endDate' => '2026-03-01']), []));
		$this->assertFalse(AwfTariffResolver::endsEarly($this->contract(['startDate' => '2026-01-01', 'endDate' => null]), []));

		$this->assertSame(['tariff' => 'high', 'basis' => 'early-end'], AwfTariffResolver::resolve($early, '1990-04-12', '2026-02', 156.0, true));

	}//end testAContractEndingWithinTwoMonthsEndsEarly()

	/**
	 * Handboek 7.2.2 voorbeeld 2: a temporary contract from 1 January,
	 * followed without a gap by a permanent one from 1 February ending
	 * 15 March, is one employment of more than two months: no review.
	 *
	 * @return void
	 */
	public function testAContractFollowingAnotherWithoutAGapCountsFromTheFirstStart(): void {
		$first = $this->contract(['id' => 'ct-a', 'type' => 'temporary', 'startDate' => '2026-01-01', 'endDate' => '2026-01-31']);
		$second = $this->contract(['id' => 'ct-b', 'startDate' => '2026-02-01', 'endDate' => '2026-03-15']);
		$this->assertFalse(AwfTariffResolver::endsEarly($second, [$first, $second]));

		$gap = $this->contract(['id' => 'ct-a', 'type' => 'temporary', 'startDate' => '2026-01-01', 'endDate' => '2026-01-30']);
		$this->assertTrue(AwfTariffResolver::endsEarly($second, [$gap, $second]));

	}//end testAContractFollowingAnotherWithoutAGapCountsFromTheFirstStart()

	/**
	 * Handboek 7.2.3 stap 2: a month is hours a week x 13/3, a four-week
	 * period x 4, a part period x the calendar days it ran / 7, rounded to
	 * two decimals.
	 *
	 * @return void
	 */
	public function testContractHoursPerPeriod(): void {
		$this->assertSame(93.6, AwfHoursReview::contractHoursIn($this->contract(['hoursPerWeek' => 21.6]), '2026-03'));
		$this->assertSame(86.4, AwfHoursReview::contractHoursIn($this->contract(['hoursPerWeek' => 21.6]), '2026-P03'));
		// Voorbeeld 2: in on 20 May at 30 hours, 12 days in May.
		$this->assertSame(51.43, AwfHoursReview::contractHoursIn($this->contract(['hoursPerWeek' => 30.0, 'startDate' => '2026-05-20']), '2026-05'));
		$this->assertSame(0.0, AwfHoursReview::contractHoursIn($this->contract(['startDate' => '2026-07-01']), '2026-05'));

	}//end testContractHoursPerPeriod()
}//end class
