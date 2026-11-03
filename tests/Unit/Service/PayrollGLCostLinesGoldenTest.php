<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The GL debit lines of one cost total match the recorded golden for
 * allocation lines of this and another run, with and without codes, with
 * missing or non-numeric amounts, and totals above and below the coded sum.
 *
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\PayrollGLPostService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/../../fixtures/golden/gl-cost-lines.input.php';

/**
 * Golden test for PayrollGLPostService::costLines().
 *
 * @covers \OCA\Humaniq\Service\PayrollGLPostService
 * @uses   \OCA\Humaniq\Service\PayrollJournalTotals
 */
class PayrollGLCostLinesGoldenTest extends TestCase {

	/**
	 * Every case produces the recorded lines.
	 *
	 * @return void
	 */
	public function testCostLinesMatchTheGolden(): void {
		$golden = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/golden/gl-cost-lines.json'), true);
		$class = new ReflectionClass(PayrollGLPostService::class);
		$service = $class->newInstanceWithoutConstructor();
		foreach (glCostLineCases() as $name => $case) {
			$lines = $class->getMethod('costLines')->invoke($service, $case['allocations'], $case['cost']);
			self::assertSame($golden[$name], $lines, $name);
		}
	}//end testCostLinesMatchTheGolden()
}//end class
