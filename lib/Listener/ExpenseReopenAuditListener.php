<?php

/**
 * Humaniq ExpenseReopenAuditListener
 *
 * Post-save listener on OpenRegister's `ObjectUpdatedEvent` for `Expense`:
 * when HR reopens an approved claim (approved to submitted), it logs the
 * claim's id, the account that reopened it and the account that had approved
 * it. OpenRegister's audit trail records the change too.
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
 * @spec openspec/changes/hr-reopens-an-approved-claim/specs/humaniq-expenses/spec.md#Requirement:-HR-SHALL-be-able-to-reopen-an-approved-expense-claim-that-no-payroll-run-holds
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Logs each reopen of an expense claim.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/hr-reopens-an-approved-claim/specs/humaniq-expenses/spec.md#Requirement:-HR-SHALL-be-able-to-reopen-an-approved-expense-claim-that-no-payroll-run-holds
 */
class ExpenseReopenAuditListener implements IEventListener {

	/**
	 * Lower-cased slug of the expense schema.
	 *
	 * @var string
	 */
	public const SLUG = 'expense';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Resolves the schema slug.
	 * @param IUserSession $userSession The account that reopened the claim.
	 * @param LoggerInterface $logger The log.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Log a reopen.
	 *
	 * @param Event $event The post-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hr-reopens-an-approved-claim/specs/humaniq-expenses/spec.md#Requirement:-HR-SHALL-be-able-to-reopen-an-approved-expense-claim-that-no-payroll-run-holds
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$entity = $event->getNewObject();
		$old = ($event->getOldObject()?->getObject() ?? []);
		$new = ($entity->getObject() ?? []);
		if (strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== self::SLUG
			|| trim((string)($old['status'] ?? '')) !== 'approved'
			|| trim((string)($new['status'] ?? '')) !== 'submitted'
		) {
			return;
		}

		$this->logger->notice(
			'humaniq: expense claim reopened',
			['id' => (string)$entity->getUuid(), 'reopenedBy' => (string)$this->userSession->getUser()?->getUID(), 'approvedBy' => (string)($old['approvedBy'] ?? '')]
		);
	}//end handle()

}//end class
