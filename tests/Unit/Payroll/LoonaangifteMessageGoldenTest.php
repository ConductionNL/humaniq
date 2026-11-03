<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The wage tax return message matches the recorded golden for a complete
 * administration with two income relationships and two corrections, and an
 * incomplete administration with neither.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteMessageBuilder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../fixtures/golden/loonaangifte-message.input.php';

/**
 * Golden test for {@see LoonaangifteMessageBuilder::build()}.
 *
 * @covers \OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteMessageBuilder
 * @uses   \OCA\Humaniq\Payroll\Loonaangifte\PersonPart
 */
class LoonaangifteMessageGoldenTest extends TestCase {

	/**
	 * Every case builds the recorded tree, totals and findings.
	 *
	 * @return void
	 */
	public function testMessagesMatchTheGolden(): void {
		$golden = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/golden/loonaangifte-message.json'), true);
		foreach (loonaangifteMessageCases() as $name => $case) {
			$built = LoonaangifteMessageBuilder::build(
				$case['administration'],
				$case['header'],
				['period' => $case['period'], 'lines' => $case['lines'], 'corrections' => $case['corrections']]
			);
			self::assertSame(
				json_encode($golden[$name], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE),
				json_encode($built, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE),
				$name
			);
		}
	}//end testMessagesMatchTheGolden()
}//end class
