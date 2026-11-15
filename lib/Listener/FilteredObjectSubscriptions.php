<?php

/**
 * Humaniq FilteredObjectSubscriptions
 *
 * The schema-filtered object event subscriptions that live outside the
 * Application class: the interview interviewer check before an Interview is
 * saved, then the manager access stamps (ManagerAccessListeners). Kept here
 * so each new subscription does not grow the bootstrap class every other
 * class depends on.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
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
 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md#requirement-an-interview-shall-be-a-calendar-event-with-the-interviewers-as-attendees-req-intv-010
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;

/**
 * Subscribes the schema-filtered object listeners kept out of Application.
 *
 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md#requirement-an-interview-shall-be-a-calendar-event-with-the-interviewers-as-attendees-req-intv-010
 */
final class FilteredObjectSubscriptions {

	/**
	 * Hand every subscription to the app's filtered subscriber, in order.
	 *
	 * @param callable(array{event: string, listener: string, schemas: list<string>}): void $subscribe Subscribes one listener for one event and its schema slugs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/interview-in-the-interviewers-calendars/specs/interview-scheduling/spec.md#requirement-an-interview-shall-be-a-calendar-event-with-the-interviewers-as-attendees-req-intv-010
	 */
	public function register(callable $subscribe): void {
		// An Interview's interviewers are Nextcloud users, checked on every save.
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$subscribe(['event' => $event, 'listener' => InterviewInterviewersListener::class, 'schemas' => [InterviewInterviewersListener::SCHEMA_SLUG]]);
		}

		(new ManagerAccessListeners())->register(subscribe: $subscribe);
	}//end register()

}//end class
