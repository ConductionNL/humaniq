<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A wage period reads as a month only when the whole text is one.
 *
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\WagePeriod;
use PHPUnit\Framework\TestCase;

/**
 * WagePeriod.
 *
 * @covers \OCA\Humaniq\Service\WagePeriod
 */
class WagePeriodTest extends TestCase {

	/**
	 * Period texts and the month they read as, or null.
	 *
	 * @return array<string, array{0: string, 1: string|null, 2: string|null}>
	 */
	public static function periods(): array {
		return [
			'month' => ['2026-05', '2026-05', '2026-05-01'],
			'one-digit month' => ['2026-5', '2026-05', '2026-05-01'],
			'surrounding spaces' => [' 2026-12 ', '2026-12', '2026-12-01'],
			'January' => ['2027-01', '2027-01', '2027-01-01'],
			'empty' => ['', null, null],
			'no dash' => ['202605', null, null],
			'now' => ['now', null, null],
			'month zero' => ['2026-00', null, null],
			'month thirteen' => ['2026-13', null, null],
			'month name' => ['mei', null, null],
			'text before' => ['x2026-05', null, null],
			'five-digit year' => ['12026-05', null, null],
			'text after' => ['2026-05x', null, null],
			'three-digit month' => ['2026-055', null, null],
			'a date' => ['2026-05-01', null, null],
		];
	}//end periods()

	/**
	 * The text reads as the expected month and first day.
	 *
	 * @param string      $text     The period text.
	 * @param string|null $month    The expected month.
	 * @param string|null $firstDay The expected first day.
	 *
	 * @return void
	 *
	 * @dataProvider periods
	 */
	public function testAPeriodReadsAsAMonthOnlyWhenTheWholeTextIsOne(string $text, ?string $month, ?string $firstDay): void {
		$period = new WagePeriod($text);

		$this->assertSame($month, $period->month());
		$this->assertSame($firstDay, $period->firstDay()?->format('Y-m-d'));
	}//end testAPeriodReadsAsAMonthOnlyWhenTheWholeTextIsOne()
}//end class
