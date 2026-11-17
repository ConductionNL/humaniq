<?php

/**
 * Humaniq InterviewInterviewersListener: an Interview names its interviewers as Nextcloud users.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
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

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\InterviewAttendees;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Refuses a save whose interviewers name a user id that is not a Nextcloud user, naming it.
 *
 * Registered for the Interview schema only. The stored list is the trimmed
 * user ids, each once.
 *
 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md#requirement-an-interview-shall-be-a-calendar-event-with-the-interviewers-as-attendees-req-intv-010
 *
 * @template-implements IEventListener<Event>
 */
class InterviewInterviewersListener implements IEventListener {

	/**
	 * The schema this listener guards.
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'interview';

	/**
	 * Constructor.
	 *
	 * @param InterviewAttendees $attendees Tells which ids are Nextcloud users.
	 */
	public function __construct(
		private readonly InterviewAttendees $attendees,
	) {
	}//end __construct()

	/**
	 * Check the interviewers of an Interview being created or updated.
	 *
	 * @param Event $event The creating or updating event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md#requirement-an-interview-shall-be-a-calendar-event-with-the-interviewers-as-attendees-req-intv-010
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$entity = $event->getObject();
		if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
		}

		$record = ($entity->getObject() ?? []);
		if (array_key_exists('interviewers', $record) === false || $record['interviewers'] === null) {
			return;
		}

		if (is_array($record['interviewers']) === false) {
			$event->setErrors(['message' => 'Interviewers moeten Nextcloud-gebruikers zijn, geen vrije tekst.']);
			$event->stopPropagation();
			return;
		}

		$ids = InterviewAttendees::ids(interviewers: $record['interviewers']);
		$unknown = $this->attendees->unknown(ids: $ids);
		if ($unknown !== []) {
			$event->setErrors(['message' => 'Deze interviewers zijn geen Nextcloud-gebruikers: ' . implode(', ', $unknown) . '.']);
			$event->stopPropagation();
			return;
		}

		if ($ids !== $record['interviewers']) {
			$event->setModifiedData(['interviewers' => $ids]);
		}
	}//end handle()
}//end class
