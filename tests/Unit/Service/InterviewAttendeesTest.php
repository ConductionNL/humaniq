<?php

/**
 * Unit tests for the interviewers as attendees of the interview's calendar event.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md#requirement-an-interview-shall-be-a-calendar-event-with-the-interviewers-as-attendees-req-intv-010
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\InterviewAttendees;
use OCA\Humaniq\Service\InterviewIcsRenderer;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Els and Jan interview; the calendar belongs to hr-agenda.
 */
class InterviewAttendeesTest extends TestCase {

	/**
	 * The attendee resolver over Els, Jan (no email) and the hr-agenda calendar owner.
	 *
	 * @return InterviewAttendees
	 */
	private function attendees(): InterviewAttendees {
		$people = [
			'els' => ['Els de Vries', 'els@gemeente.example'],
			'jan' => ['Jan Smit', ''],
			'hr-agenda' => ['HR agenda', 'hr-agenda@gemeente.example'],
		];
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $id): bool => isset($people[$id]));
		$users->method('get')->willReturnCallback(
			function (string $id) use ($people): ?IUser {
				if (isset($people[$id]) === false) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getDisplayName')->willReturn($people[$id][0]);
				$user->method('getEMailAddress')->willReturn($people[$id][1]);
				return $user;
			}
		);

		return new InterviewAttendees(users: $users);
	}//end attendees()

	/**
	 * The event for an interview with Els and Jan.
	 *
	 * @return array<string, mixed>
	 */
	private function event(): array {
		$people = $this->attendees()->forEvent(interviewers: ['els', ' jan ', 'els'], principal: 'principals/users/hr-agenda');

		return [
			'dtstart' => '20261120T090000Z',
			'dtend' => '20261120T100000Z',
			'summary' => 'Sollicitatiegesprek Beleidsadviseur',
			'location' => 'Kamer 2.14',
			'interviewers' => $people['description'],
			'organizer' => $people['organizer'],
			'attendees' => $people['attendees'],
		];
	}//end event()

	/**
	 * Each interviewer with an email address is an attendee; the calendar's owner organises.
	 *
	 * @return void
	 */
	public function testEachInterviewerIsAnAttendee(): void {
		$people = $this->attendees()->forEvent(interviewers: ['els', ' jan ', 'els'], principal: 'principals/users/hr-agenda');

		$this->assertSame('mailto:hr-agenda@gemeente.example', $people['organizer']);
		$this->assertSame([['name' => 'Els de Vries', 'email' => 'els@gemeente.example']], $people['attendees']);
		$this->assertSame('Els de Vries, Jan Smit', $people['description'], 'Jan has no address to invite but is still named');
	}//end testEachInterviewerIsAnAttendee()

	/**
	 * The rendered event carries ORGANIZER and the attendees, and nothing of the candidate.
	 *
	 * @return void
	 */
	public function testTheEventNamesTheInterviewersAndNeverTheCandidate(): void {
		$ics = (new InterviewIcsRenderer())->render('hrmq-interview-1', $this->event());

		$this->assertStringContainsString("\r\nORGANIZER:mailto:hr-agenda@gemeente.example\r\n", $ics);
		$this->assertStringContainsString(
			"\r\nATTENDEE;CN=Els de Vries;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:els@gemeente.example\r\n",
			$ics
		);
		$this->assertSame(1, substr_count($ics, 'ATTENDEE'));
		$this->assertStringNotContainsString('kandidaat', strtolower($ics));
	}//end testTheEventNamesTheInterviewersAndNeverTheCandidate()

	/**
	 * A changed list of attendees is a change; the same list is not.
	 *
	 * @return void
	 */
	public function testAChangedAttendeeListIsAChange(): void {
		$renderer = new InterviewIcsRenderer();
		$event = $this->event();
		$stored = $renderer->render('hrmq-interview-1', $event);

		$this->assertTrue($renderer->unchanged($stored, $event));
		$moved = $event;
		$moved['attendees'][] = ['name' => 'Piet', 'email' => 'piet@gemeente.example'];
		$this->assertFalse($renderer->unchanged($stored, $moved));
		$withoutAttendeeInfo = $event;
		unset($withoutAttendeeInfo['attendees']);
		$this->assertTrue($renderer->unchanged($stored, $withoutAttendeeInfo), 'a caller that does not know the attendees forces no update');
	}//end testAChangedAttendeeListIsAChange()

	/**
	 * Unknown ids are named; a value stored as text before is named only, never invited.
	 *
	 * @return void
	 */
	public function testUnknownIdsAndLegacyText(): void {
		$attendees = $this->attendees();

		$this->assertSame(['ghost'], $attendees->unknown(ids: ['els', 'ghost']));
		$this->assertSame(['els', 'jan'], InterviewAttendees::ids(interviewers: [' els', 'jan', '', 'els', 7 => 'jan']));
		$this->assertSame([], InterviewAttendees::ids(interviewers: 'Els en Jan'));
		$this->assertSame(
			['organizer' => '', 'attendees' => [], 'description' => 'Els en Jan'],
			$attendees->forEvent(interviewers: 'Els en Jan', principal: 'principals/users/hr-agenda')
		);
		$this->assertSame('', $attendees->forEvent(interviewers: [], principal: 'principals/system/calendar')['organizer']);
	}//end testUnknownIdsAndLegacyText()
}//end class
