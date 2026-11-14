<?php

/**
 * AWF hours review figures tests
 *
 * Pins AwfHoursReview::figures directly: paid and contracted hours over the
 * year to date, the average contracted week of the low periods over the days
 * employed (overlapping contracts counted once), and the overrun.
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
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-102
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\AwfDayRange;
use OCA\Humaniq\Payroll\AwfHoursReview;
use PHPUnit\Framework\TestCase;

/**
 * The review figures of one employee.
 */
class AwfHoursReviewTest extends TestCase {

	/**
	 * Two overlapping contracts starting mid-month, a contract before the year
	 * and a period charged high: the figures as a whole.
	 *
	 * @return void
	 */
	public function testTheFiguresOfOverlappingContracts(): void {
		$contracts = [
			['hoursPerWeek' => 20, 'startDate' => '2026-02-16', 'endDate' => '2026-04-30'],
			['hoursPerWeek' => 8, 'startDate' => '2026-03-01'],
			['hoursPerWeek' => 40, 'startDate' => '2025-01-01', 'endDate' => '2025-12-31'],
			['hoursPerWeek' => 0, 'startDate' => '2026-01-01'],
		];
		$slips = [
			['period' => '2026-02', 'hours' => 50.0, 'low' => true],
			['period' => '2026-03', 'hours' => 130.0, 'low' => true],
			['period' => '2026-04', 'hours' => 120.5, 'low' => false],
		];

		self::assertSame(
			['paid' => 300.5, 'contract' => 279.82, 'averageHoursPerWeek' => 10, 'overrunPercent' => 7],
			AwfHoursReview::figures(contracts: $contracts, slips: $slips, lastPeriod: '2026-04')
		);
		self::assertSame(['paid' => 0.0, 'contract' => 0.0, 'averageHoursPerWeek' => 0, 'overrunPercent' => 0], AwfHoursReview::figures(contracts: [], slips: [], lastPeriod: '2026-03'));
	}

	/**
	 * The days a contract runs in a range, inclusive, open ends running on.
	 *
	 * @return void
	 */
	public function testOverlapDays(): void {
		$from = new \DateTimeImmutable('2026-05-01');
		$until = new \DateTimeImmutable('2026-05-31');

		self::assertSame(31, AwfHoursReview::overlapDays(contract: [], range: AwfDayRange::between(from: $from, until: $until)));
		self::assertSame(12, AwfHoursReview::overlapDays(contract: ['startDate' => '2026-05-20'], range: AwfDayRange::between(from: $from, until: $until)));
		self::assertSame(0, AwfHoursReview::overlapDays(contract: ['endDate' => '2026-04-30'], range: AwfDayRange::between(from: $from, until: $until)));
	}
}
