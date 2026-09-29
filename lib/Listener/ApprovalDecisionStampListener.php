<?php

/**
 * Approval Decision Stamp Listener
 *
 * Stamps when a leave request, expense claim or leave trade was submitted,
 * and who decided on it and when (self-service-approvals-inbox D1). The
 * approvals inbox reads these to list what a manager or deputy decided, with
 * each request's timeline. Timesheets are stamped the same way by
 * TimesheetProcessStampListener, which owns more of that schema's fields.
 *
 * Only a status edge stamps: a submit sets submittedAt and clears an earlier
 * verdict, an approve or reject sets approvedBy to the account that moved it
 * and approvedAt to now. Any other write leaves the fields as they are.
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
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Stamps the submit and decision moments of an approvable request.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
 */
class ApprovalDecisionStampListener implements IEventListener {

	/**
	 * The schemas this listener stamps, by slug.
	 */
	public const SLUGS = ['leaverequest', 'expense', 'leavetransaction'];

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The account moving the request.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
	) {

	}//end __construct()

	/**
	 * Stamp a submit or a decision.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$new = ($event->getNewObject()->getObject() ?? []);
		$old = ($event->getOldObject()?->getObject() ?? []);
		$stamps = $this->stampsFor(from: (string)($old['status'] ?? ''), to: (string)($new['status'] ?? ''));
		if ($stamps !== []) {
			$event->setModifiedData($stamps);
		}
	}//end handle()

	/**
	 * The fields a move from one status to another stamps.
	 *
	 * @param string $from The stored status.
	 * @param string $to The status being saved.
	 *
	 * @return array<string, string|null>
	 */
	private function stampsFor(string $from, string $to): array {
		if ($from === $to) {
			return [];
		}

		$now = gmdate('Y-m-d\TH:i:s\Z');
		if ($to === 'submitted') {
			return ['submittedAt' => $now, 'approvedBy' => null, 'approvedAt' => null];
		}

		if ($from === 'submitted' && ($to === 'approved' || $to === 'rejected')) {
			$writer = trim((string)($this->userSession->getUser()?->getUID() ?? ''));
			return ['approvedBy' => ($writer !== '' ? $writer : null), 'approvedAt' => $now];
		}

		return [];
	}//end stampsFor()

}//end class
