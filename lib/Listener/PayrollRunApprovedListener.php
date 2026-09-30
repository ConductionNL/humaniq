<?php

/**
 * Credits overtime taken as time off when a payroll run is approved.
 *
 * Approving a run is a status edit (and, with payroll-run-as-a-flow, a flow
 * step writing the same field); both arrive as an object update. When a
 * PayrollRun's status moves from draft to approved, OvertimeCreditService
 * credits the run's time-off overtime (time-hours-and-overtime-to-payroll D5).
 * Registered for the payrollrun schema only; never breaks the save.
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
 * @spec openspec/specs/hours-and-overtime-to-payroll/spec.md#REQ-HOP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\OvertimeCreditService;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Draft to approved: credit the time off.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/hours-and-overtime-to-payroll/spec.md#REQ-HOP-003
 */
class PayrollRunApprovedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param OvertimeCreditService $credits The credit.
	 * @param LoggerInterface       $logger  The logger.
	 *
	 * @spec openspec/specs/hours-and-overtime-to-payroll/spec.md#REQ-HOP-003
	 */
	public function __construct(
		private readonly OvertimeCreditService $credits,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Credit on the draft to approved edge.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-and-overtime-to-payroll/spec.md#REQ-HOP-003
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$old = $event->getOldObject()?->getObject();
		$new = $event->getNewObject()->getObject();
		if (($old['status'] ?? null) !== 'draft' || ($new['status'] ?? null) !== 'approved') {
			return;
		}

		$runId = (string)$event->getNewObject()->getUuid();
		try {
			$this->credits->creditForRun($runId);
		} catch (\Throwable $e) {
			$this->logger->error('humaniq: crediting the overtime of payroll run ' . $runId . ' failed: ' . $e->getMessage());
		}
	}//end handle()

}//end class
