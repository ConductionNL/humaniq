<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The seeded sample objects of the Dutch payroll and HR21 standards checks
 * match the recorded golden, field for field and in order.
 *
 * @spec exclude Seed samples are self-test fixtures of the standards checks; no behavioural requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\Checks\NlHr21Checks;
use OCA\Humaniq\Standards\Checks\NlPayrollChecks;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for the standards checks' seedObjects().
 *
 * @covers \OCA\Humaniq\Standards\Checks\NlPayrollChecks
 * @covers \OCA\Humaniq\Standards\Checks\NlHr21Checks
 * @uses   \OCA\Humaniq\Standards\Checks\PayrollSampleFields
 * @uses   \OCA\Humaniq\Support\RoundsToCents
 */
class StandardsSeedObjectsGoldenTest extends TestCase {

	/**
	 * Both providers return the recorded samples.
	 *
	 * @return void
	 */
	public function testSeedObjectsMatchTheGolden(): void {
		$golden = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/golden/standards-seed-objects.json'), true);
		$actual = [
			'NlPayrollChecks' => NlPayrollChecks::seedObjects(),
			'NlHr21Checks'    => NlHr21Checks::seedObjects(),
		];
		self::assertSame(
			json_encode($golden, JSON_PRESERVE_ZERO_FRACTION),
			json_encode($actual, JSON_PRESERVE_ZERO_FRACTION)
		);
	}//end testSeedObjectsMatchTheGolden()
}//end class
