<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The time-estimate summary matches the recorded golden for estimates per
 * role and without one, an overrun, malformed hours and other objects' rows.
 *
 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\TimeEstimateService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../fixtures/golden/time-estimate-summary.input.php';

/**
 * Golden test for {@see TimeEstimateService::summary()}.
 *
 * @covers \OCA\Humaniq\Service\TimeEstimateService
 */
class TimeEstimateSummaryGoldenTest extends TestCase {

	/**
	 * Every case produces the recorded summary.
	 *
	 * @return void
	 */
	public function testSummaryMatchesTheGolden(): void {
		$golden = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/golden/time-estimate-summary.json'), true);
		foreach (timeEstimateSummaryCases() as $name => $case) {
			$summary = (new TimeEstimateService())->summary(
				estimates: $case['estimates'],
				entries: $case['entries'],
				domainObjectType: 'dossiq:Zaak',
				domainObjectRef: 'z-1'
			);
			self::assertSame(
				json_encode($golden[$name], JSON_PRESERVE_ZERO_FRACTION),
				json_encode($summary, JSON_PRESERVE_ZERO_FRACTION),
				$name
			);
		}
	}//end testSummaryMatchesTheGolden()
}//end class
