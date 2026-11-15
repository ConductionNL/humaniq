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
use OCP\IUserSession;

/**
 * Writes and keeps acknowledgedBy on a payroll run finding.
 *
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-001
 */
class PayrollRunFindingStampListener extends ObjectUpdatingListener {

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
	 * @param ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-001
	 */
	protected function onUpdating(ObjectUpdatingEvent $event): void {
		$finding = ($event->getNewObject()->getObject() ?? []);
		$old = ($event->getOldObject()?->getObject() ?? []);
		$reviewer = (string)($old['acknowledgedBy'] ?? '');
		if (($old['status'] ?? '') !== 'acknowledged' && ($finding['status'] ?? '') === 'acknowledged') {
			$reviewer = (string)($this->userSession->getUser()?->getUID() ?? '');
		}

		if ($reviewer !== '' && $reviewer !== ($finding['acknowledgedBy'] ?? null)) {
			$event->setModifiedData(['acknowledgedBy' => $reviewer]);
		}
	}//end onUpdating()

}//end class
