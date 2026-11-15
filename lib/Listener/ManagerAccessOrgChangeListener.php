<?php

/**
 * Humaniq ManagerAccessOrgChangeListener
 *
 * Queues a manager access refresh after an OrgAssignment, OrgUnit or
 * ManagerDeputy is created, updated or deleted, because each can change
 * who manages, or stands in for the manager of, records already stored.
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
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\BackgroundJob\RefreshManagerAccessNowJob;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Queues the manager access refresh on an org or deputy change.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */
class ManagerAccessOrgChangeListener implements IEventListener {

	/**
	 * The schema slugs this listener is subscribed for.
	 *
	 * @var list<string>
	 */
	public const SLUGS = ['orgassignment', 'orgunit', 'managerdeputy'];

	/**
	 * Constructor.
	 *
	 * @param IJobList $jobList Queues the refresh.
	 * @param HoursRegisterGateway $gateway Resolves the schema slug.
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function __construct(
		private readonly IJobList $jobList,
		private readonly HoursRegisterGateway $gateway,
	) {

	}//end __construct()

	/**
	 * Queue the refresh after a saved or deleted org or deputy record.
	 *
	 * @param Event $event The post-save or post-delete event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent || $event instanceof ObjectUpdatedEvent || $event instanceof ObjectDeletedEvent) === false) {
			return;
		}

		$object = ($event instanceof ObjectUpdatedEvent) ? $event->getNewObject() : $event->getObject();
		$slug = strtolower($this->gateway->resolveSchemaSlug((string)$object->getSchema()));
		if (in_array($slug, self::SLUGS, true) === true) {
			$this->jobList->add(RefreshManagerAccessNowJob::class);
		}
	}//end handle()

}//end class
