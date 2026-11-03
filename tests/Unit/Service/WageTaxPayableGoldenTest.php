<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The draft payable to the Belastingdienst matches the recorded golden:
 * payee, payment reference, return number, invoice and due dates, the
 * amount on the payable and its one line, the account and the
 * administration.
 *
 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\WageTaxPayable;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../fixtures/golden/wage-tax-payable.input.php';

/**
 * Golden test for {@see WageTaxPayable::build()}.
 *
 * @covers \OCA\Humaniq\Service\WageTaxPayable
 */
class WageTaxPayableGoldenTest extends TestCase {

	/**
	 * Every case builds the recorded payable.
	 *
	 * @return void
	 */
	public function testPayablesMatchTheGolden(): void {
		$golden = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/golden/wage-tax-payable.json'), true);
		self::assertSame(array_keys($golden), array_keys(wageTaxPayableCases()));
		foreach (wageTaxPayableCases() as $name => $case) {
			try {
				$payable = (new WageTaxPayable(amount: $case['amount'], payeeId: $case['payeeId'], account: $case['account']))->build(run: $case['run'], filing: $case['filing']);
			} catch (\InvalidArgumentException $e) {
				$payable = ['refused' => $e->getMessage()];
			}

			self::assertSame(json_encode($golden[$name], JSON_PRESERVE_ZERO_FRACTION), json_encode($payable, JSON_PRESERVE_ZERO_FRACTION), $name);
		}
	}//end testPayablesMatchTheGolden()
}//end class
