<?php

/**
 * Unit tests for SchedulingCalendarWriter.
 *
 * Pins how the scheduling-aware calendar is found under a principal and
 * URI, that a refused write surfaces as an exception, and the cancel and
 * update versions of an event's text: STATUS:CANCELLED with a higher
 * SEQUENCE, kept only for the attendees it must reach.
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
 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\SchedulingCalendarWriter;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Finding the calendar, writing to it, and deriving cancel/update versions.
 */
class SchedulingCalendarWriterTest extends TestCase {

	private const EVENT = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:hrmq-interview-1\r\nDTSTART:20260803T100000Z\r\n"
		. "ORGANIZER:mailto:hr@gemeente.example\r\n"
		. "ATTENDEE;CN=Els;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:Els@Gemeente.example\r\n"
		. "ATTENDEE;CN=Jan;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:jan@gemeente.\r\n example\r\n"
		. "TRANSP:OPAQUE\r\nSEQUENCE:3\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

	/**
	 * A scheduling-aware calendar double with the given URI.
	 *
	 * @param string $uri The calendar URI.
	 *
	 * @return ICreateFromString&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function writable(string $uri): ICreateFromString {
		$calendar = $this->createMock(ICreateFromString::class);
		$calendar->method('getUri')->willReturn($uri);

		return $calendar;
	}//end writable()

	/**
	 * Calendar asks for the principals calendar and returns the writable one.
	 *
	 * @return void
	 */
	public function testCalendarAsksForThePrincipalsCalendarAndReturnsTheWritableOne(): void {
		$readOnly = $this->createMock(ICalendar::class);
		$readOnly->method('getUri')->willReturn('interviews');
		$other = $this->writable('personal');
		$wanted = $this->writable('interviews');

		$manager = $this->createMock(IManager::class);
		$manager->expects($this->once())->method('getCalendarsForPrincipal')
			->with('principals/users/hr', ['interviews'])
			->willReturn([$readOnly, $other, $wanted]);

		$this->assertSame($wanted, (new SchedulingCalendarWriter($manager))->calendar('principals/users/hr', 'interviews'));
	}//end testCalendarAsksForThePrincipalsCalendarAndReturnsTheWritableOne()

	/**
	 * Calendar is null when only a read only or no calendar answers.
	 *
	 * @return void
	 */
	public function testCalendarIsNullWhenOnlyAReadOnlyOrNoCalendarAnswers(): void {
		$readOnly = $this->createMock(ICalendar::class);
		$readOnly->method('getUri')->willReturn('interviews');
		$manager = $this->createMock(IManager::class);
		$manager->method('getCalendarsForPrincipal')->willReturnOnConsecutiveCalls([$readOnly], []);
		$writer = new SchedulingCalendarWriter($manager);

		$this->assertNull($writer->calendar('principals/users/hr', 'interviews'));
		$this->assertNull($writer->calendar('principals/users/hr', 'interviews'));
	}//end testCalendarIsNullWhenOnlyAReadOnlyOrNoCalendarAnswers()

	/**
	 * Calendar is null when the manager fails.
	 *
	 * @return void
	 */
	public function testCalendarIsNullWhenTheManagerFails(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('getCalendarsForPrincipal')->willThrowException(new \Exception('dav app disabled'));

		$this->assertNull((new SchedulingCalendarWriter($manager))->calendar('principals/users/hr', 'interviews'));
	}//end testCalendarIsNullWhenTheManagerFails()

	/**
	 * Send writes the text under the object uri.
	 *
	 * @return void
	 */
	public function testSendWritesTheTextUnderTheObjectUri(): void {
		$calendar = $this->writable('interviews');
		$calendar->expects($this->once())->method('createFromString')->with('hrmq-interview-1.ics', self::EVENT);

		(new SchedulingCalendarWriter($this->createMock(IManager::class)))->send($calendar, 'hrmq-interview-1.ics', self::EVENT);
	}//end testSendWritesTheTextUnderTheObjectUri()

	/**
	 * Send turns a refusal into a runtime exception naming the cause.
	 *
	 * @return void
	 */
	public function testSendTurnsARefusalIntoARuntimeExceptionNamingTheCause(): void {
		$calendar = $this->writable('interviews');
		$calendar->method('createFromString')->willThrowException(new \Exception('UID exists'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('De agenda weigerde de uitnodiging: UID exists');
		(new SchedulingCalendarWriter($this->createMock(IManager::class)))->send($calendar, 'x.ics', self::EVENT);
	}//end testSendTurnsARefusalIntoARuntimeExceptionNamingTheCause()

	/**
	 * Attendee emails reads unfolded lower cased addresses and not the organizer.
	 *
	 * @return void
	 */
	public function testAttendeeEmailsReadsUnfoldedLowerCasedAddressesAndNotTheOrganizer(): void {
		$this->assertSame(['els@gemeente.example', 'jan@gemeente.example'], SchedulingCalendarWriter::attendeeEmails(self::EVENT));
		$this->assertSame([], SchedulingCalendarWriter::attendeeEmails(''));
	}//end testAttendeeEmailsReadsUnfoldedLowerCasedAddressesAndNotTheOrganizer()

	/**
	 * Sequence reads the value or zero.
	 *
	 * @return void
	 */
	public function testSequenceReadsTheValueOrZero(): void {
		$this->assertSame(3, SchedulingCalendarWriter::sequence(self::EVENT));
		$this->assertSame(0, SchedulingCalendarWriter::sequence("BEGIN:VEVENT\r\nEND:VEVENT\r\n"));
	}//end testSequenceReadsTheValueOrZero()

	/**
	 * With sequence replaces the one sequence line inside the event.
	 *
	 * @return void
	 */
	public function testWithSequenceReplacesTheOneSequenceLineInsideTheEvent(): void {
		$ics = SchedulingCalendarWriter::withSequence(self::EVENT, 7);

		$this->assertSame(1, substr_count($ics, 'SEQUENCE:'));
		$this->assertStringContainsString("SEQUENCE:7\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", $ics);
		$this->assertSame(7, SchedulingCalendarWriter::sequence($ics));
	}//end testWithSequenceReplacesTheOneSequenceLineInsideTheEvent()

	/**
	 * Cancelled marks the event cancelled one sequence higher for every attendee.
	 *
	 * @return void
	 */
	public function testCancelledMarksTheEventCancelledOneSequenceHigherForEveryAttendee(): void {
		$ics = SchedulingCalendarWriter::cancelled(self::EVENT);

		$this->assertStringContainsString("STATUS:CANCELLED\r\nSEQUENCE:4\r\nEND:VEVENT", $ics);
		$this->assertSame(1, substr_count($ics, 'SEQUENCE:'));
		$this->assertSame(['els@gemeente.example', 'jan@gemeente.example'], SchedulingCalendarWriter::attendeeEmails($ics));
		$this->assertStringContainsString('ORGANIZER:mailto:hr@gemeente.example', $ics);
		$this->assertStringContainsString('UID:hrmq-interview-1', $ics);
	}//end testCancelledMarksTheEventCancelledOneSequenceHigherForEveryAttendee()

	/**
	 * Cancelled for some attendees keeps only them.
	 *
	 * @return void
	 */
	public function testCancelledForSomeAttendeesKeepsOnlyThem(): void {
		$ics = SchedulingCalendarWriter::cancelled(self::EVENT, ['JAN@gemeente.example']);

		$this->assertSame(['jan@gemeente.example'], SchedulingCalendarWriter::attendeeEmails($ics));
		$this->assertStringNotContainsString('Els@', $ics);
		$this->assertStringContainsString('STATUS:CANCELLED', $ics);
	}//end testCancelledForSomeAttendeesKeepsOnlyThem()

	/**
	 * Cancelled replaces an earlier status.
	 *
	 * @return void
	 */
	public function testCancelledReplacesAnEarlierStatus(): void {
		$ics = SchedulingCalendarWriter::cancelled(str_replace('TRANSP:OPAQUE', "STATUS:CONFIRMED\r\nTRANSP:OPAQUE", self::EVENT));

		$this->assertStringNotContainsString('STATUS:CONFIRMED', $ics);
		$this->assertSame(1, substr_count($ics, 'STATUS:'));
	}//end testCancelledReplacesAnEarlierStatus()
}//end class
