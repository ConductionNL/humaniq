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
 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\OvertimeCreditService;
use OCA\Humaniq\Service\PayrollExpenseFoldService;
use OCA\Humaniq\Service\ReservationPayoutApprovalService;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Draft to approved: credit the time off and mark the paid claims reimbursed.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
 */
class PayrollRunApprovedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param OvertimeCreditService          $credits  The credit.
	 * @param LoggerInterface                $logger   The logger.
	 * @param PayrollExpenseFoldService|null $expenses Marks the run's claims reimbursed (payroll-expenses-and-allowances D2).
	 * @param ReservationPayoutApprovalService|null $reservations Marks the run's payouts paid and accrues and settles the IKB (payroll-reservation-payouts D4, D8).
	 *
	 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
	 */
	public function __construct(
		private readonly OvertimeCreditService $credits,
		private readonly LoggerInterface $logger,
		private readonly ?PayrollExpenseFoldService $expenses = null,
		private readonly ?ReservationPayoutApprovalService $reservations = null,
	) {
	}//end __construct()

	/**
	 * Credit on the draft to approved edge.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false || $this->isApproval(event: $event) === false) {
			return;
		}

		$runId = (string)$event->getNewObject()->getUuid();
		$this->creditOvertime(runId: $runId);
		$this->markClaimsReimbursed(runId: $runId);
		$this->settleReservations(runId: $runId);
	}//end handle()

	/**
	 * Whether an update moves a payroll run from draft to approved.
	 *
	 * @param ObjectUpdatedEvent $event The post-save update.
	 *
	 * @return bool
	 */
	private function isApproval(ObjectUpdatedEvent $event): bool {
		$old = $event->getOldObject()?->getObject();
		$new = $event->getNewObject()->getObject();

		return ($old['status'] ?? null) === 'draft' && ($new['status'] ?? null) === 'approved';
	}//end isApproval()

	/**
	 * Credit the run's approved overtime; a failure is logged.
	 *
	 * @param string $runId The payroll run.
	 *
	 * @return void
	 */
	private function creditOvertime(string $runId): void {
		try {
			$this->credits->creditForRun($runId);
		} catch (\Throwable $e) {
			$this->logger->error('humaniq: crediting the overtime of payroll run ' . $runId . ' failed: ' . $e->getMessage());
		}
	}//end creditOvertime()

	/**
	 * Mark the run's folded claims reimbursed, when the claim step is wired; a failure is logged.
	 *
	 * @param string $runId The payroll run.
	 *
	 * @return void
	 */
	private function markClaimsReimbursed(string $runId): void {
		if ($this->expenses === null) {
			return;
		}

		try {
			$this->expenses->markReimbursed($runId);
		} catch (\Throwable $e) {
			$this->logger->error('humaniq: marking the claims of payroll run ' . $runId . ' reimbursed failed: ' . $e->getMessage());
		}
	}//end markClaimsReimbursed()

	/**
	 * Mark the run's reserve payouts paid and accrue and settle the IKB, when wired; a failure is logged.
	 *
	 * @param string $runId The payroll run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 */
	private function settleReservations(string $runId): void {
		if ($this->reservations === null) {
			return;
		}

		try {
			$this->reservations->onRunApproved($runId);
		} catch (\Throwable $e) {
			$this->logger->error('humaniq: settling the reserves of payroll run ' . $runId . ' failed: ' . $e->getMessage());
		}
	}//end settleReservations()

}//end class
