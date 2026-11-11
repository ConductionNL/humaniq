<?php

/**
 * Resolves a time entry's reference day and its hours, in either booking shape.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * - **Running** — `startedAt` is present, `endedAt` is not, and `origin` says
 *   `timer`. The work has not finished, so there are no hours yet: the entry
 *   derives `0` and takes its real figure when the timer stops and the write
 *   becomes clocked.
 *
 * WHY `origin` AND NOT JUST THE MISSING END. A booking that lost its end on the
 * way in looks exactly like a timer, and treating it as one would leave a
 * permanently running timer nobody started and no stop will ever close. The
 * marker makes the intent explicit, so a missing end without it is refused
 * exactly as it was.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Listener\HoursWriteRefusedException;

/**
 * Decides a booking's reference day and hours from each recorded shape.
 *
 * @spec openspec/specs/time-entry-capture/spec.md#requirement-humaniq-captures-time-entries-under-a-submit-approve-lifecycle-req-tec-001
 * @spec openspec/specs/hours-leaf/spec.md#requirement-an-entry-without-an-end-is-a-running-timer-not-a-defective-booking
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
	 * The `origin` value that marks an entry as a running timer.
	 *
	 * Equal to `RunningTimerService::ORIGIN_TIMER` and to the enum member on
	 * `TimeEntry` in `lib/Settings/register.d/hr-timesheet.json`. All three name
	 * the same literal; a drift between them shows up as a timer that cannot be
	 * written rather than as an error anyone would read.
	 *
	 * @var string
	 */
	public const ORIGIN_TIMER = 'timer';

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
	 * @spec openspec/specs/time-entry-capture/spec.md#requirement-humaniq-captures-time-entries-under-a-submit-approve-lifecycle-req-tec-001
	 */
	public function derive(array $incoming, ?array $stored): array {
		$rawStart = (string)($incoming['startedAt'] ?? ($stored['startedAt'] ?? ''));
		$rawEnd = (string)($incoming['endedAt'] ?? ($stored['endedAt'] ?? ''));

		if ($rawStart === '' && $rawEnd === '') {
			return $this->fromDay(incoming: $incoming, stored: $stored);
		}

		if ($this->isRunningTimerWrite(incoming: $incoming, stored: $stored) === true) {
			return $this->fromRunningTimer(rawStart: $rawStart);
		}

		return $this->fromClock(incoming: $incoming, stored: $stored, rawStart: $rawStart, rawEnd: $rawEnd);
	}//end derive()

	/**
	 * Whether this write is a running timer rather than a booking missing its
	 * end.
	 *
	 * Public because the STAMP LISTENER needs the same answer. `origin` is
	 * `readOnly` on the schema, and a client value for it does not survive the
	 * ObjectService write path: an entry started with `origin: timer` came back
	 * stored as `manual`, the schema default. The marker therefore has to be
	 * stamped server-side like every other protected field, and the listener
	 * must not re-derive the rule from its own copy of these conditions.
	 *
	 * Reads the incoming `origin` first and the stored one second, so that
	 * stopping a timer, a write that carries an end and no origin, still sees
	 * the marker on the row it is closing.
	 *
	 * @param array<string, mixed>      $incoming The incoming payload.
	 * @param array<string, mixed>|null $stored   The stored payload (update only).
	 *
	 * @return bool True when the write declares itself a timer.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-an-entry-without-an-end-is-a-running-timer-not-a-defective-booking
	 */
	public function isRunningTimerWrite(array $incoming, ?array $stored): bool {
		$rawStart = (string)($incoming['startedAt'] ?? ($stored['startedAt'] ?? ''));
		$rawEnd = (string)($incoming['endedAt'] ?? ($stored['endedAt'] ?? ''));
		if ($rawStart === '' || $rawEnd !== '') {
			return false;
		}

		return (string)($incoming['origin'] ?? ($stored['origin'] ?? '')) === self::ORIGIN_TIMER;
	}//end isRunningTimerWrite()

	/**
	 * The running shape: a start, no end, and no hours worked yet.
	 *
	 * Zero rather than null: `hours` feeds the parent timesheet's total, and a
	 * total that has to special-case one row is a total that will eventually
	 * forget to. A timer in progress contributes nothing until it stops, which
	 * is what zero says.
	 *
	 * @param string $rawStart The raw `startedAt`.
	 *
	 * @return array{0: int, 1: float} The start timestamp and zero hours.
	 *
	 * @throws HoursWriteRefusedException When the start cannot be parsed.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-an-entry-without-an-end-is-a-running-timer-not-a-defective-booking
	 */
	private function fromRunningTimer(string $rawStart): array {
		$start = strtotime($rawStart);
		if ($start === false) {
			throw new HoursWriteRefusedException('De starttijd van de lopende timer is ongeldig.');
		}

		return [$start, 0.0];
	}//end fromRunningTimer()

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
	 * @spec openspec/specs/time-entry-capture/spec.md#requirement-humaniq-captures-time-entries-under-a-submit-approve-lifecycle-req-tec-001
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
	 * @spec openspec/specs/time-entry-capture/spec.md#requirement-humaniq-captures-time-entries-under-a-submit-approve-lifecycle-req-tec-001
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
