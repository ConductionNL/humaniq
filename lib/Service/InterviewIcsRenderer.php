<?php

/**
 * Interview Ics Renderer
 *
 * Pure ICS text concerns for interview-scheduling, extracted out of
 * `InterviewCalendarService` (no ObjectService/CalDavBackend I/O here):
 * hand-built RFC 5545 VCALENDAR/VEVENT rendering, the §3.3.11 text-escaping
 * helper, the no-op-diff comparison that keeps a repeated sync idempotent,
 * and the ISO date-time -> RFC 5545 compact form conversions (design.md
 * D3/D5).
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
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use OCA\Humaniq\Support\ReadsIcsValues;

/**
 * Renders and diffs one Interview's VEVENT text.
 */
class InterviewIcsRenderer {

	use ReadsIcsValues;

	/**
	 * Render one timed VEVENT wrapped in a VCALENDAR (design.md D5):
	 * hand-built RFC 5545, `DTSTART`/`DTEND` as UTC DATE-TIME, `DTSTAMP`
	 * (current UTC time), `SEQUENCE:0`, `LOCATION` when present,
	 * `DESCRIPTION` (interviewers, plain text) when present, no
	 * ATTENDEE/ORGANIZER. `SUMMARY`/`LOCATION`/`DESCRIPTION` are escaped
	 * per RFC 5545 §3.3.11.
	 *
	 * @param string $uid The deterministic VEVENT UID.
	 * @param array{dtstart: string, dtend: string, summary: string, location: string, interviewers: string, organizer?: string, attendees?: array<int, array{name: string, email: string}>} $event Compact UTC DATE-TIMEs (YYYYMMDDTHHMMSSZ), the AVG-safe SUMMARY, and the LOCATION and DESCRIPTION (interviewers) texts, all unescaped ('' means none).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-005
	 */
	public function render(string $uid, array $event): string {
		$dtstamp = gmdate('Ymd\THis\Z');

		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Conduction//humaniq interview-scheduling//EN',
			'CALSCALE:GREGORIAN',
			'BEGIN:VEVENT',
			'UID:' . $uid,
			'DTSTAMP:' . $dtstamp,
			'DTSTART:' . $event['dtstart'],
			'DTEND:' . $event['dtend'],
			'SUMMARY:' . IcsText::escape($event['summary']),
		];

		if ($event['location'] !== '') {
			$lines[] = 'LOCATION:' . IcsText::escape($event['location']);
		}

		if ($event['interviewers'] !== '') {
			$lines[] = 'DESCRIPTION:' . IcsText::escape($event['interviewers']);
		}

		// REQ-INTV-010: the calendar's owner organises, each interviewer attends.
		// Only Nextcloud users reach these lines; the candidate never does.
		if (($event['organizer'] ?? '') !== '') {
			$lines[] = 'ORGANIZER:' . $event['organizer'];
		}

		foreach (($event['attendees'] ?? []) as $attendee) {
			$lines[] = 'ATTENDEE;CN=' . self::paramValue(value: $attendee['name']) . ';CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . $attendee['email'];
		}

		$lines[] = 'TRANSP:OPAQUE';
		$lines[] = 'SEQUENCE:0';
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode("\r\n", $lines) . "\r\n";
	}//end render()

	/**
	 * Whether a stored VEVENT's DTSTART/DTEND/SUMMARY/LOCATION already match
	 * the desired values (design.md D3 — avoids etag churn on a no-op
	 * sync). DTSTAMP/SEQUENCE are deliberately excluded from the comparison
	 * since DTSTAMP is regenerated on every render. DESCRIPTION
	 * (interviewers) is deliberately not diffed, per REQ-INTV-003's literal
	 * scope.
	 *
	 * @param string $ics The stored calendar object's raw ICS text.
	 * @param array{dtstart: string, dtend: string, summary: string, location: string, attendees?: array<int, array{name: string, email: string}>} $event Desired compact UTC DATE-TIMEs (YYYYMMDDTHHMMSSZ), SUMMARY and LOCATION texts (unescaped, '' means no location).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/interview-scheduling/spec.md#REQ-INTV-003
	 */
	public function unchanged(string $ics, array $event): bool {
		$existingDtstart = $this->extractIcsValue($ics, 'DTSTART');
		$existingDtend = $this->extractIcsValue($ics, 'DTEND');
		$existingSummary = $this->extractIcsValue($ics, 'SUMMARY');
		$existingLocation = $this->extractIcsValue($ics, 'LOCATION');

		if ($existingDtstart === null || $existingDtend === null || $existingSummary === null) {
			return false;
		}

		$normalisedExistingLocation = ($existingLocation === null) ? '' : IcsText::unescape($existingLocation);
		$summaryMatches = (IcsText::unescape($existingSummary) === $event['summary']);
		$datesMatch = ($existingDtstart === $event['dtstart'] && $existingDtend === $event['dtend']);

		return $datesMatch === true && $summaryMatches === true && $normalisedExistingLocation === $event['location']
			&& $this->sameAttendees(ics: $ics, event: $event) === true;
	}//end unchanged()

	/**
	 * Whether the stored event names the same attendees, when the event says who attends.
	 *
	 * An event rendered without attendee information compares as before, so a
	 * caller that does not know the attendees never forces an update.
	 *
	 * @param string               $ics   The stored ICS.
	 * @param array<string, mixed> $event The wanted event.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md#requirement-an-interview-shall-be-a-calendar-event-with-the-interviewers-as-attendees-req-intv-010
	 */
	private function sameAttendees(string $ics, array $event): bool {
		if (array_key_exists('attendees', $event) === false) {
			return true;
		}

		$wanted = array_map(static fn (array $attendee): string => strtolower($attendee['email']), $event['attendees']);
		$stored = [];
		if (preg_match_all('/^ATTENDEE[^\r\n]*:mailto:([^\r\n]+)\r?$/mi', $ics, $matches) > 0) {
			$stored = array_map('strtolower', $matches[1]);
		}

		sort($wanted);
		sort($stored);

		return $wanted === $stored;
	}//end sameAttendees()

	/**
	 * A property parameter value, quoted when it holds a character that would end it.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private static function paramValue(string $value): string {
		$value = str_replace(['"', "\r", "\n"], '', $value);
		if (preg_match('/[;:,]/', $value) === 1) {
			return '"' . $value . '"';
		}

		return $value;
	}//end paramValue()

	/**
	 * An ISO-8601 date-time, compacted to the RFC 5545 UTC DATE-TIME form
	 * (`YYYYMMDDTHHMMSSZ`), or null when unparseable.
	 *
	 * @param string $isoDateTime The ISO date-time (e.g. `2026-08-01T10:00:00+02:00`).
	 *
	 * @return string|null
	 */
	public function compactDateTime(string $isoDateTime): ?string {
		try {
			$parsed = new DateTimeImmutable($isoDateTime);
		} catch (Throwable $e) {
			return null;
		}

		return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
	}//end compactDateTime()

	/**
	 * Whether `$scheduledStart` (ISO date-time) falls on or after `$bound`
	 * (`Y-m-d`); unparseable input is treated as "in scope" so a malformed
	 * value never silently drops an Interview from the upsert set (upstream
	 * schema validation guards the shape).
	 *
	 * @param string $scheduledStart The Interview's scheduledStart (ISO date-time).
	 * @param string $bound The lower bound (Y-m-d).
	 *
	 * @return bool
	 */
	public function scheduledStartOnOrAfter(string $scheduledStart, string $bound): bool {
		try {
			$parsedStart = new DateTimeImmutable($scheduledStart);
			$parsedBound = new DateTimeImmutable($bound);
		} catch (Throwable $e) {
			return true;
		}

		return $parsedStart >= $parsedBound;
	}//end scheduledStartOnOrAfter()

}//end class
