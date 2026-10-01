<?php

/**
 * Awf Hours Review
 *
 * The two calculations of the extra-hours review of the low Awf premium
 * (filings-premium-differentiation D3), as the Handboek Loonheffingen 2026
 * (maart 2026) paragraaf 7.2.3 prescribes them:
 * - berekening 1: the contracted hours of the periods charged the low rate,
 *   divided by the weeks the employee was employed in the year (calendar
 *   days / 7, two decimals), rounded UP to whole hours; more than 30 a week
 *   and there is no review;
 * - berekening 2: the paid hours of the year against the contracted hours of
 *   every contract, as a percentage rounded DOWN; above 30 the low rate is
 *   reviewed.
 * Contracted hours per period follow `AwfTariffResolver::contractHoursIn()`.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll
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
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-102
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

use DateTimeImmutable;

/**
 * Pure arithmetic of the extra-hours review.
 *
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-102
 */
final class AwfHoursReview {

	/**
	 * Above this average of contracted hours a week there is no review
	 * (from 2025; up to 2024 it was 35 or more).
	 */
	public const AVERAGE_HOURS_CEILING = 30;

	/**
	 * Above this percentage of extra paid hours the low rate is reviewed.
	 */
	public const OVERRUN_PERCENT = 30;

	/**
	 * The review figures for one employee over the periods of a year up to
	 * and including the last period.
	 *
	 * @param array<int, array<string, mixed>> $contracts  Every contract of the employee.
	 * @param array<int, array<string, mixed>> $slips      The year's payslips as {period, hours, low}.
	 * @param string                           $lastPeriod The last period counted (`YYYY-MM`).
	 *
	 * @return array{paid: float, contract: float, averageHoursPerWeek: int, overrunPercent: int}
	 */
	public static function figures(array $contracts, array $slips, string $lastPeriod): array {
		$year = substr($lastPeriod, 0, 4);
		$lowPeriods = [];
		$paid = 0.0;
		foreach ($slips as $slip) {
			$paid += (float)($slip['hours'] ?? 0.0);
			if (($slip['low'] ?? false) === true) {
				$lowPeriods[(string)$slip['period']] = true;
			}
		}

		$contractAll = 0.0;
		$contractLow = 0.0;
		foreach (self::periods($lastPeriod) as $period) {
			foreach ($contracts as $contract) {
				$hours = AwfTariffResolver::contractHoursIn($contract, $period);
				$contractAll += $hours;
				$contractLow += (isset($lowPeriods[$period]) === true ? $hours : 0.0);
			}
		}

		$first = new DateTimeImmutable($year . '-01-01');
		$last  = (new DateTimeImmutable($lastPeriod . '-01'))->modify('last day of this month');
		$weeks = round(self::employedDays(contracts: $contracts, from: $first, until: $last) / 7, 2);
		$paid  = round($paid, 2);
		$contractAll = round($contractAll, 2);

		return [
			'paid' => $paid,
			'contract' => $contractAll,
			'averageHoursPerWeek' => ($weeks > 0.0 ? (int)ceil(round($contractLow / $weeks, 6)) : 0),
			'overrunPercent' => ($contractAll > 0.0 ? (int)floor(round(($paid - $contractAll) / $contractAll * 100, 6)) : 0),
		];

	}//end figures()

	/**
	 * Whether the figures call for a review (or, during the year, a
	 * signal): 30 contracted hours a week or less on average, and more than
	 * 30 percent paid above the contract.
	 *
	 * @param array<string, mixed> $figures The figures.
	 *
	 * @return bool
	 */
	public static function exceeds(array $figures): bool {
		return (float)($figures['contract'] ?? 0.0) > 0.0
			&& (int)($figures['averageHoursPerWeek'] ?? 0) <= self::AVERAGE_HOURS_CEILING
			&& (int)($figures['overrunPercent'] ?? 0) > self::OVERRUN_PERCENT;

	}//end exceeds()

	/**
	 * The months of the year up to and including the last period.
	 *
	 * @param string $lastPeriod The last period (`YYYY-MM`).
	 *
	 * @return array<int, string>
	 */
	private static function periods(string $lastPeriod): array {
		$year  = substr($lastPeriod, 0, 4);
		$until = (int)substr($lastPeriod, 5, 2);
		$out   = [];
		for ($month = 1; $month <= $until; $month++) {
			$out[] = sprintf('%s-%02d', $year, $month);
		}

		return $out;

	}//end periods()

	/**
	 * The calendar days between two dates on which any contract ran.
	 *
	 * @param array<int, array<string, mixed>> $contracts The contracts.
	 * @param DateTimeImmutable                $from      The first day.
	 * @param DateTimeImmutable                $until     The last day.
	 *
	 * @return int
	 */
	private static function employedDays(array $contracts, DateTimeImmutable $from, DateTimeImmutable $until): int {
		$covered = [];
		foreach ($contracts as $contract) {
			$days = AwfTariffResolver::overlapDays(contract: $contract, from: $from, until: $until);
			if ($days === 0) {
				continue;
			}

			$raw   = substr(trim((string)($contract['startDate'] ?? '')), 0, 10);
			$start = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? max(new DateTimeImmutable($raw), $from) : $from);
			for ($i = 0; $i < $days; $i++) {
				$covered[$start->modify('+' . $i . ' days')->format('Y-m-d')] = true;
			}
		}

		return count($covered);

	}//end employedDays()
}//end class
