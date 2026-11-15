<?php

/**
 * Checks a bureau's results when a payroll handoff is received.
 *
 * integriq sets a PayrollHandoff from verzonden to ontvangen when the
 * bureau's payslips are written back (payroll-external-bureau-handoff D3).
 * On that edge SalaryBureauExchangeService::checkIntake() lists missing and
 * unknown employees on the handoff and stamps the returned payslips (D4).
 * Registered for the payrollhandoff schema only; never breaks the save.
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
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\SalaryBureauExchangeService;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Verzonden to ontvangen: check the intake.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-004
 */
class HandoffIntakeListener implements IEventListener {

	/**
	 * The schema slug this listener is registered for.
	 */
	public const SLUG = 'payrollhandoff';

	/**
	 * Constructor.
	 *
	 * @param SalaryBureauExchangeService $handoffs The intake check.
	 * @param LoggerInterface       $logger   The logger.
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-004
	 */
	public function __construct(
		private readonly SalaryBureauExchangeService $handoffs,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the intake check on the verzonden to ontvangen edge.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-004
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$old = $event->getOldObject()?->getObject();
		$new = $event->getNewObject()->getObject();
		if (($old['status'] ?? null) !== 'verzonden' || ($new['status'] ?? null) !== 'ontvangen') {
			return;
		}

		$handoffId = (string)$event->getNewObject()->getUuid();
		try {
			$this->handoffs->checkIntake($handoffId);
		} catch (\Throwable $e) {
			$this->logger->error('humaniq: the intake check of payroll handoff ' . $handoffId . ' failed: ' . $e->getMessage());
		}
	}//end handle()

}//end class
