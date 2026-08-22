<?php

/**
 * Employee Timeline
 *
 * The who-was-employed-when half of the headcount metric, split out of
 * {@see AnalyticsService} so each class holds one job: this one places
 * `Employee` rows on a timeline and answers headcount/starters/leavers for a
 * period; the service turns those answers into a bucketed series.
 *
 * The split follows the {@see AbsenceProgression} precedent, and for the same
 * measured reason: adding the headcount and billable-ratio metrics pushed
 * `AnalyticsService` to an overall phpmd complexity of 60 against a threshold
 * of 50, with `headcountSeries()` itself at cyclomatic 14 (threshold 10) and
 * NPath 300 (threshold 200). The honest fix for "this class does too much" is
 * to make it do less, not to widen the threshold or baseline the finding.
 *
 * Every method is side-effect free: the whole class is a pure function over
 * plain arrays, which is what lets its tests reach every branch without a
 * Nextcloud bootstrap.
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
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Places employees on a timeline and counts them over a period.
 */
class EmployeeTimeline {

	/**
	 * Place `Employee` rows on the timeline.
	 *
	 * A row with no parseable `startDate` cannot be placed at all, so it is
	 * excluded rather than assumed to have started at some convenient
	 * moment — the same refusal {@see AbsenceRateService} makes for an
	 * absence with no covering contract. The excluded count is returned
	 * rather than swallowed, because an administration where many employees
	 * lack a start date produces a headcount line that is real-looking and
	 * wrong.
	 *
	 * @param array<int, array<string, mixed>> $rows Raw Employee rows.
	 *
	 * @return array{placed: array<int, array{start: DateTimeImmutable, end: DateTimeImmutable|null}>, excluded: int}
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-003
	 */
	public function place(array $rows): array {
		$placed = [];
		$excluded = 0;
		foreach ($rows as $row) {
			$start = $this->date($row['startDate'] ?? null);
			if ($start === null) {
				$excluded++;
				continue;
			}

			$placed[] = ['start' => $start, 'end' => $this->date($row['endDate'] ?? null)];
		}

		return ['placed' => $placed, 'excluded' => $excluded];
	}//end place()

	/**
	 * Count headcount, starters and leavers over one period.
	 *
	 * Headcount is measured at the period's END (someone who left mid-period
	 * is not on the books at the end of it), while starters and leavers count
	 * events WITHIN the period. Keeping the three counts in one pass over the
	 * placed set is what lets the caller stay a simple loop over buckets.
	 *
	 * @param array<int, array{start: DateTimeImmutable, end: DateTimeImmutable|null}> $placed Output of place().
	 * @param DateTimeImmutable $start First day of the period.
	 * @param DateTimeImmutable $end Last day of the period.
	 *
	 * @return array{headcount: int, starters: int, leavers: int}
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-003
	 */
	public function countOver(array $placed, DateTimeImmutable $start, DateTimeImmutable $end): array {
		$headcount = 0;
		$starters = 0;
		$leavers = 0;

		foreach ($placed as $employee) {
			$headcount += (int)$this->isActiveAt($employee, $end);
			$starters += (int)$this->within($employee['start'], $start, $end);
			$leavers += (int)($employee['end'] !== null && $this->within($employee['end'], $start, $end));
		}

		return ['headcount' => $headcount, 'starters' => $starters, 'leavers' => $leavers];
	}//end countOver()

	/**
	 * Is this employee on the books at `$moment`?
	 *
	 * @param array{start: DateTimeImmutable, end: DateTimeImmutable|null} $employee A placed employee.
	 * @param DateTimeImmutable $moment The instant to test.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-003
	 */
	private function isActiveAt(array $employee, DateTimeImmutable $moment): bool {
		if ($employee['start'] > $moment) {
			return false;
		}

		return ($employee['end'] === null || $employee['end'] > $moment);
	}//end isActiveAt()

	/**
	 * Does `$moment` fall inside the inclusive window?
	 *
	 * @param DateTimeImmutable $moment The instant to test.
	 * @param DateTimeImmutable $start First day, inclusive.
	 * @param DateTimeImmutable $end Last day, inclusive.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-003
	 */
	private function within(DateTimeImmutable $moment, DateTimeImmutable $start, DateTimeImmutable $end): bool {
		return ($moment >= $start && $moment <= $end);
	}//end within()

	/**
	 * Parse a stored date value.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return DateTimeImmutable|null Null when absent, blank, or unparseable.
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-003
	 */
	private function date(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception) {
			return null;
		}
	}//end date()
}//end class
