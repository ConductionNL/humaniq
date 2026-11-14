<?php

/**
 * Reservation Payout Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The arithmetic of paying out a reserve: the annual wage that selects the
 * special rate (payroll-reservation-payouts D3), the basis period a payout
 * covers (D5) and the open balance of a reserve (D4). It reads what the
 * payslips and the payout records already hold; it stores nothing itself,
 * so a recalculated payslip can never leave a second balance behind.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Annual wage, basis periods and open balances of the holiday allowance and end-of-year reserves.
 *
 * Amounts in cents. Payslip amounts are stored in euros (`grossPay`,
 * `vakantiegeldReserved`) and read here into cents.
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
 */
class ReservationPayoutService {

	/**
	 * The holiday allowance reserve.
	 *
	 * @var string
	 */
	public const KIND_HOLIDAY_ALLOWANCE = 'vakantiegeld';

	/**
	 * The end-of-year (thirteenth month) reserve.
	 *
	 * @var string
	 */
	public const KIND_END_OF_YEAR = 'eindejaarsuitkering';

	/**
	 * The payslip field each reserve kind is stored in.
	 *
	 * @var array<string, string>
	 */
	public const RESERVE_FIELDS = [
		self::KIND_HOLIDAY_ALLOWANCE => 'vakantiegeldReserved',
		self::KIND_END_OF_YEAR => 'eindejaarsuitkeringReserved',
	];

	/**
	 * The annual wage that selects the special-rate band for a payout in this period (D3).
	 *
	 * The sum of the gross wage on the employee's payslips of the previous
	 * calendar year when all twelve of its months have one; otherwise the
	 * current regular wage times twelve plus the holiday allowance on it.
	 *
	 * @param array<int, array<string, mixed>> $payslips          The employee's payslips.
	 * @param string                           $period            The payout period, `YYYY-MM`.
	 * @param int                              $regularWageCents  This period's regular wage.
	 * @param float                            $holidayRatePercent The holiday allowance rate, such as 8.0.
	 *
	 * @return int The annual wage in cents.
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-engine-shall-tax-a-special-payment-at-the-special-rate-req-rsp-001
	 */
	public function annualWageReference(array $payslips, string $period, int $regularWageCents, float $holidayRatePercent): int {
		$priorYear = (string)(self::year(period: $period) - 1);
		$months = [];
		$sum = 0;
		foreach ($payslips as $payslip) {
			$slipPeriod = (string)($payslip['period'] ?? '');
			if (str_starts_with($slipPeriod, $priorYear . '-') === false) {
				continue;
			}

			$months[$slipPeriod] = true;
			$sum += self::cents(euros: ($payslip['grossPay'] ?? 0));
		}

		if (count($months) === 12) {
			return $sum;
		}

		return (int)round($regularWageCents * 12 * (1 + ($holidayRatePercent / 100)));
	}//end annualWageReference()

	/**
	 * The periods a scheduled payout in this period covers, `YYYY-MM` inclusive (D5).
	 *
	 * The holiday allowance pays the twelve months ending in the payout month
	 * (June of last year to May for a May payout); the end-of-year payment
	 * pays the calendar year of the payout.
	 *
	 * @param string $kind   The reserve kind.
	 * @param string $period The payout period, `YYYY-MM`.
	 *
	 * @return array{from: string, to: string} The basis period.
	 *
	 * @throws InvalidArgumentException For an unknown kind or a malformed period.
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 */
	public function basisPeriod(string $kind, string $period): array {
		$year = self::year(period: $period);
		$month = (int)substr($period, 5, 2);
		if ($kind === self::KIND_END_OF_YEAR) {
			return ['from' => sprintf('%04d-01', $year), 'to' => sprintf('%04d-12', $year)];
		}

		if ($kind !== self::KIND_HOLIDAY_ALLOWANCE) {
			throw new InvalidArgumentException('Unknown reserve kind: ' . $kind);
		}

		$from = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('-11 months');

		return ['from' => $from->format('Y-m'), 'to' => sprintf('%04d-%02d', $year, $month)];
	}//end basisPeriod()

	/**
	 * What of a reserve is still open: reserved on the payslips in the basis
	 * period, minus what payout records of that kind already paid of it (D4).
	 *
	 * A payout counts against the basis when its own basis overlaps it, so a
	 * leaver's early payout is not paid again in May. Never below zero.
	 *
	 * @param string                           $kind     The reserve kind.
	 * @param array{from: string, to: string}  $basis    The basis period.
	 * @param array<int, array<string, mixed>> $payslips The employee's payslips.
	 * @param array<int, array<string, mixed>> $payouts  The employee's payout records.
	 *
	 * @return int The open balance in cents.
	 *
	 * @throws InvalidArgumentException For an unknown kind.
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 */
	public function openBalance(string $kind, array $basis, array $payslips, array $payouts): int {
		$field = (self::RESERVE_FIELDS[$kind] ?? null);
		if ($field === null) {
			throw new InvalidArgumentException('Unknown reserve kind: ' . $kind);
		}

		$reserved = 0;
		foreach ($payslips as $payslip) {
			if (self::within(period: (string)($payslip['period'] ?? ''), basis: $basis) === true) {
				$reserved += self::cents(euros: ($payslip[$field] ?? 0));
			}
		}

		$paid = 0;
		foreach ($payouts as $payout) {
			if (($payout['kind'] ?? null) === $kind && self::overlaps(payout: $payout, basis: $basis) === true) {
				$paid += (int)($payout['amount'] ?? 0);
			}
		}

		return max(0, $reserved - $paid);
	}//end openBalance()

	/**
	 * Whether a period lies in the basis, inclusive.
	 *
	 * @param string                          $period The period, `YYYY-MM`.
	 * @param array{from: string, to: string} $basis  The basis.
	 *
	 * @return bool True when inside.
	 */
	private static function within(string $period, array $basis): bool {
		return $period !== '' && strcmp($period, $basis['from']) >= 0 && strcmp(substr($period, 0, 7), $basis['to']) <= 0;
	}//end within()

	/**
	 * Whether a payout's own basis overlaps the basis.
	 *
	 * @param array<string, mixed>            $payout The payout record.
	 * @param array{from: string, to: string} $basis  The basis.
	 *
	 * @return bool True when they overlap.
	 */
	private static function overlaps(array $payout, array $basis): bool {
		$from = substr((string)($payout['basisFrom'] ?? ''), 0, 7);
		$to = substr((string)($payout['basisTo'] ?? ''), 0, 7);
		if ($from === '' || $to === '') {
			return false;
		}

		return strcmp($from, $basis['to']) <= 0 && strcmp($to, $basis['from']) >= 0;
	}//end overlaps()

	/**
	 * The year of a `YYYY-MM` period.
	 *
	 * @param string $period The period.
	 *
	 * @return int The year.
	 *
	 * @throws InvalidArgumentException When the period is malformed.
	 */
	private static function year(string $period): int {
		if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
			throw new InvalidArgumentException('Period must be YYYY-MM, got: ' . $period);
		}

		return (int)substr($period, 0, 4);
	}//end year()

	/**
	 * A stored euro amount in cents.
	 *
	 * @param mixed $euros The amount.
	 *
	 * @return int The cents.
	 */
	private static function cents(mixed $euros): int {
		if (is_numeric($euros) === false) {
			return 0;
		}

		return (int)round(((float)$euros) * 100);
	}//end cents()
}//end class
