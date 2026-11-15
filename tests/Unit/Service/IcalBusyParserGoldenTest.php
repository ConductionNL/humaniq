<?php

/**
 * Golden test for IcalBusyParser: the busy periods of a feed with every edge.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\IcalBusyParser;
use PHPUnit\Framework\TestCase;

/**
 * The busy periods of a feed with every edge.
 */
class IcalBusyParserGoldenTest extends TestCase {

	/**
	 * Every kind of event, in one feed.
	 *
	 * @return void
	 */
	public function testTheBusyPeriodsOfAFeedWithEveryEdge(): void {
		$events = [
			"DTSTART:20260601T090000Z\r\nDTEND:20260601T100000Z",
			"DTSTART;TZID=Europe/Amsterdam:20260602T090000\r\nDTEND;TZID=Europe/Amsterdam:20260602T1\r\n 03000",
			"DTSTART;VALUE=DATE:20260603\r\nDTEND;VALUE=DATE:20260604",
			"DTSTART;TZID=Nowhere/Else:20260605T090000\r\nDURATION:PT2H",
			"DTSTART:20260606T090000Z\r\nDURATION:bogus",
			"DTSTART:20260607T090000Z",
			"DTSTART:20260608T100000Z\r\nDTEND:20260608T090000Z",
			"DTSTART:20260609T100000Z\r\nDTEND:20260609T100000Z",
			"STATUS:CANCELLED\r\nDTSTART:20260610T090000Z\r\nDTEND:20260610T100000Z",
			"TRANSP: transparent\r\nDTSTART:20260611T090000Z\r\nDTEND:20260611T100000Z",
			"STATUS:CONFIRMED\r\nTRANSP:OPAQUE\r\nSUMMARY:Geheim\r\nDTSTART:20260612T090000Z\r\nDTEND:20260612T100000Z",
			"DTSTART:\r\nDTEND:20260613T100000Z",
			"DTSTART:not-a-date\r\nDTEND:20260614T100000Z",
			"DTSTART:20260615T090000\r\nDTEND:20260615T100000",
			"dtstart:20260616T090000Z\r\nDTEND:20260616T100000Z\r\nNOCOLON",
			"DTSTART:20260617T090000Z\r\nDTEND:20260617T100000Z\r\nEND:VEVENT\r\nDTSTART:20260618T090000Z",
		];
		$ical = "BEGIN:VCALENDAR\r\nDTSTART:20260501T090000Z\r\n";
		foreach ($events as $event) {
			$ical .= "BEGIN:VEVENT\r\n" . $event . "\r\nEND:VEVENT\n";
		}

		$ical .= "BEGIN:VEVENT\nDTSTART:20260619T090000Z\nDTEND:20260619T100000Z\nEND:VCALENDAR\n";

		$got = (new IcalBusyParser())->busyPeriods($ical);

		self::assertSame(
			[
				['start' => '2026-06-01T09:00:00+00:00', 'end' => '2026-06-01T10:00:00+00:00'],
				['start' => '2026-06-02T09:00:00+02:00', 'end' => '2026-06-02T10:30:00+02:00'],
				['start' => '2026-06-03T00:00:00+00:00', 'end' => '2026-06-04T00:00:00+00:00'],
				['start' => '2026-06-05T09:00:00+00:00', 'end' => '2026-06-05T11:00:00+00:00'],
				['start' => '2026-06-12T09:00:00+00:00', 'end' => '2026-06-12T10:00:00+00:00'],
				['start' => '2026-06-15T09:00:00+00:00', 'end' => '2026-06-15T10:00:00+00:00'],
				['start' => '2026-06-16T09:00:00+00:00', 'end' => '2026-06-16T10:00:00+00:00'],
				['start' => '2026-06-17T09:00:00+00:00', 'end' => '2026-06-17T10:00:00+00:00'],
			],
			$got
		);
	}//end testTheBusyPeriodsOfAFeedWithEveryEdge()
}//end class
