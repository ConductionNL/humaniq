<?php

/**
 * Unit tests for FilteredObjectSubscriptions.
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

use OCA\Humaniq\Listener\FilteredObjectSubscriptions;
use OCA\Humaniq\Listener\InterviewInterviewersListener;
use OCA\Humaniq\Listener\ManagerAccessOrgChangeListener;
use OCA\Humaniq\Listener\ManagerAccessStampListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * The interviewer check is subscribed before every save of an Interview, and
 * the manager access subscriptions still follow it unchanged.
 *
 * @covers \OCA\Humaniq\Listener\FilteredObjectSubscriptions
 * @uses   \OCA\Humaniq\Listener\ManagerAccessListeners
 */
class FilteredObjectSubscriptionsTest extends TestCase {

	/**
	 * Every subscription, in the order it is handed over.
	 *
	 * @return list<array{event: string, listener: string, schemas: list<string>}>
	 */
	private function subscriptions(): array {
		$seen = [];
		(new FilteredObjectSubscriptions())->register(subscribe: static function (array $on) use (&$seen): void {
			$seen[] = $on;
		});

		return $seen;
	}//end subscriptions()

	/**
	 * The interviewer check comes first, on create and update of an Interview only.
	 *
	 * @return void
	 */
	public function testTheInterviewerCheckIsSubscribedBeforeEverySave(): void {
		$seen = $this->subscriptions();

		self::assertSame(
			[
				['event' => ObjectCreatingEvent::class, 'listener' => InterviewInterviewersListener::class, 'schemas' => ['interview']],
				['event' => ObjectUpdatingEvent::class, 'listener' => InterviewInterviewersListener::class, 'schemas' => ['interview']],
			],
			array_slice($seen, 0, 2)
		);
	}//end testTheInterviewerCheckIsSubscribedBeforeEverySave()

	/**
	 * The manager access stamp and org-change refresh follow, as before.
	 *
	 * @return void
	 */
	public function testTheManagerAccessSubscriptionsFollowUnchanged(): void {
		$rest = array_slice($this->subscriptions(), 2);

		self::assertSame(
			[
				[ObjectCreatingEvent::class, ManagerAccessStampListener::class],
				[ObjectUpdatingEvent::class, ManagerAccessStampListener::class],
				[ObjectCreatedEvent::class, ManagerAccessOrgChangeListener::class],
				[ObjectUpdatedEvent::class, ManagerAccessOrgChangeListener::class],
				[ObjectDeletedEvent::class, ManagerAccessOrgChangeListener::class],
			],
			array_map(static fn (array $on): array => [$on['event'], $on['listener']], $rest)
		);
		self::assertSame(array_keys(ManagerAccessStampListener::SLUGS), $rest[0]['schemas']);
		self::assertSame(ManagerAccessOrgChangeListener::SLUGS, $rest[2]['schemas']);
	}//end testTheManagerAccessSubscriptionsFollowUnchanged()
}//end class
