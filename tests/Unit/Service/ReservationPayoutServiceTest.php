<?php

/**
 * Tests for ReservationPayoutService: annual wage, basis periods and open balances.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Humaniq\Service\ReservationPayoutService;
use PHPUnit\Framework\TestCase;

/**
 * An employee earning 3000.00 a month with an 8% holiday allowance reserve of 240.00.
 */
class ReservationPayoutServiceTest extends TestCase {

	/**
	 * Payslips for the months from one period to another.
	 *
	 * @param string $from     The first period.
	 * @param string $to       The last period.
	 * @param float  $gross    The gross per month, in euros.
	 * @param float  $reserved The holiday allowance reserved per month, in euros.
	 *
	 * @return list<array<string, mixed>> The payslips.
	 */
	private static function payslips(string $from, string $to, float $gross = 3000.0, float $reserved = 240.0): array {
		$slips = [];
		$month = new \DateTimeImmutable($from . '-01');
		while ($month->format('Y-m') <= $to) {
			$slips[] = ['period' => $month->format('Y-m'), 'grossPay' => $gross, 'vakantiegeldReserved' => $reserved, 'eindejaarsuitkeringReserved' => 250.0];
			$month = $month->modify('+1 month');
		}

		return $slips;
	}//end payslips()

	/**
	 * A full prior year sums the payslips' gross.
	 *
	 * @return void
	 */
	public function testAFullPriorYearSumsItsPayslips(): void {
		$slips = self::payslips(from: '2025-01', to: '2026-04', gross: 3000.0);
		$slips[3]['grossPay'] = 3500.5;

		$this->assertSame(3650050, (new ReservationPayoutService())->annualWageReference($slips, '2026-05', 310000, 8.0));
	}//end testAFullPriorYearSumsItsPayslips()

	/**
	 * A starter without twelve prior months takes the regular wage times twelve plus the holiday allowance.
	 *
	 * @return void
	 */
	public function testAStarterExtrapolatesTheRegularWage(): void {
		$slips = self::payslips(from: '2025-09', to: '2026-04');

		// 3100.00 x 12 x 1.08 = 40176.00
		$this->assertSame(4017600, (new ReservationPayoutService())->annualWageReference($slips, '2026-05', 310000, 8.0));
		$this->assertSame(3720000, (new ReservationPayoutService())->annualWageReference([], '2026-05', 310000, 0.0));
	}//end testAStarterExtrapolatesTheRegularWage()

	/**
	 * May pays June to May; the end-of-year payment pays the calendar year.
	 *
	 * @return void
	 */
	public function testTheBasisPeriods(): void {
		$service = new ReservationPayoutService();

		$this->assertSame(['from' => '2025-06', 'to' => '2026-05'], $service->basisPeriod('vakantiegeld', '2026-05'));
		$this->assertSame(['from' => '2026-01', 'to' => '2026-12'], $service->basisPeriod('vakantiegeld', '2026-12'));
		$this->assertSame(['from' => '2026-01', 'to' => '2026-12'], $service->basisPeriod('eindejaarsuitkering', '2026-12'));
		$this->assertSame(['from' => '2026-01', 'to' => '2026-12'], $service->basisPeriod('eindejaarsuitkering', '2026-03'));
	}//end testTheBasisPeriods()

	/**
	 * The open balance is the reserve in the basis, minus what payouts of the same kind already paid of it.
	 *
	 * @return void
	 */
	public function testAPartlyPaidReserveLeavesTheRest(): void {
		$service = new ReservationPayoutService();
		$basis = $service->basisPeriod('vakantiegeld', '2026-05');
		$slips = self::payslips(from: '2025-01', to: '2026-05');

		$this->assertSame(288000, $service->openBalance('vakantiegeld', $basis, $slips, []));

		$payouts = [
			['kind' => 'vakantiegeld', 'basisFrom' => '2025-06', 'basisTo' => '2025-12', 'amount' => 100000],
			['kind' => 'vakantiegeld', 'basisFrom' => '2024-06', 'basisTo' => '2025-05', 'amount' => 288000],
			['kind' => 'eindejaarsuitkering', 'basisFrom' => '2025-06', 'basisTo' => '2026-05', 'amount' => 50000],
			['kind' => 'vakantiegeld', 'amount' => 999],
		];
		$this->assertSame(188000, $service->openBalance('vakantiegeld', $basis, $slips, $payouts));
	}//end testAPartlyPaidReserveLeavesTheRest()

	/**
	 * The end-of-year reserve reads its own field; overpaid reserves never go negative.
	 *
	 * @return void
	 */
	public function testTheEndOfYearReserveAndOverpayment(): void {
		$service = new ReservationPayoutService();
		$basis = $service->basisPeriod('eindejaarsuitkering', '2026-12');
		$slips = self::payslips(from: '2026-01', to: '2026-12');
		$slips[] = ['period' => '2026-07', 'eindejaarsuitkeringReserved' => 'n/a'];

		$this->assertSame(300000, $service->openBalance('eindejaarsuitkering', $basis, $slips, []));
		$this->assertSame(0, $service->openBalance('eindejaarsuitkering', $basis, $slips, [['kind' => 'eindejaarsuitkering', 'basisFrom' => '2026-01', 'basisTo' => '2026-12', 'amount' => 400000]]));
	}//end testTheEndOfYearReserveAndOverpayment()

	/**
	 * An unknown kind or a malformed period is refused.
	 *
	 * @return void
	 */
	public function testUnknownKindsAndPeriodsAreRefused(): void {
		$service = new ReservationPayoutService();
		foreach ([fn () => $service->basisPeriod('ikb', '2026-05'), fn () => $service->openBalance('ikb', ['from' => '2026-01', 'to' => '2026-12'], [], []), fn () => $service->basisPeriod('vakantiegeld', '2026-13'), fn () => $service->annualWageReference([], 'mei', 0, 8.0)] as $index => $call) {
			try {
				$call();
				$this->fail('call ' . $index . ' must be refused');
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}//end testUnknownKindsAndPeriodsAreRefused()
}//end class
