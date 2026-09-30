<?php

/**
 * Stamps the reviewer who acknowledges a payroll run finding.
 *
 * The acknowledge transition moves a finding from open to acknowledged with
 * the reviewer's note. This listener writes the signed-in user as
 * acknowledgedBy at that moment, and keeps that reviewer on every later
 * write, including the run check that refreshes a recurring finding's values
 * (payroll-run-checks D5).
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
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Writes and keeps acknowledgedBy on a payroll run finding.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-001
 */
class PayrollRunFindingStampListener implements IEventListener {

	/**
	 * Lower-cased slug of the finding schema.
	 */
	public const SLUG = 'payrollrunfinding';

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The signed-in user.
	 *
	 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-001
	 */
	public function __construct(
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Stamp the reviewer on the acknowledgement, keep them afterwards.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$finding = ($event->getNewObject()->getObject() ?? []);
		$old = ($event->getOldObject()?->getObject() ?? []);
		$reviewer = (string)($old['acknowledgedBy'] ?? '');
		if (($old['status'] ?? '') !== 'acknowledged' && ($finding['status'] ?? '') === 'acknowledged') {
			$reviewer = (string)($this->userSession->getUser()?->getUID() ?? '');
		}

		if ($reviewer !== '' && $reviewer !== ($finding['acknowledgedBy'] ?? null)) {
			$event->setModifiedData(['acknowledgedBy' => $reviewer]);
		}
	}//end handle()

}//end class
