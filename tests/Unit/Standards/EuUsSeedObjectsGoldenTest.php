<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The EU/US payroll checks' seed samples (employees, contracts, payslips
 * and filings for DE, FR and the US) match the recorded golden, field for
 * field and in order.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\Checks\EuUsPayrollChecks;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for EuUsPayrollChecks::seedObjects().
 *
 * @covers \OCA\Humaniq\Standards\Checks\EuUsPayrollChecks
 * @uses   \OCA\Humaniq\Standards\Checks\PayrollSampleFields
 */
class EuUsSeedObjectsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/eu-us-seed-objects.json';

	/**
	 * The provider returns the recorded samples.
	 *
	 * @return void
	 */
	public function testSeedObjectsMatchTheGolden(): void {
		$actual = EuUsPayrollChecks::seedObjects();
		if (getenv('HUMANIQ_GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		self::assertSame(
			json_encode(json_decode((string)file_get_contents(self::GOLDEN), true), JSON_PRESERVE_ZERO_FRACTION),
			json_encode($actual, JSON_PRESERVE_ZERO_FRACTION)
		);
	}//end testSeedObjectsMatchTheGolden()
}//end class
