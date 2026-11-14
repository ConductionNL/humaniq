<?php

/**
 * Unit tests for the scheduling-aware writes of InterviewSyncEngine.
 *
 * REQ-INTV-010: an interview with interviewers reaches their own calendars,
 * moves with a reschedule and is withdrawn on cancel. The engine writes such
 * events through the scheduling-aware calendar (doubled here, recording
 * what it was sent) and keeps the backend for writes that must send
 * nothing; an event without attendees still goes through the backend only.
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

use OCA\Humaniq\Service\InterviewAttendees;
use OCA\Humaniq\Service\InterviewCalendarTarget;
use OCA\Humaniq\Service\InterviewIcsRenderer;
use OCA\Humaniq\Service\InterviewRepository;
use OCA\Humaniq\Service\InterviewSyncEngine;
use OCA\Humaniq\Service\InterviewSyncRun;
use OCA\Humaniq\Service\SchedulingCalendarWriter;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Els and Jan interview; the shared calendar `interviews` belongs to `hr`.
 */
class InterviewSchedulingSyncTest extends TestCase {

	private const PRINCIPAL = 'principals/users/hr';

	private const URI = 'interviews';

	private const OBJECT = 'hrmq-interview-intv-1.ics';

	/**
	 * Every backend call, in order, as `op:objectUri`.
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * What the scheduling-aware calendar was sent, in order.
	 *
	 * @var array<int, string>
	 */
	private array $sent = [];

	/**
	 * A backend double holding one calendar's objects, logging into $this->log.
	 *
	 * @param array<string, string> $objects Stored objects by URI.
	 *
	 * @return object
	 */
	private function backend(array $objects = []): object {
		$log = &$this->log;
		return new class($objects, $log) {
			/**
			 * @param array<string, string> $objects Stored objects by URI.
			 * @param array<int, string>    $log     The shared call log.
			 */
			public function __construct(public array $objects, private array &$log) {
			}

			/**
			 * Backend double: getCalendarObjectByUID.
			 *
			 * @return mixed
			 */
			public function getCalendarObjectByUID($principal, $uid, $uri = null) {
				return isset($this->objects[$uid . '.ics']) ? 'interviews/' . $uid . '.ics' : null;
			}

			/**
			 * Backend double: getCalendarObject.
			 *
			 * @return mixed
			 */
			public function getCalendarObject($calendarId, $objectUri) {
				return isset($this->objects[$objectUri]) ? ['calendardata' => $this->objects[$objectUri]] : null;
			}

			/**
			 * Backend double: createCalendarObject.
			 *
			 * @return mixed
			 */
			public function createCalendarObject($calendarId, $objectUri, $data) {
				$this->log[] = 'create:' . $objectUri;
				$this->objects[$objectUri] = $data;
			}

			/**
			 * Backend double: updateCalendarObject.
			 *
			 * @return mixed
			 */
			public function updateCalendarObject($calendarId, $objectUri, $data) {
				$this->log[] = 'update:' . $objectUri;
				$this->objects[$objectUri] = $data;
			}

			/**
			 * Backend double: deleteCalendarObject.
			 *
			 * @return mixed
			 */
			public function deleteCalendarObject($calendarId, $objectUri) {
				$this->log[] = 'delete:' . $objectUri;
				unset($this->objects[$objectUri]);
			}
		};
	}//end backend()

	/**
	 * The engine over a backend, with attendees and a scheduling calendar
	 * that writes into the same backend (as Nextcloud's does) unless it fails.
	 *
	 * @param object      $backend  The backend double.
	 * @param bool        $withCalendar Whether the manager offers the calendar.
	 * @param string|null $failOn   A text fragment that makes the calendar refuse a write.
	 *
	 * @return array{0: InterviewSyncEngine, 1: InterviewSyncRun}
	 */
	private function engine(object $backend, bool $withCalendar = true, ?string $failOn = null): array {
		$people = ['els' => ['Els de Vries', 'els@gemeente.example'], 'jan' => ['Jan Smit', 'jan@gemeente.example'], 'hr' => ['HR', 'hr@gemeente.example']];
		$users = $this->createMock(IUserManager::class);
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

		$calendar = $this->createMock(ICreateFromString::class);
		$calendar->method('getUri')->willReturn(self::URI);
		$calendar->method('createFromString')->willReturnCallback(
			function (string $name, string $ics) use ($backend, $failOn): void {
				if ($failOn !== null && str_contains($ics, $failOn) === true) {
					throw new \Exception('refused');
				}

				if (isset($backend->objects[$name]) === true) {
					throw new \Exception('UID exists');
				}

				$this->sent[] = $ics;
				$backend->objects[$name] = $ics;
			}
		);
		$manager = $this->createMock(IManager::class);
		$manager->method('getCalendarsForPrincipal')->willReturn($withCalendar === true ? [$calendar] : []);

		$repository = $this->createMock(InterviewRepository::class);
		$repository->method('resolveSummary')->willReturn('Sollicitatiegesprek — Beleidsmedewerker');

		$engine = new InterviewSyncEngine($repository, new InterviewIcsRenderer(), new InterviewAttendees($users), new SchedulingCalendarWriter($manager));
		$run = new InterviewSyncRun(new InterviewCalendarTarget($backend, 7, self::PRINCIPAL, self::URI), [], []);

		return [$engine, $run];
	}//end engine()

	/**
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function interview(array $overrides = []): array {
		return array_merge(
			[
				'applicationId' => 'app-1',
				'scheduledStart' => '2026-08-03T10:00:00+00:00',
				'scheduledEnd' => '2026-08-03T11:00:00+00:00',
				'interviewers' => ['els', 'jan'],
				'location' => 'Kamer 4',
				'status' => 'scheduled',
				'calendarEventUid' => 'hrmq-interview-intv-1',
			],
			$overrides
		);
	}//end interview()

	/**
	 * The event as a previous sync stored it.
	 *
	 * @param array<int, string> $attendees Attendee emails.
	 * @param string             $start     DTSTART.
	 *
	 * @return string
	 */
	private function stored(array $attendees, string $start = '20260803T100000Z'): string {
		$lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'BEGIN:VEVENT', 'UID:hrmq-interview-intv-1', 'DTSTART:' . $start, 'DTEND:20260803T110000Z', 'SUMMARY:Sollicitatiegesprek — Beleidsmedewerker', 'LOCATION:Kamer 4', 'ORGANIZER:mailto:hr@gemeente.example'];
		foreach ($attendees as $email) {
			$lines[] = 'ATTENDEE;CN=x:mailto:' . $email;
		}

		return implode("\r\n", array_merge($lines, ['SEQUENCE:2', 'END:VEVENT', 'END:VCALENDAR'])) . "\r\n";
	}//end stored()

	/**
	 * A new interview with interviewers is sent to their calendars not written raw.
	 *
	 * @return void
	 */
	public function testANewInterviewWithInterviewersIsSentToTheirCalendarsNotWrittenRaw(): void {
		$backend = $this->backend();
		[$engine, $run] = $this->engine($backend);

		$engine->syncInterview($run, $this->interview(['calendarEventUid' => null]), 'intv-1');

		$this->assertSame('created', $run->results[0]['status']);
		$this->assertSame([], $this->log, 'the backend wrote nothing itself');
		$this->assertCount(1, $this->sent);
		$this->assertSame(['els@gemeente.example', 'jan@gemeente.example'], SchedulingCalendarWriter::attendeeEmails($this->sent[0]));
		$this->assertStringContainsString('ORGANIZER:mailto:hr@gemeente.example', $this->sent[0]);
	}//end testANewInterviewWithInterviewersIsSentToTheirCalendarsNotWrittenRaw()

	/**
	 * Without the scheduling calendar the backend still creates.
	 *
	 * @return void
	 */
	public function testWithoutTheSchedulingCalendarTheBackendStillCreates(): void {
		$backend = $this->backend();
		[$engine, $run] = $this->engine($backend, withCalendar: false);

		$engine->syncInterview($run, $this->interview(['calendarEventUid' => null]), 'intv-1');

		$this->assertSame('created', $run->results[0]['status']);
		$this->assertSame(['create:' . self::OBJECT], $this->log);
		$this->assertSame([], $this->sent);
	}//end testWithoutTheSchedulingCalendarTheBackendStillCreates()

	/**
	 * An interview without attendees never uses the scheduling calendar.
	 *
	 * @return void
	 */
	public function testAnInterviewWithoutAttendeesNeverUsesTheSchedulingCalendar(): void {
		$backend = $this->backend();
		[$engine, $run] = $this->engine($backend);

		$engine->syncInterview($run, $this->interview(['calendarEventUid' => null, 'interviewers' => 'Els Bakker']), 'intv-1');

		$this->assertSame(['create:' . self::OBJECT], $this->log);
		$this->assertSame([], $this->sent);
	}//end testAnInterviewWithoutAttendeesNeverUsesTheSchedulingCalendar()

	/**
	 * Moving the interview replaces the stored copy and sends the new time with a higher sequence.
	 *
	 * @return void
	 */
	public function testMovingTheInterviewReplacesTheStoredCopyAndSendsTheNewTimeWithAHigherSequence(): void {
		$backend = $this->backend([self::OBJECT => $this->stored(['els@gemeente.example', 'jan@gemeente.example'], '20260801T090000Z')]);
		[$engine, $run] = $this->engine($backend);

		$engine->syncInterview($run, $this->interview(), 'intv-1');

		$this->assertSame('updated', $run->results[0]['status']);
		$this->assertSame(['delete:' . self::OBJECT], $this->log, 'the stored copy is removed silently, never updated raw');
		$this->assertCount(1, $this->sent);
		$this->assertStringContainsString('DTSTART:20260803T100000Z', $this->sent[0]);
		$this->assertSame(3, SchedulingCalendarWriter::sequence($this->sent[0]));
		$this->assertStringNotContainsString('STATUS:CANCELLED', $this->sent[0]);
		$this->assertSame($this->sent[0], $backend->objects[self::OBJECT]);
	}//end testMovingTheInterviewReplacesTheStoredCopyAndSendsTheNewTimeWithAHigherSequence()

	/**
	 * An interviewer taken off the interview gets a cancellation.
	 *
	 * @return void
	 */
	public function testAnInterviewerTakenOffTheInterviewGetsACancellation(): void {
		$backend = $this->backend([self::OBJECT => $this->stored(['els@gemeente.example', 'jan@gemeente.example', 'piet@gemeente.example'])]);
		[$engine, $run] = $this->engine($backend);

		$engine->syncInterview($run, $this->interview(), 'intv-1');

		$this->assertSame('updated', $run->results[0]['status']);
		$this->assertCount(2, $this->sent);
		$this->assertStringContainsString('STATUS:CANCELLED', $this->sent[0]);
		$this->assertSame(['piet@gemeente.example'], SchedulingCalendarWriter::attendeeEmails($this->sent[0]));
		$this->assertSame(3, SchedulingCalendarWriter::sequence($this->sent[0]));
		$this->assertSame(['els@gemeente.example', 'jan@gemeente.example'], SchedulingCalendarWriter::attendeeEmails($this->sent[1]));
		$this->assertSame(4, SchedulingCalendarWriter::sequence($this->sent[1]));
		$this->assertSame(['delete:' . self::OBJECT, 'delete:' . self::OBJECT], $this->log);
	}//end testAnInterviewerTakenOffTheInterviewGetsACancellation()

	/**
	 * An unchanged interview sends nothing.
	 *
	 * @return void
	 */
	public function testAnUnchangedInterviewSendsNothing(): void {
		$backend = $this->backend();
		[$engine, $run] = $this->engine($backend);
		$engine->syncInterview($run, $this->interview(['calendarEventUid' => null]), 'intv-1');
		$this->sent = [];

		$engine->syncInterview($run, $this->interview(), 'intv-1');

		$this->assertSame('unchanged', $run->results[1]['status']);
		$this->assertSame([], $this->sent);
		$this->assertSame([], $this->log);
	}//end testAnUnchangedInterviewSendsNothing()

	/**
	 * A failed reschedule restores the stored copy.
	 *
	 * @return void
	 */
	public function testAFailedRescheduleRestoresTheStoredCopy(): void {
		$stored = $this->stored(['els@gemeente.example', 'jan@gemeente.example'], '20260801T090000Z');
		$backend = $this->backend([self::OBJECT => $stored]);
		[$engine, $run] = $this->engine($backend, failOn: 'DTSTART:20260803T100000Z');

		$engine->syncInterview($run, $this->interview(), 'intv-1');

		$this->assertSame('failed', $run->results[0]['status']);
		$this->assertStringContainsString('refused', $run->results[0]['message']);
		$this->assertSame($stored, $backend->objects[self::OBJECT]);
		$this->assertSame(['delete:' . self::OBJECT, 'create:' . self::OBJECT], $this->log);
	}//end testAFailedRescheduleRestoresTheStoredCopy()

	/**
	 * Cancelling sends a cancellation to every attendee and leaves no event.
	 *
	 * @return void
	 */
	public function testCancellingSendsACancellationToEveryAttendeeAndLeavesNoEvent(): void {
		$backend = $this->backend([self::OBJECT => $this->stored(['els@gemeente.example', 'jan@gemeente.example'])]);
		[$engine, $run] = $this->engine($backend);

		$engine->syncInterview($run, $this->interview(['status' => 'cancelled']), 'intv-1');

		$this->assertSame('removed', $run->results[0]['status']);
		$this->assertCount(1, $this->sent);
		$this->assertStringContainsString('STATUS:CANCELLED', $this->sent[0]);
		$this->assertSame(['els@gemeente.example', 'jan@gemeente.example'], SchedulingCalendarWriter::attendeeEmails($this->sent[0]));
		$this->assertSame(3, SchedulingCalendarWriter::sequence($this->sent[0]));
		$this->assertSame(['delete:' . self::OBJECT, 'delete:' . self::OBJECT], $this->log);
		$this->assertArrayNotHasKey(self::OBJECT, $backend->objects);
	}//end testCancellingSendsACancellationToEveryAttendeeAndLeavesNoEvent()

	/**
	 * Cancelling an event without attendees only deletes it.
	 *
	 * @return void
	 */
	public function testCancellingAnEventWithoutAttendeesOnlyDeletesIt(): void {
		$backend = $this->backend([self::OBJECT => $this->stored([])]);
		[$engine, $run] = $this->engine($backend);

		$engine->syncInterview($run, $this->interview(['status' => 'cancelled']), 'intv-1');

		$this->assertSame('removed', $run->results[0]['status']);
		$this->assertSame([], $this->sent);
		$this->assertSame(['delete:' . self::OBJECT], $this->log);
	}//end testCancellingAnEventWithoutAttendeesOnlyDeletesIt()

	/**
	 * A failed cancellation puts the event back so the next sync retries.
	 *
	 * @return void
	 */
	public function testAFailedCancellationPutsTheEventBackSoTheNextSyncRetries(): void {
		$stored = $this->stored(['els@gemeente.example']);
		$backend = $this->backend([self::OBJECT => $stored]);
		[$engine, $run] = $this->engine($backend, failOn: 'STATUS:CANCELLED');

		$engine->syncInterview($run, $this->interview(['status' => 'cancelled']), 'intv-1');

		$this->assertSame('failed', $run->results[0]['status']);
		$this->assertSame($stored, $backend->objects[self::OBJECT]);
	}//end testAFailedCancellationPutsTheEventBackSoTheNextSyncRetries()
}//end class
