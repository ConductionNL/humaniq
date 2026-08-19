<?php

/**
 * Absence Progression
 *
 * The step-function half of the verzuimpercentage calculation, split out of
 * {@see AbsenceRateService} so each class holds one job: this one turns a
 * `SickLeaveCase.absenceProgression` array into an ordered, clipped, summable
 * series of absence steps; the service turns those into a rate against
 * contracted availability.
 *
 * The split is not cosmetic. Keeping both in one class put its overall
 * complexity over the fleet's phpmd threshold, and the honest fix for
 * "this class does too much" is to make it do less rather than to widen the
 * threshold or baseline the finding.
 *
 * Every method is side-effect free: the whole class is a pure function over
 * plain arrays, which is what lets the service's tests reach
 * every branch without a Nextcloud bootstrap.
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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
 * @spec openspec/changes/absence-rate-partial-recovery/specs/absence-rate/spec.md#REQ-ABSRATE-002
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use DateTimeImmutable;

/**
 * Pure step-function helpers over a case's `absenceProgression`.
 *
 * @spec openspec/changes/absence-rate-partial-recovery/specs/absence-rate/spec.md#REQ-ABSRATE-002
 */
class AbsenceProgression {

	/**
	 * Absence percentage applied to a case that carries no progression steps.
	 *
	 * `absenceProgression`'s own contract: empty or absent means full absence
	 * for the whole case window. This is the no-regression default -- every
	 * case recorded before the field existed counts exactly as it did.
	 *
	 * @var float
	 */
	public const FULL_ABSENCE_PERCENTAGE = 100.0;

	/**
	 * Normalise a case's `absenceProgression` into an ordered step list.
	 *
	 * @param array<string, mixed> $case         The SickLeaveCase.
	 * @param DateTimeImmutable    $firstSickDay The case anchor date.
	 *
	 * @return list<array{from: DateTimeImmutable, percentage: float}> Ordered by `from`, ascending.
	 *
	 * @spec openspec/changes/absence-rate-partial-recovery/specs/absence-rate/spec.md#REQ-ABSRATE-002
	 */
	public function steps(array $case, DateTimeImmutable $firstSickDay): array {
		$raw = ($case['absenceProgression'] ?? null);
		if (is_array($raw) === false || $raw === []) {
			return [$this->fullAbsenceFrom(day: $firstSickDay)];
		}

		$steps = [];
		foreach ($raw as $entry) {
			$step = $this->parseStep(entry: $entry, firstSickDay: $firstSickDay);
			if ($step !== null) {
				$steps[] = $step;
			}
		}

		// A progression that is entirely malformed falls back to full absence,
		// never to zero: a data-entry error must not read as a clean absence
		// record.
		if ($steps === []) {
			return [$this->fullAbsenceFrom(day: $firstSickDay)];
		}

		usort($steps, static fn (array $a, array $b): int => ($a['from'] <=> $b['from']));

		// A progression that starts after firstSickDay leaves the opening
		// stretch undescribed. That stretch is full absence -- the case was
		// reported, and the first resumption step is what changed it.
		if ($steps[0]['from'] > $firstSickDay) {
			array_unshift($steps, $this->fullAbsenceFrom(day: $firstSickDay));
		}

		return $steps;
	}//end steps()

	/**
	 * Sum an ordered step list into FTE-weighted day-equivalents.
	 *
	 * Each step holds its percentage until the day before the next begins; the
	 * last runs to the end of the window. Every segment is clipped to the
	 * window before it counts.
	 *
	 * @param list<array{from: DateTimeImmutable, percentage: float}> $steps       Ordered ascending by `from`.
	 * @param DateTimeImmutable                                       $windowStart First day counted, inclusive.
	 * @param DateTimeImmutable                                       $windowEnd   Last day counted, inclusive.
	 * @param float                                                   $fte         The employee's FTE over the period.
	 *
	 * @return float Day-equivalents, already FTE-weighted.
	 *
	 * @spec openspec/changes/absence-rate-partial-recovery/specs/absence-rate/spec.md#REQ-ABSRATE-002
	 */
	public function sum(
		array $steps,
		DateTimeImmutable $windowStart,
		DateTimeImmutable $windowEnd,
		float $fte,
	): float {
		$total = 0.0;
		$count = count($steps);
		for ($i = 0; $i < $count; $i++) {
			$segmentEnd = $windowEnd;
			if (isset($steps[($i + 1)]) === true) {
				$segmentEnd = $steps[($i + 1)]['from']->modify('-1 day');
			}

			$clippedStart = ($steps[$i]['from'] > $windowStart) ? $steps[$i]['from'] : $windowStart;
			$clippedEnd = ($segmentEnd < $windowEnd) ? $segmentEnd : $windowEnd;
			if ($clippedStart > $clippedEnd) {
				continue;
			}

			$days = $this->inclusiveDays(from: $clippedStart, to: $clippedEnd);
			$total += ($days * ($steps[$i]['percentage'] / 100.0) * $fte);
		}

		return $total;
	}//end sum()

	/**
	 * Inclusive day count between two dates.
	 *
	 * @param DateTimeImmutable $from First day, inclusive.
	 * @param DateTimeImmutable $to   Last day, inclusive.
	 *
	 * @return int Number of days, at least 1 when from <= to.
	 *
	 * @spec openspec/changes/absence-rate-partial-recovery/specs/absence-rate/spec.md#REQ-ABSRATE-002
	 */
	public function inclusiveDays(DateTimeImmutable $from, DateTimeImmutable $to): int {
		return ((int) $from->diff($to)->days + 1);
	}//end inclusiveDays()

	/**
	 * Parse a stored date value, normalised to midnight.
	 *
	 * Midnight normalisation matters: `diff()` between two dates carrying
	 * different times rounds the day count down, which would silently shorten
	 * an absence by a day whenever the stored values disagree on time.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return DateTimeImmutable|null Null when absent, blank, or unparseable.
	 *
	 * @spec openspec/changes/absence-rate-partial-recovery/specs/absence-rate/spec.md#REQ-ABSRATE-002
	 */
	public function date(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->setTime(hour: 0, minute: 0);
		} catch (\Exception) {
			return null;
		}
	}//end date()

	/**
	 * One full-absence step starting on the given day.
	 *
	 * @param DateTimeImmutable $day The step's first day.
	 *
	 * @return array{from: DateTimeImmutable, percentage: float}
	 *
	 * @spec openspec/changes/absence-rate-partial-recovery/specs/absence-rate/spec.md#REQ-ABSRATE-002
	 */
	private function fullAbsenceFrom(DateTimeImmutable $day): array {
		return [
			'from'       => $day,
			'percentage' => self::FULL_ABSENCE_PERCENTAGE,
		];
	}//end fullAbsenceFrom()

	/**
	 * Parse one raw `absenceProgression` entry into a step.
	 *
	 * A step dated before `firstSickDay` is clipped onto it rather than
	 * dropped, so a back-dated correction still counts. The percentage is
	 * clamped to 0..100 rather than rejected, because a stored value outside
	 * that range is a data-entry error whose nearest honest reading is the
	 * bound it exceeded.
	 *
	 * @param mixed             $entry        One raw progression entry.
	 * @param DateTimeImmutable $firstSickDay The case anchor date.
	 *
	 * @return array{from: DateTimeImmutable, percentage: float}|null Null when the entry is malformed.
	 *
	 * @spec openspec/changes/absence-rate-partial-recovery/specs/absence-rate/spec.md#REQ-ABSRATE-002
	 */
	private function parseStep(mixed $entry, DateTimeImmutable $firstSickDay): ?array {
		if (is_array($entry) === false) {
			return null;
		}

		$from = $this->date(value: ($entry['effectiveFrom'] ?? null));
		$percentage = ($entry['absencePercentage'] ?? null);
		if ($from === null || is_numeric($percentage) === false) {
			return null;
		}

		return [
			'from'       => ($from < $firstSickDay) ? $firstSickDay : $from,
			'percentage' => max(0.0, min(100.0, (float) $percentage)),
		];
	}//end parseStep()
}//end class
