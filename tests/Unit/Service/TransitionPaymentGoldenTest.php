<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The transition payment matches the recorded golden for a salaried and an
 * hourly employee, chained and broken contract spans, variable pay, the cap
 * and a departure the employer did not initiate.
 *
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\TransitionPaymentCalculator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../fixtures/golden/transition-payment.input.php';

/**
 * Golden test for {@see TransitionPaymentCalculator::calculate()}.
 *
 * @covers \OCA\Humaniq\Service\TransitionPaymentCalculator
 * @uses \OCA\Humaniq\Service\DateValues
 */
class TransitionPaymentGoldenTest extends TestCase {

	/**
	 * Every case produces the recorded amount and breakdown.
	 *
	 * @return void
	 */
	public function testPaymentsMatchTheGolden(): void {
		$golden = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/golden/transition-payment.json'), true);
		foreach (transitionPaymentCases() as $name => $case) {
			$result = (new TransitionPaymentCalculator())->calculate(
				employee: $case['employee'],
				history: ['contracts' => $case['contracts'], 'payslips' => $case['payslips']],
				parameters: $case['parameters'],
				departure: ['endDate' => $case['endDate'], 'reason' => $case['reason']]
			);
			self::assertSame(
				json_encode($golden[$name], JSON_PRESERVE_ZERO_FRACTION),
				json_encode($result, JSON_PRESERVE_ZERO_FRACTION),
				$name
			);
		}
	}//end testPaymentsMatchTheGolden()
}//end class
