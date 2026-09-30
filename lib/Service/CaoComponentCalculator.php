<?php

/**
 * The CAO component lines of one payslip.
 *
 * Pure: given the components a contract gets, the regular wage, the hourly
 * rate and the approved time entries the run pays, it returns one line per
 * component (and per surcharge percentage) with its basis, and the total
 * the run adds to the gross (payroll-cao-components D3). A surcharge window
 * is matched per minute in Europe/Amsterdam time; the highest matching
 * percentage counts, a public holiday counts at the holiday percentage, and
 * a break is taken off the end of the span, which never adds premium hours.
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
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Percentages of wage, fixed amounts and premiums per hour in a window.
 *
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-003
 */
class CaoComponentCalculator {

	/**
	 * The local time the windows are read in.
	 */
	private const TIMEZONE = 'Europe/Amsterdam';

	/**
	 * Compute the lines and their total.
	 *
	 * @param list<array<string, mixed>> $components       Resolved components (EmploymentTermsResolver::resolveComponents).
	 * @param int                        $regularWageCents The regular wage of the period, in cents.
	 * @param float|null                 $hourlyRate       The hourly rate, or null when unknown.
	 * @param list<array<string, mixed>> $entries          The approved time entries the run pays.
	 * @param list<string>               $nonWorkingDates  Public holidays, `YYYY-MM-DD`.
	 * @param float                      $monthFraction    The share of the month the contract covers.
	 *
	 * @return array{lines: list<array<string, mixed>>, totalCents: int}
	 *
	 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-003
	 */
	public function compute(array $components, int $regularWageCents, ?float $hourlyRate, array $entries, array $nonWorkingDates = [], float $monthFraction = 1.0): array {
		$lines = [];
		foreach ($components as $component) {
			$lines = array_merge($lines, match ($component['kind'] ?? '') {
				'percentage-of-wage' => [$this->percentageLine(component: $component, regularWageCents: $regularWageCents, monthFraction: $monthFraction)],
				'fixed-monthly' => [self::line(component: $component, basis: 'fixed per month' . ($monthFraction < 1.0 ? ', ' . round($monthFraction * 100, 1) . '% of the month' : ''), hours: null, pct: null, amountCents: (int)round((int)($component['amountCents'] ?? 0) * $monthFraction))],
				'hourly-surcharge' => $this->surchargeLines(component: $component, hourlyRate: $hourlyRate, entries: $entries, holidays: array_flip($nonWorkingDates)),
				default => [],
			});
		}

		return ['lines' => $lines, 'totalCents' => array_sum(array_column($lines, 'amountCents'))];
	}//end compute()

	/**
	 * A percentage of the regular wage, lifted to its monthly minimum.
	 *
	 * @param array<string, mixed> $component        The component.
	 * @param int                  $regularWageCents The regular wage.
	 * @param float                $monthFraction    The share of the month.
	 *
	 * @return array<string, mixed>
	 */
	private function percentageLine(array $component, int $regularWageCents, float $monthFraction): array {
		$pct = (float)($component['pct'] ?? 0);
		$amountCents = (int)round($regularWageCents * $pct / 100);
		$minimumCents = (int)round((int)($component['minAmountCents'] ?? 0) * $monthFraction);
		$basis = self::percent($pct) . ' of ' . number_format($regularWageCents / 100, 2, '.', '');
		if ($amountCents < $minimumCents) {
			$amountCents = $minimumCents;
			$basis .= ', at least ' . number_format($minimumCents / 100, 2, '.', '');
		}

		return self::line(component: $component, basis: $basis, hours: null, pct: $pct, amountCents: $amountCents);
	}//end percentageLine()

	/**
	 * The premium lines of one surcharge: one per percentage earned, one for
	 * the hours without times.
	 *
	 * @param array<string, mixed>       $component  The component.
	 * @param float|null                 $hourlyRate The hourly rate.
	 * @param list<array<string, mixed>> $entries    The entries.
	 * @param array<string, int>         $holidays   Public holidays as keys.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function surchargeLines(array $component, ?float $hourlyRate, array $entries, array $holidays): array {
		$minutesByBucket = [];
		$noTimesHours = 0.0;
		foreach ($entries as $entry) {
			$span = self::span(entry: $entry);
			if ($span === null) {
				$noTimesHours += (float)($entry['hours'] ?? 0);
				continue;
			}

			foreach ($this->premiumMinutes(component: $component, start: $span[0], end: $span[1], holidays: $holidays) as $bucket => $minutes) {
				$minutesByBucket[$bucket] = (($minutesByBucket[$bucket] ?? 0) + $minutes);
			}
		}

		$lines = [];
		foreach ($minutesByBucket as $bucket => $minutes) {
			[$basis, $pct] = explode('|', (string)$bucket);
			$hours = round($minutes / 60, 2);
			if ($hourlyRate === null) {
				$lines[] = self::line(component: $component, basis: 'no-hourly-rate', hours: $hours, pct: (float)$pct, amountCents: 0);
				continue;
			}

			$lines[] = self::line(component: $component, basis: $basis, hours: $hours, pct: (float)$pct, amountCents: (int)round($minutes / 60 * $hourlyRate * (float)$pct));
		}

		if ($noTimesHours > 0.0) {
			$lines[] = self::line(component: $component, basis: 'no-times', hours: round($noTimesHours, 2), pct: null, amountCents: 0);
		}

		return $lines;
	}//end surchargeLines()

	/**
	 * Premium minutes of one span per `basis|pct` bucket.
	 *
	 * @param array<string, mixed> $component The component.
	 * @param DateTimeImmutable    $start     The start, local time.
	 * @param DateTimeImmutable    $end       The end after the break, local time.
	 * @param array<string, int>   $holidays  Public holidays as keys.
	 *
	 * @return array<string, int>
	 */
	private function premiumMinutes(array $component, DateTimeImmutable $start, DateTimeImmutable $end, array $holidays): array {
		$buckets = [];
		$holidayPct = ($component['holidayPct'] ?? null);
		for ($minute = $start; $minute < $end; $minute = $minute->add(new DateInterval('PT1M'))) {
			$holiday = ($holidayPct !== null && isset($holidays[$minute->format('Y-m-d')]) === true);
			$bucket = ($holiday === true ? 'public holiday|' . (float)$holidayPct : self::windowBucket(windows: (array)($component['windows'] ?? []), minute: $minute));

			if ($bucket !== null) {
				$buckets[$bucket] = (($buckets[$bucket] ?? 0) + 1);
			}
		}

		return $buckets;
	}//end premiumMinutes()

	/**
	 * The best-paid window a minute falls in, as `basis|pct`, or null.
	 *
	 * @param list<array<string, mixed>> $windows The windows.
	 * @param DateTimeImmutable          $minute  The minute.
	 *
	 * @return string|null
	 */
	private static function windowBucket(array $windows, DateTimeImmutable $minute): ?string {
		$time = $minute->format('H:i');
		$today = strtolower($minute->format('l'));
		$yesterday = strtolower($minute->sub(new DateInterval('P1D'))->format('l'));
		$best = null;
		foreach ($windows as $window) {
			if (self::inside(window: $window, today: $today, yesterday: $yesterday, time: $time) === true && ($best === null || (float)$window['pct'] > (float)$best['pct'])) {
				$best = $window;
			}
		}

		if ($best === null) {
			return null;
		}

		return ($best['from'] . '-' . $best['to'] . ' ' . implode(', ', $best['days'])) . '|' . (float)$best['pct'];
	}//end windowBucket()

	/**
	 * Whether a minute at `time` on `today` falls in a window. A window with
	 * no days or no times never matches; one whose `from` is after its `to`
	 * runs from `from` on a listed day to `to` on the next day.
	 *
	 * @param array<string, mixed> $window    The window.
	 * @param string               $today     The minute's weekday.
	 * @param string               $yesterday The weekday before.
	 * @param string               $time      The minute, `HH:MM`.
	 *
	 * @return bool
	 */
	private static function inside(array $window, string $today, string $yesterday, string $time): bool {
		if (is_array($window['days'] ?? null) === false || isset($window['from'], $window['to']) === false) {
			return false;
		}

		[$days, $from, $to] = [$window['days'], $window['from'], $window['to']];
		if ($from > $to) {
			return (in_array($today, $days, true) === true && $time >= $from) || (in_array($yesterday, $days, true) === true && $time < $to);
		}

		return in_array($today, $days, true) === true && $time >= $from && $time < $to;
	}//end inside()

	/**
	 * An entry's worked span in local time, the break taken off the end, or
	 * null when it has no start and end.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null
	 */
	private static function span(array $entry): ?array {
		$started = trim((string)($entry['startedAt'] ?? ''));
		$ended = trim((string)($entry['endedAt'] ?? ''));
		if ($started === '' || $ended === '') {
			return null;
		}

		try {
			$zone = new DateTimeZone(self::TIMEZONE);
			$start = (new DateTimeImmutable($started))->setTimezone($zone);
			$end = (new DateTimeImmutable($ended))->setTimezone($zone)->sub(new DateInterval('PT' . max(0, (int)($entry['breakMinutes'] ?? 0)) . 'M'));
		} catch (\Exception) {
			return null;
		}

		return ($end > $start ? [$start, $end] : null);
	}//end span()

	/**
	 * A payslip line.
	 *
	 * @param array<string, mixed> $component   The component.
	 * @param string               $basis       What the amount is computed on.
	 * @param float|null           $hours       The premium hours.
	 * @param float|null           $pct         The percentage.
	 * @param int                  $amountCents The amount.
	 *
	 * @return array<string, mixed>
	 */
	private static function line(array $component, string $basis, ?float $hours, ?float $pct, int $amountCents): array {
		return ['key' => (string)($component['key'] ?? ''), 'kind' => (string)$component['kind'], 'basis' => $basis, 'hours' => $hours, 'pct' => $pct, 'amountCents' => $amountCents, 'source' => (string)($component['source'] ?? '')];
	}//end line()

	/**
	 * A percentage in words.
	 *
	 * @param float $pct The percentage.
	 *
	 * @return string
	 */
	private static function percent(float $pct): string {
		return rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '%';
	}//end percent()

}//end class
