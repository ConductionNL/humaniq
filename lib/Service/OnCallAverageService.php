<?php

/**
 * Humaniq OnCallAverageService
 *
 * The average approved hours of every on-call contract over a window the user
 * picks, for the fixed-hours offer of BW 7:628a lid 5 (people-flex-contract-rules
 * D3). Only hours on a timesheet that is approved count. The window is cut to
 * the part the contract ran, and the hours are divided by the weeks and the
 * months in that part.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Averages approved hours per on-call contract.
 *
 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-002
 */
final class OnCallAverageService {

	/**
	 * Months after its start that an on-call contract is owed an offer.
	 *
	 * @var int
	 */
	public const OFFER_AFTER_MONTHS = 12;

	/**
	 * Days in an average month.
	 *
	 * @var float
	 */
	private const DAYS_PER_MONTH = (365.25 / 12);

	/**
	 * One row per on-call contract that ran in the window.
	 *
	 * @param array<int, array<string, mixed>> $contracts            EmploymentContract rows.
	 * @param array<int, array<string, mixed>> $entries              TimeEntry rows: employeeId, timesheetId, date, hours.
	 * @param array<int, string>               $approvedTimesheetIds The ids of approved timesheets.
	 * @param string                           $from                 First day of the window (Y-m-d).
	 * @param string                           $to                   Last day of the window (Y-m-d).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-002
	 */
	public function averages(array $contracts, array $entries, array $approvedTimesheetIds, string $from, string $to): array {
		$approved = array_flip($approvedTimesheetIds);
		$rows = [];
		foreach ($contracts as $contract) {
			if ((string)($contract['type'] ?? '') !== 'oproep') {
				continue;
			}

			$start = max($from, substr((string)($contract['startDate'] ?? ''), 0, 10));
			$contractEnd = substr((string)($contract['endDate'] ?? ''), 0, 10);
			$end = $to;
			if ($contractEnd !== '' && $contractEnd < $to) {
				$end = $contractEnd;
			}

			if ($start > $end) {
				continue;
			}

			$hours = $this->approvedHours(employeeId: (string)($contract['employeeId'] ?? ''), entries: $entries, approved: $approved, start: $start, end: $end);
			$days = ((new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1);
			$rows[] = [
				'contractId'          => (string)($contract['id'] ?? ''),
				'employeeId'          => (string)($contract['employeeId'] ?? ''),
				'startDate'           => (string)($contract['startDate'] ?? ''),
				'countedFrom'         => $start,
				'countedTo'           => $end,
				'hours'               => round($hours, 2),
				'hoursPerWeek'        => round($hours / ($days / 7), 2),
				'hoursPerMonth'       => round($hours / ($days / self::DAYS_PER_MONTH), 2),
				'offerDue'            => $this->offerDue(contract: $contract, onDate: $to),
				'vasteUrenAanbodOp'   => ($contract['vasteUrenAanbodOp'] ?? null),
				'vasteUrenAanbodUren' => ($contract['vasteUrenAanbodUren'] ?? null),
			];
		}//end foreach

		return $rows;
	}//end averages()

	/**
	 * The window as two Y-m-d days, or null when it is not one. Without `to`
	 * it ends today; without `from` it starts a year before `to`.
	 *
	 * @param string|null $from The first day.
	 * @param string|null $to   The last day.
	 *
	 * @return array{0: string, 1: string}|null
	 *
	 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-002
	 */
	public function window(?string $from, ?string $to): ?array {
		$last = ($to ?? (new DateTimeImmutable('today'))->format('Y-m-d'));
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $last) !== 1) {
			return null;
		}

		$first = ($from ?? (new DateTimeImmutable($last))->modify('-1 year')->modify('+1 day')->format('Y-m-d'));
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $first) !== 1 || $first > $last) {
			return null;
		}

		return [$first, $last];
	}//end window()

	/**
	 * The approved hours of one employee between two days.
	 *
	 * @param string                           $employeeId The employee.
	 * @param array<int, array<string, mixed>> $entries    TimeEntry rows.
	 * @param array<string, int>               $approved   Approved timesheet ids as keys.
	 * @param string                           $start      First day (Y-m-d).
	 * @param string                           $end        Last day (Y-m-d).
	 *
	 * @return float
	 */
	private function approvedHours(string $employeeId, array $entries, array $approved, string $start, string $end): float {
		$hours = 0.0;
		foreach ($entries as $entry) {
			$date = substr((string)($entry['date'] ?? ''), 0, 10);
			if ((string)($entry['employeeId'] ?? '') !== $employeeId || $date < $start || $date > $end) {
				continue;
			}

			if (isset($approved[(string)($entry['timesheetId'] ?? '')]) === true) {
				$hours += (float)($entry['hours'] ?? 0);
			}
		}

		return $hours;
	}//end approvedHours()

	/**
	 * Whether the contract ran twelve months by the given day without a recorded offer.
	 *
	 * @param array<string, mixed> $contract The contract.
	 * @param string               $onDate   The day (Y-m-d).
	 *
	 * @return bool
	 */
	private function offerDue(array $contract, string $onDate): bool {
		if (trim((string)($contract['vasteUrenAanbodOp'] ?? '')) !== '') {
			return false;
		}

		$start = substr((string)($contract['startDate'] ?? ''), 0, 10);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1) {
			return false;
		}

		$due = (new DateTimeImmutable($start))->modify('+' . self::OFFER_AFTER_MONTHS . ' months')->format('Y-m-d');

		return $due <= $onDate;
	}//end offerDue()
}//end class
