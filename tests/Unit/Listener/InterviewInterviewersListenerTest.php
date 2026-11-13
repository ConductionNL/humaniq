<?php

/**
 * Unit tests for InterviewInterviewersListener.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
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

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\InterviewInterviewersListener;
use OCA\Humaniq\Service\InterviewAttendees;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * An interviewer who is not a Nextcloud user is refused, by name.
 */
class InterviewInterviewersListenerTest extends TestCase {

	/**
	 * Run the listener over a new Interview with the given interviewers.
	 *
	 * @param mixed $interviewers The interviewers on the record.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function save(mixed $interviewers): ObjectCreatingEvent {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $id): bool => in_array($id, ['els', 'jan'], true));

		$entity = new ObjectEntity();
		$entity->setObject(['applicationId' => 'app-1', 'interviewers' => $interviewers]);
		$event = new ObjectCreatingEvent($entity);

		(new InterviewInterviewersListener(attendees: new InterviewAttendees(users: $users)))->handle($event);

		return $event;
	}//end save()

	/**
	 * A user id that does not exist refuses the save and names it.
	 *
	 * @return void
	 */
	public function testAnUnknownInterviewerIsRefusedByName(): void {
		$event = $this->save(interviewers: ['els', 'ghost']);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['message' => 'Deze interviewers zijn geen Nextcloud-gebruikers: ghost.'], $event->getErrors());
	}//end testAnUnknownInterviewerIsRefusedByName()

	/**
	 * Free text is refused; known users pass, trimmed and once each.
	 *
	 * @return void
	 */
	public function testFreeTextIsRefusedAndKnownUsersPass(): void {
		$this->assertTrue($this->save(interviewers: 'Els en Jan')->isPropagationStopped());

		$ok = $this->save(interviewers: [' els', 'jan', 'els']);
		$this->assertFalse($ok->isPropagationStopped());
		$this->assertSame(['interviewers' => ['els', 'jan']], $ok->getModifiedData());

		$this->assertFalse($this->save(interviewers: null)->isPropagationStopped());
	}//end testFreeTextIsRefusedAndKnownUsersPass()
}//end class
