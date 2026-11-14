<?php

/**
 * Reservation Periods
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The period arithmetic of paying out reserves (payroll-reservation-payouts
 * D5, D6): whether an employee leaves in a period, where a basis starts after
 * the last payout, and the neighbouring periods. Pure functions over
 * `YYYY-MM` strings.
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

/**
 * Period helpers for the reservation payouts.
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
 */
final class ReservationPeriods {
	/**
	 * Whether the employee's employment ends in the period.
	 *
	 * @param array<string, mixed> $employee The employee.
	 * @param string               $period   The period.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 */
	public static function leavesIn(array $employee, string $period): bool {
		return str_starts_with((string)($employee['endDate'] ?? ''), $period . '-');
	}//end leavesIn()

	/**
	 * The month after the latest payout of a kind, or '' when there is none.
	 *
	 * @param string                     $kind    The kind.
	 * @param list<array<string, mixed>> $payouts The payouts.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 */
	public static function afterLastPayout(string $kind, array $payouts): string {
		$last = '';
		foreach ($payouts as $payout) {
			$to = substr((string)($payout['basisTo'] ?? ''), 0, 7);
			if (($payout['kind'] ?? null) === $kind && strcmp($to, $last) > 0) {
				$last = $to;
			}
		}

		if ($last === '') {
			return '';
		}

		return (new DateTimeImmutable($last . '-01'))->modify('+1 month')->format('Y-m');
	}//end afterLastPayout()

	/**
	 * The later of two periods; '' is the earliest.
	 *
	 * @param string $a A period.
	 * @param string $b A period or ''.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 */
	public static function later(string $a, string $b): string {
		return (strcmp($a, $b) >= 0 ? $a : $b);
	}//end later()

	/**
	 * The period before.
	 *
	 * @param string $period The period.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 */
	public static function previous(string $period): string {
		return (new DateTimeImmutable($period . '-01'))->modify('-1 month')->format('Y-m');
	}//end previous()

	/**
	 * A month number, or the default when it is not one.
	 *
	 * @param mixed $value   The stored value.
	 * @param int   $default The default.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 */
	public static function month(mixed $value, int $default): int {
		$month = (is_numeric($value) === true ? (int)$value : $default);

		return ($month >= 1 && $month <= 12) ? $month : $default;
	}//end month()

	/**
	 * The period a yearly holiday allowance basis ends in: the payout month,
	 * or for a leaver outside it, the period itself.
	 *
	 * @param string $period  The period.
	 * @param int    $month   The payout month.
	 * @param bool   $leaving Whether the employee leaves.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 */
	public static function scheduledEnd(string $period, int $month, bool $leaving): string {
		if ($leaving === true && (int)substr($period, 5, 2) !== $month) {
			return $period;
		}

		return sprintf('%s-%02d', substr($period, 0, 4), $month);
	}//end scheduledEnd()

	/**
	 * The administration's payout schedule, the defaults filling what it does not set (D5).
	 *
	 * @param list<array<string, mixed>> $rows             The hrAdministration rows.
	 * @param string                     $administrationId The administration.
	 * @param array<string, mixed>       $defaults         The defaults.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 */
	public static function schedule(array $rows, string $administrationId, array $defaults): array {
		$schedule = $defaults;
		foreach ($rows as $row) {
			if ((string)($row['administrationId'] ?? '') !== $administrationId) {
				continue;
			}

			$schedule['holidayAllowancePayout'] = (($row['holidayAllowancePayout'] ?? null) === 'per-period' ? 'per-period' : 'yearly');
			$schedule['holidayAllowanceMonth'] = self::month(value: ($row['holidayAllowanceMonth'] ?? null), default: 5);
			$schedule['endOfYearMonth'] = self::month(value: ($row['endOfYearMonth'] ?? null), default: 12);
			$schedule['ikbPayOutRemainder'] = (($row['ikbPayOutRemainder'] ?? false) === true);
		}

		return $schedule;
	}//end schedule()

	/**
	 * The month a reserve kind is paid in on the schedule.
	 *
	 * @param string               $kind     The reserve kind.
	 * @param array<string, mixed> $schedule The schedule.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 */
	public static function payoutMonth(string $kind, array $schedule): int {
		return (int)($kind === ReservationPayoutService::KIND_END_OF_YEAR ? $schedule['endOfYearMonth'] : $schedule['holidayAllowanceMonth']);
	}//end payoutMonth()

	/**
	 * The identity of a payout within its run, for the upsert.
	 *
	 * @param array<string, mixed> $row The payout.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 */
	public static function payoutKey(array $row): string {
		return implode('|', [(string)($row['employeeId'] ?? ''), (string)($row['kind'] ?? ''), (string)($row['reason'] ?? ''), (string)($row['ikbSpendRequestId'] ?? '')]);
	}//end payoutKey()
}//end class
