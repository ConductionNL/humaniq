<?php

/**
 * iCalendar Busy Parser
 *
 * Reads an iCalendar feed and keeps the periods, and nothing else.
 *
 * WHY IT ONLY READS TWO PROPERTIES
 * --------------------------------
 * The AVG boundary is enforced by never parsing the rest. `SUMMARY`,
 * `LOCATION`, `ATTENDEE`, `ORGANIZER` and `DESCRIPTION` are not extracted, not
 * returned and not stored, so there is no later step where somebody could
 * forget to strip them (REQ-AGD-005). An employee's manager sees that Thursday
 * morning is busy; that it says "Tandarts" never leaves the feed.
 *
 * A cancelled event is skipped, because a cancelled appointment is not busy
 * time, and a transparent event (`TRANSP:TRANSPARENT`) is skipped for the same
 * reason: the calendar itself says it does not block.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 * It does not expand recurrence rules. An `RRULE` series is read as its first
 * occurrence only, and that is stated here rather than approximated, because a
 * half-expanded series would silently under-report busy time in a way nobody
 * could see. Full recurrence belongs to a follow-up with its own tests.
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns an iCalendar document into busy periods.
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
 */
class IcalBusyParser {

	/**
	 * The busy periods a feed declares.
	 *
	 * @param string $ical The iCalendar document.
	 *
	 * @return array<int, array{start: string, end: string}> The periods, each an ISO instant pair.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
	 */
	public function busyPeriods(string $ical): array {
		$periods = [];
		foreach ($this->events(ical: $ical) as $event) {
			$status = strtoupper(trim((string)($event['STATUS'] ?? '')));
			$transparency = strtoupper(trim((string)($event['TRANSP'] ?? '')));
			if ($status === 'CANCELLED' || $transparency === 'TRANSPARENT') {
				continue;
			}

			$start = $this->instant(value: ($event['DTSTART'] ?? null));
			if ($start === null) {
				continue;
			}

			$end = $this->instant(value: ($event['DTEND'] ?? null));
			if ($end === null) {
				$end = $this->endFromDuration(start: $start, duration: ($event['DURATION'] ?? null));
			}

			if ($end === null || $end <= $start) {
				continue;
			}

			$periods[] = [
				'start' => $start->format('Y-m-d\TH:i:sP'),
				'end' => $end->format('Y-m-d\TH:i:sP'),
			];
		}

		return $periods;
	}//end busyPeriods()

	/**
	 * The VEVENT blocks of a feed, as property maps holding only the
	 * properties this parser reads.
	 *
	 * @param string $ical The iCalendar document.
	 *
	 * @return array<int, array<string, string>> The events.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
	 */
	private function events(string $ical): array {
		// Unfold: a long property is continued on the next line, prefixed with
		// one space or tab (RFC 5545 section 3.1).
		$unfolded = preg_replace("/\r\n[ \t]|\n[ \t]/", '', $ical);
		if (is_string($unfolded) === false) {
			return [];
		}

		$events = [];
		$current = null;
		foreach (preg_split("/\r\n|\n|\r/", $unfolded) ?: [] as $line) {
			$line = trim($line);
			if ($line === 'BEGIN:VEVENT') {
				$current = [];
				continue;
			}

			if ($line === 'END:VEVENT') {
				if (is_array($current) === true) {
					$events[] = $current;
				}

				$current = null;
				continue;
			}

			if (is_array($current) === false || str_contains($line, ':') === false) {
				continue;
			}

			[$rawName, $value] = explode(':', $line, 2);
			$parts = explode(';', $rawName);
			$name = strtoupper(trim($parts[0]));

			// Only the properties a busy period is made of are kept. SUMMARY,
			// LOCATION, ATTENDEE, ORGANIZER and DESCRIPTION are deliberately not
			// among them: what is never read cannot later leak.
			if (in_array($name, ['DTSTART', 'DTEND', 'DURATION', 'STATUS', 'TRANSP'], true) === false) {
				continue;
			}

			if (in_array($name, ['DTSTART', 'DTEND'], true) === true) {
				$current[$name] = (implode(';', array_slice($parts, 1)) . ':' . $value);
				continue;
			}

			$current[$name] = $value;
		}

		return $events;
	}//end events()

	/**
	 * One DTSTART or DTEND as an instant.
	 *
	 * @param mixed $value The raw property, parameters and value.
	 *
	 * @return DateTimeImmutable|null The instant, or null when unusable.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
	 */
	private function instant(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		$parameters = '';
		$raw = $value;
		if (str_contains($value, ':') === true) {
			[$parameters, $raw] = explode(':', $value, 2);
		}

		$raw = trim($raw);
		if ($raw === '') {
			return null;
		}

		$timezone = new DateTimeZone('UTC');
		if (preg_match('/TZID=([^;:]+)/i', $parameters, $matches) === 1) {
			try {
				$timezone = new DateTimeZone(trim($matches[1]));
			} catch (\Throwable $e) {
				$timezone = new DateTimeZone('UTC');
			}
		}

		try {
			if (preg_match('/^\d{8}$/', $raw) === 1) {
				// A whole-day value. Midnight to midnight in the feed's zone.
				return new DateTimeImmutable(substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2), $timezone);
			}

			if (str_ends_with($raw, 'Z') === true) {
				return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
			}

			return new DateTimeImmutable($raw, $timezone);
		} catch (\Throwable $e) {
			return null;
		}
	}//end instant()

	/**
	 * The end of an event that declares a DURATION instead of a DTEND.
	 *
	 * @param DateTimeImmutable $start The event start.
	 * @param mixed $duration The raw DURATION value.
	 *
	 * @return DateTimeImmutable|null The end, or null when the duration is unusable.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
	 */
	private function endFromDuration(DateTimeImmutable $start, mixed $duration): ?DateTimeImmutable {
		if (is_string($duration) === false || trim($duration) === '') {
			return null;
		}

		try {
			return $start->add(new DateInterval(trim($duration)));
		} catch (\Throwable $e) {
			return null;
		}
	}//end endFromDuration()
}//end class
