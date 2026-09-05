<?php

/**
 * Resolves a time entry's reference day and its hours, in either booking shape.
 *
 * A `TimeEntry` is recorded one of two ways, and this is the only place that
 * decides which:
 *
 * - **Clocked** — `startedAt` and `endedAt` are both present, and `hours` is
 *   derived from `(endedAt − startedAt − breakMinutes)`. This is humaniq's own
 *   shape and every refusal in it is unchanged.
 * - **Booked to a day** — neither is present, and a `date` plus an explicit
 *   positive `hours` stand in. This is what pipelinq and planninq record;
 *   neither captures clock times, so an owner that required them could only
 *   have taken their bookings by fabricating a start and an end that nobody
 *   measured.
 *
 * WHY NOT IN THE SCHEMA. JSON Schema's `required` cannot express "either this
 * pair or that field". The schema therefore marks all three optional and the
 * invariant lives here, next to the other refusals and their structured Dutch
 * messages.
 *
 * WHY NOT IN THE LISTENER. It was, and it took
 * {@see \OCA\Humaniq\Listener\TimeEntryStampListener} to an overall complexity
 * of 56 against a threshold of 50. The listener's job is the mutability guard
 * and the stamping; deciding what a booking MEANS is a separate question with
 * no dependencies, which is why this class has none.
 *
 * @category  Service
 * @package   OCA\Humaniq\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Listener\HoursWriteRefusedException;

/**
 * Decides a booking's reference day and hours from either recorded shape.
 */
class TimeEntryHoursDeriver {

	/**
	 * The most hours a single day can hold.
	 *
	 * Above this a day booking is a data error rather than a long shift, and
	 * letting it through would corrupt the timesheet total it feeds.
	 *
	 * @var float
	 */
	private const MAX_HOURS_PER_DAY = 24.0;

	/**
	 * Resolve the reference timestamp and the hours for a write.
	 *
	 * @param array<string, mixed>      $incoming The incoming payload.
	 * @param array<string, mixed>|null $stored   The stored payload (update only).
	 *
	 * @return array{0: int, 1: float} The UTC reference timestamp and the hours.
	 *
	 * @throws HoursWriteRefusedException When the booking is in neither shape,
	 *  or the shape it is in is impossible.
	 *
	 * @spec openspec/changes/a-time-entry-can-be-booked-to-a-day/specs/time-entry-capture/spec.md#requirement-humaniq-captures-time-entries-under-a-submit-approve-lifecycle-req-tec-001
	 */
	public function derive(array $incoming, ?array $stored): array {
		$rawStart = (string)($incoming['startedAt'] ?? ($stored['startedAt'] ?? ''));
		$rawEnd = (string)($incoming['endedAt'] ?? ($stored['endedAt'] ?? ''));

		if ($rawStart === '' && $rawEnd === '') {
			return $this->fromDay(incoming: $incoming, stored: $stored);
		}

		return $this->fromClock(incoming: $incoming, stored: $stored, rawStart: $rawStart, rawEnd: $rawEnd);
	}//end derive()

	/**
	 * The clocked shape: a span, minus the break.
	 *
	 * @param array<string, mixed>      $incoming The incoming payload.
	 * @param array<string, mixed>|null $stored   The stored payload (update only).
	 * @param string                    $rawStart The raw `startedAt`.
	 * @param string                    $rawEnd   The raw `endedAt`.
	 *
	 * @return array{0: int, 1: float} The start timestamp and the derived hours.
	 *
	 * @throws HoursWriteRefusedException On an impossible span.
	 *
	 * @spec openspec/changes/a-time-entry-can-be-booked-to-a-day/specs/time-entry-capture/spec.md#requirement-humaniq-captures-time-entries-under-a-submit-approve-lifecycle-req-tec-001
	 */
	private function fromClock(array $incoming, ?array $stored, string $rawStart, string $rawEnd): array {
		// Plain strtotime: timestamps are timezone-agnostic, and the derived
		// date strings downstream are formatted with gmdate() — no DateTime
		// machinery needed.
		$start = strtotime($rawStart);
		$end = strtotime($rawEnd);
		if ($start === false || $end === false) {
			throw new HoursWriteRefusedException('De start- of eindtijd van de urenboeking is ongeldig.');
		}

		if ($end <= $start) {
			throw new HoursWriteRefusedException('De eindtijd van een urenboeking moet na de starttijd liggen.');
		}

		$breakMinutes = ($incoming['breakMinutes'] ?? ($stored['breakMinutes'] ?? 0));
		if (is_numeric($breakMinutes) === false || (int)$breakMinutes < 0) {
			throw new HoursWriteRefusedException('De pauze van een urenboeking moet nul minuten of meer zijn.');
		}

		$spanMinutes = (($end - $start) / 60);
		if ((int)$breakMinutes >= $spanMinutes) {
			throw new HoursWriteRefusedException('De pauze is even lang als of langer dan de geboekte tijd.');
		}

		return [$start, round((($spanMinutes - (int)$breakMinutes) / 60), 2)];
	}//end fromClock()

	/**
	 * The day shape: a date and an explicit number of hours.
	 *
	 * Refuses as loudly as the clocked path, and for the same reason: a booking
	 * that cannot say WHEN or HOW LONG is not a booking, and accepting it would
	 * put a row on a timesheet that no aggregate can total.
	 *
	 * @param array<string, mixed>      $incoming The incoming payload.
	 * @param array<string, mixed>|null $stored   The stored payload (update only).
	 *
	 * @return array{0: int, 1: float} The day's timestamp and the hours.
	 *
	 * @throws HoursWriteRefusedException When the date or the hours is missing
	 *  or impossible.
	 *
	 * @spec openspec/changes/a-time-entry-can-be-booked-to-a-day/specs/time-entry-capture/spec.md#requirement-humaniq-captures-time-entries-under-a-submit-approve-lifecycle-req-tec-001
	 */
	private function fromDay(array $incoming, ?array $stored): array {
		$date = strtotime((string)($incoming['date'] ?? ($stored['date'] ?? '')));
		if ($date === false) {
			throw new HoursWriteRefusedException(
				'Een urenboeking zonder start- en eindtijd heeft een geldige datum nodig.'
			);
		}

		$raw = ($incoming['hours'] ?? ($stored['hours'] ?? null));
		if (is_numeric($raw) === false) {
			throw new HoursWriteRefusedException(
				'Een urenboeking zonder start- en eindtijd heeft een aantal uren nodig.'
			);
		}

		$hours = round((float)$raw, 2);
		if ($hours <= 0.0) {
			throw new HoursWriteRefusedException('Het aantal uren van een urenboeking moet groter dan nul zijn.');
		}

		if ($hours > self::MAX_HOURS_PER_DAY) {
			throw new HoursWriteRefusedException('Een urenboeking kan niet meer dan 24 uur op één dag beslaan.');
		}

		return [$date, $hours];
	}//end fromDay()

}//end class
