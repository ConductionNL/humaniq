<?php

/**
 * Humaniq LeaveApprovalListener.
 *
 * Thin OpenRegister adapter, the leave counterpart of
 * {@see TimesheetApprovalListener}: on every object create or update it resolves
 * the changed object's schema slug and, for a LeaveRequest, hands the row to
 * {@see LeaveBalanceProjectionService} which recomputes the matching
 * `LeaveBalance.usedHours`.
 *
 * It listens on EVERY status, not only on entry into `approved`. The projection
 * is a recompute rather than an increment, so moving a request out of `approved`
 * has to restate the balance just as moving into it does, and a corrected date
 * or hours value on an already approved request has to be picked up too. Gating
 * on the transition edge would leave the balance stale in exactly those cases.
 *
 * All decision logic lives in the service so it is unit testable without
 * OpenRegister. This class carries only the wiring, and never throws into the
 * save path.
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
 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\LeaveBalanceProjectionService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Listener that recomputes a leave balance whenever a leave request changes.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-001
 */
class LeaveApprovalListener implements IEventListener {

	/**
	 * The schema slug this listener acts on, lower-cased for comparison.
	 *
	 * Also declared at registration time in Application::boot(), so an
	 * unrelated app's object write never constructs this listener.
	 *
	 * @var string
	 */
	public const LEAVEREQUEST_SLUG = 'leaverequest';

	/**
	 * Constructor.
	 *
	 * @param LeaveBalanceProjectionService $projection The usedHours recompute.
	 * @param ContainerInterface $container The DI container (lazy OpenRegister SchemaMapper lookup).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly LeaveBalanceProjectionService $projection,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle an object create or update event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false
			&& ($event instanceof ObjectUpdatedEvent) === false
		) {
			return;
		}

		try {
			$entity = ($event instanceof ObjectUpdatedEvent)
				? $event->getNewObject()
				: $event->getObject();

			if ($entity === null) {
				return;
			}

			$schemaSlug = $this->resolveSchemaSlug((string)$entity->getSchema());
			if (strtolower($schemaSlug) !== self::LEAVEREQUEST_SLUG) {
				return;
			}

			$this->projection->projectForRequest($entity->getObject());
		} catch (\Throwable $e) {
			// Never break the save path: a failure to resolve or project is
			// logged and swallowed, exactly as TimesheetApprovalListener does.
			$this->logger->warning(
				'humaniq: LeaveApprovalListener could not project a leave request onto its balance',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * Resolve a schema id to its slug via OpenRegister's SchemaMapper.
	 *
	 * Returns an empty string when OpenRegister is absent or the schema cannot
	 * be resolved, so the listener no-ops safely.
	 *
	 * @param string $schemaId The OpenRegister schema id/uuid carried by the object.
	 *
	 * @return string The schema slug, or '' when unresolvable.
	 */
	private function resolveSchemaSlug(string $schemaId): string {
		if ($schemaId === '') {
			return '';
		}

		try {
			$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
			$schema = $schemaMapper->find($schemaId);
			return (string)$schema->getSlug();
		} catch (\Throwable $e) {
			return '';
		}

	}//end resolveSchemaSlug()

}//end class
