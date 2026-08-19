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
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Renders and diffs one Interview's VEVENT text.
 */
class InterviewIcsRenderer {

	/**
	 * Render one timed VEVENT wrapped in a VCALENDAR (design.md D5):
	 * hand-built RFC 5545, `DTSTART`/`DTEND` as UTC DATE-TIME, `DTSTAMP`
	 * (current UTC time), `SEQUENCE:0`, `LOCATION` when present,
	 * `DESCRIPTION` (interviewers, plain text) when present, no
	 * ATTENDEE/ORGANIZER. `SUMMARY`/`LOCATION`/`DESCRIPTION` are escaped
	 * per RFC 5545 §3.3.11.
	 *
	 * @param string $uid The deterministic VEVENT UID.
	 * @param string $dtstart Compact UTC DATE-TIME (YYYYMMDDTHHMMSSZ).
	 * @param string $dtend Compact UTC DATE-TIME (YYYYMMDDTHHMMSSZ).
	 * @param string $summary The AVG-safe SUMMARY text (unescaped).
	 * @param string $location The LOCATION text (unescaped, '' means none).
	 * @param string $interviewers The DESCRIPTION text (unescaped, '' means none).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-005
	 */
	public function render(string $uid, string $dtstart, string $dtend, string $summary, string $location, string $interviewers): string {
		$dtstamp = gmdate('Ymd\THis\Z');

		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Conduction//hrmq interview-scheduling//EN',
			'CALSCALE:GREGORIAN',
			'BEGIN:VEVENT',
			'UID:' . $uid,
			'DTSTAMP:' . $dtstamp,
			'DTSTART:' . $dtstart,
			'DTEND:' . $dtend,
			'SUMMARY:' . $this->escapeText($summary),
		];

		if ($location !== '') {
			$lines[] = 'LOCATION:' . $this->escapeText($location);
		}

		if ($interviewers !== '') {
			$lines[] = 'DESCRIPTION:' . $this->escapeText($interviewers);
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
	 * @param string $dtstart Desired compact UTC DATE-TIME (YYYYMMDDTHHMMSSZ).
	 * @param string $dtend Desired compact UTC DATE-TIME (YYYYMMDDTHHMMSSZ).
	 * @param string $summary Desired SUMMARY text (unescaped).
	 * @param string $location Desired LOCATION text (unescaped, '' means none).
	 *
	 * @return bool
	 */
	public function unchanged(string $ics, string $dtstart, string $dtend, string $summary, string $location): bool {
		$existingDtstart = $this->extractIcsValue($ics, 'DTSTART');
		$existingDtend = $this->extractIcsValue($ics, 'DTEND');
		$existingSummary = $this->extractIcsValue($ics, 'SUMMARY');
		$existingLocation = $this->extractIcsValue($ics, 'LOCATION');

		if ($existingDtstart === null || $existingDtend === null || $existingSummary === null) {
			return false;
		}

		$normalisedExistingLocation = ($existingLocation === null) ? '' : $this->unescapeText($existingLocation);
		$summaryMatches = ($this->unescapeText($existingSummary) === $summary);
		$datesMatch = ($existingDtstart === $dtstart && $existingDtend === $dtend);

		return $datesMatch === true && $summaryMatches === true && $normalisedExistingLocation === $location;
	}//end unchanged()

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

	/**
	 * Escape a TEXT property value per RFC 5545 §3.3.11 (backslash,
	 * semicolon, comma, newline) — candidate/vacancy/location/interviewer
	 * text is data, not trusted literal text.
	 *
	 * @param string $value The raw text.
	 *
	 * @return string
	 */
	private function escapeText(string $value): string {
		$value = str_replace('\\', '\\\\', $value);
		$value = str_replace([';', ',', "\n"], ['\\;', '\\,', '\\n'], $value);
		return $value;
	}//end escapeText()

	/**
	 * Reverse `escapeText()`, for comparing a stored SUMMARY/LOCATION back
	 * to the desired unescaped value in `unchanged()`.
	 *
	 * @param string $value The escaped text.
	 *
	 * @return string
	 */
	private function unescapeText(string $value): string {
		$value = str_replace(['\\n', '\\,', '\\;'], ["\n", ',', ';'], $value);
		$value = str_replace('\\\\', '\\', $value);
		return $value;
	}//end unescapeText()

	/**
	 * Extract a single property's value from a raw ICS text
	 * (`PROPERTY[;PARAMS]:VALUE`), tolerant of `;` parameters and both CRLF
	 * and LF line endings. Returns null when the property is absent.
	 *
	 * @param string $ics The raw ICS text.
	 * @param string $property The property name (e.g. `DTSTART`).
	 *
	 * @return string|null
	 */
	private function extractIcsValue(string $ics, string $property): ?string {
		$lines = preg_split('/\r\n|\n|\r/', $ics) ?: [];
		foreach ($lines as $line) {
			if (str_starts_with($line, $property . ':') === true) {
				return substr($line, strlen($property) + 1);
			}

			if (str_starts_with($line, $property . ';') === true) {
				$colonPos = strpos($line, ':');
				if ($colonPos !== false) {
					return substr($line, $colonPos + 1);
				}
			}
		}

		return null;
	}//end extractIcsValue()

}//end class
