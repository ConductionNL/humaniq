<?php

/**
 * Unit tests for PayrollDate.
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

use OCA\Humaniq\Payroll\PayrollDate;
use PHPUnit\Framework\TestCase;

/**
 * The Y-m-d reading of payroll dates.
 *
 * @covers \OCA\Humaniq\Payroll\PayrollDate
 */
class PayrollDateTest extends TestCase {

	/**
	 * Values and the date they read as, or null.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function values(): array {
		return [
			'a date' => ['2026-03-01', '2026-03-01'],
			'padded' => ['  2026-03-01 ', '2026-03-01'],
			'a timestamp' => ['2026-03-01T10:00:00Z', '2026-03-01'],
			'an overflowing day rolls over' => ['2026-02-30', '2026-03-02'],
			'empty' => ['', null],
			'a month' => ['2026-03', null],
			'day first' => ['01-03-2026', null],
			'text' => ['gisteren', null],
		];

	}//end values()

	/**
	 * @param string      $value    The value.
	 * @param string|null $expected The date it reads as, Y-m-d, or null.
	 *
	 * @return void
	 *
	 * @dataProvider values
	 */
	public function testFromYmd(string $value, ?string $expected): void {
		$this->assertSame($expected, PayrollDate::fromYmd($value)?->format('Y-m-d'));

	}//end testFromYmd()
}//end class
