<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The on-call averages rows match the recorded golden for contracts that
 * start before, inside and after the window, end inside it, carry an offer
 * or a malformed start, with approved, open and out-of-window hours.
 *
 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\OnCallAverageService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../fixtures/golden/on-call-averages.input.php';

/**
 * Golden test for {@see OnCallAverageService::averages()}.
 *
 * @covers \OCA\Humaniq\Service\OnCallAverageService
 */
class OnCallAverageGoldenTest extends TestCase {

	/**
	 * Every case produces the recorded rows.
	 *
	 * @return void
	 */
	public function testRowsMatchTheGolden(): void {
		$golden = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/golden/on-call-averages.json'), true);
		foreach (oncallCases() as $name => $case) {
			$rows = (new OnCallAverageService())->averages(
				contracts: $case['contracts'],
				entries: $case['entries'],
				approvedTimesheetIds: $case['approved'],
				window: $case['window']
			);
			self::assertSame($golden[$name], json_decode((string)json_encode($rows, JSON_PRESERVE_ZERO_FRACTION), true), $name);
		}
	}//end testRowsMatchTheGolden()
}//end class
