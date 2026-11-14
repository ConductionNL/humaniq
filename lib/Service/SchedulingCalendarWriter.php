<?php

/**
 * Scheduling Calendar Writer
 *
 * Writes an event with attendees through Nextcloud's scheduling-aware
 * calendar API (`OCP\Calendar\ICreateFromString::createFromString`), which
 * runs the CalDAV scheduling plugin, so every attendee who is a Nextcloud
 * user gets the event delivered into their own calendar (iTIP REQUEST), and
 * a cancelled copy is withdrawn from it (iTIP CANCEL). The raw
 * `CalDavBackend` write path skips that plugin entirely.
 *
 * That API is create-only: it refuses a UID the calendar already holds. A
 * caller therefore removes the stored copy first (through the backend, which
 * sends nothing) and then writes the new version here. This class only
 * resolves the calendar, writes, and derives the cancel and update versions
 * of an event's text; the order of operations is the caller's.
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
 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use RuntimeException;
use Throwable;

/**
 * Writes events through the calendar API that delivers them to their attendees.
 *
 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md
 */
class SchedulingCalendarWriter {

	/**
	 * @param IManager $calendars Nextcloud's calendar manager.
	 */
	public function __construct(
		private readonly IManager $calendars,
	) {
	}//end __construct()

	/**
	 * The writable, scheduling-aware calendar under a principal and URI, or
	 * null when the manager does not offer one (or fails to answer).
	 *
	 * @param string $principal   The calendar owner's principal, e.g. `principals/users/hr`.
	 * @param string $calendarUri The calendar's URI.
	 *
	 * @return ICreateFromString|null
	 *
	 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md
	 */
	public function calendar(string $principal, string $calendarUri): ?ICreateFromString {
		try {
			$found = $this->calendars->getCalendarsForPrincipal($principal, [$calendarUri]);
		} catch (Throwable $e) {
			return null;
		}

		foreach ($found as $calendar) {
			if ($calendar instanceof ICreateFromString && $calendar->getUri() === $calendarUri) {
				return $calendar;
			}
		}

		return null;
	}//end calendar()

	/**
	 * Write one event so its attendees receive it.
	 *
	 * @param ICreateFromString $calendar  The calendar from calendar().
	 * @param string            $objectUri The object's file name, ending in `.ics`.
	 * @param string            $ics       The full VCALENDAR text.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the calendar refuses the write.
	 *
	 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md
	 */
	public function send(ICreateFromString $calendar, string $objectUri, string $ics): void {
		try {
			$calendar->createFromString($objectUri, $ics);
		} catch (Throwable $e) {
			throw new RuntimeException('De agenda weigerde de uitnodiging: ' . $e->getMessage(), 0, $e);
		}
	}//end send()

	/**
	 * The attendees' email addresses in an event, lower-cased, in order.
	 *
	 * @param string $ics The VCALENDAR text.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md
	 */
	public static function attendeeEmails(string $ics): array {
		$emails = [];
		foreach (self::lines(ics: $ics) as $line) {
			$email = self::attendeeEmail(line: $line);
			if ($email !== null && in_array($email, $emails, true) === false) {
				$emails[] = $email;
			}
		}

		return $emails;
	}//end attendeeEmails()

	/**
	 * The event's SEQUENCE, 0 when it has none.
	 *
	 * @param string $ics The VCALENDAR text.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md
	 */
	public static function sequence(string $ics): int {
		foreach (self::lines(ics: $ics) as $line) {
			if (preg_match('/^SEQUENCE:(\d+)$/i', $line, $match) === 1) {
				return (int)$match[1];
			}
		}

		return 0;
	}//end sequence()

	/**
	 * The event with its SEQUENCE set, so attendees treat it as newer than
	 * the copy they hold.
	 *
	 * @param string $ics      The VCALENDAR text.
	 * @param int    $sequence The SEQUENCE to carry.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md
	 */
	public static function withSequence(string $ics, int $sequence): string {
		$lines = array_values(array_filter(self::lines(ics: $ics), static fn (string $line): bool => preg_match('/^SEQUENCE:/i', $line) !== 1));

		return self::insertBeforeEventEnd(lines: $lines, insert: ['SEQUENCE:' . max(0, $sequence)]);
	}//end withSequence()

	/**
	 * The cancelled version of an event: STATUS:CANCELLED, SEQUENCE one
	 * higher, and, when $onlyTo is given, only those attendees kept, so the
	 * cancellation reaches exactly them.
	 *
	 * @param string                  $ics    The VCALENDAR text of the event as the attendees hold it.
	 * @param array<int, string>|null $onlyTo The attendees' email addresses to cancel for; null cancels for all.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md
	 */
	public static function cancelled(string $ics, ?array $onlyTo = null): string {
		$keep = ($onlyTo === null) ? null : array_map('strtolower', $onlyTo);
		$lines = [];
		foreach (self::lines(ics: $ics) as $line) {
			if (preg_match('/^(STATUS|SEQUENCE):/i', $line) === 1) {
				continue;
			}

			$email = self::attendeeEmail(line: $line);
			if ($email !== null && $keep !== null && in_array($email, $keep, true) === false) {
				continue;
			}

			$lines[] = $line;
		}

		return self::insertBeforeEventEnd(lines: $lines, insert: ['STATUS:CANCELLED', 'SEQUENCE:' . (self::sequence(ics: $ics) + 1)]);
	}//end cancelled()

	/**
	 * The unfolded content lines of an ICS text, without empty lines.
	 *
	 * @param string $ics The VCALENDAR text.
	 *
	 * @return array<int, string>
	 */
	private static function lines(string $ics): array {
		$unfolded = preg_replace('/\r?\n[ \t]/', '', $ics);
		$lines = preg_split('/\r?\n/', (string)$unfolded);

		return array_values(array_filter(($lines === false ? [] : $lines), static fn (string $line): bool => $line !== ''));
	}//end lines()

	/**
	 * The lower-cased email address of an ATTENDEE line, or null for any other line.
	 *
	 * @param string $line One unfolded content line.
	 *
	 * @return string|null
	 */
	private static function attendeeEmail(string $line): ?string {
		if (preg_match('/^ATTENDEE[;:].*:mailto:(.+)$/i', $line, $match) !== 1) {
			return null;
		}

		return strtolower(trim($match[1]));
	}//end attendeeEmail()

	/**
	 * Join the lines back into ICS text with $insert placed before the first
	 * END:VEVENT (appended at the end when there is none).
	 *
	 * @param array<int, string> $lines  The content lines.
	 * @param array<int, string> $insert The lines to insert.
	 *
	 * @return string
	 */
	private static function insertBeforeEventEnd(array $lines, array $insert): string {
		$at = array_search('END:VEVENT', array_map('strtoupper', $lines), true);
		if ($at === false) {
			$at = count($lines);
		}

		array_splice($lines, (int)$at, 0, $insert);

		return implode("\r\n", $lines) . "\r\n";
	}//end insertBeforeEventEnd()
}//end class
