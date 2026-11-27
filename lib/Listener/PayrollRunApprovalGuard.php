<?php

/**
 * Payroll Run Approval Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pre-save listener on OpenRegister's `ObjectCreatingEvent` /
 * `ObjectUpdatingEvent` for `PayrollRun` writes. Once the shipped Loonrun flow
 * is adopted (enabled), the reviewer's decision in its review step is the only
 * approval of a run: a raw write that moves a run out of draft into approved,
 * posted or paid is refused and names the review step. The flow's own approve
 * step writes under the internal-write marker and passes, as does a shipped
 * import. Before adoption the raw status edit stays the production path, so
 * nothing is refused.
 *
 * Fails open on the adoption lookup only: an instance whose flow store cannot
 * be read is treated as not having adopted the flow, the documented default,
 * rather than refusing every approval on it. The failure is logged.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-run-pay-date-and-schedule/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\LoonrunFlowRepairService;
use OCA\Humaniq\Service\ShippedFlowAdoption;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Only the adopted Loonrun flow approves a payroll run.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/payroll-run-pay-date-and-schedule/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-003
 */
class PayrollRunApprovalGuard implements IEventListener {

	public const SCHEMA_SLUG = 'payrollrun';

	/**
	 * The statuses a run only reaches through an approval.
	 *
	 * @var string[]
	 */
	private const APPROVED_STATUSES = ['approved', 'posted', 'paid'];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway  Resolves the written object's schema slug.
	 * @param InternalWriteMarker  $marker   Set while the flow's approve step writes.
	 * @param ShippedFlowAdoption  $adoption Whether the Loonrun flow is adopted.
	 * @param LoggerInterface      $logger   Logs an adoption lookup that failed.
	 *
	 * @spec openspec/changes/payroll-run-pay-date-and-schedule/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-003
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
		private readonly ShippedFlowAdoption $adoption,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse a raw approval of a run once the Loonrun flow is adopted.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-run-pay-date-and-schedule/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-003
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PreSaveEvents::entityOf and ::refuse are the shared reader and refusal every pre-save guard uses.
	 */
	public function handle(Event $event): void {
		if ($this->marker->isInternal() === true || $this->marker->isShippedImport() === true) {
			return;
		}

		$entity = PreSaveEvents::entityOf($event);
		if ($entity === null || $this->isRun(entity: $entity) === false) {
			return;
		}

		$newStatus = (string)(($entity->getObject() ?? [])['status'] ?? '');
		if ($this->approves(newStatus: $newStatus, oldStatus: $this->oldStatus(event: $event)) === false) {
			return;
		}

		if ($this->flowAdopted() === false) {
			return;
		}

		PreSaveEvents::refuse(
			$event,
			'Een loonrun wordt goedgekeurd in de beoordelingsstap (review) van de Loonrun-flow. '
			. 'Een directe statuswijziging naar "' . $newStatus . '" is geweigerd; rond de beoordelingstaak af.'
		);
	}//end handle()

	/**
	 * Whether the write moves a run from draft (or nothing) to a status only
	 * an approval reaches.
	 *
	 * @param string      $newStatus The written status.
	 * @param string|null $oldStatus The stored status, null on a create.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-run-pay-date-and-schedule/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-003
	 */
	private function approves(string $newStatus, ?string $oldStatus): bool {
		if (in_array($newStatus, self::APPROVED_STATUSES, true) === false) {
			return false;
		}

		return $oldStatus === null || in_array($oldStatus, self::APPROVED_STATUSES, true) === false;
	}//end approves()

	/**
	 * The stored status on an update, null on a create.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/payroll-run-pay-date-and-schedule/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-003
	 */
	private function oldStatus(Event $event): ?string {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return null;
		}

		$old = ($event->getOldObject()?->getObject() ?? []);

		return (string)($old['status'] ?? '');
	}//end oldStatus()

	/**
	 * Whether the Loonrun flow is adopted; false when that cannot be read.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-run-pay-date-and-schedule/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-003
	 */
	private function flowAdopted(): bool {
		try {
			return $this->adoption->isAdopted(
				app: LoonrunFlowRepairService::FLOW_APP,
				name: LoonrunFlowRepairService::FLOW_NAME,
				triggerSchema: LoonrunFlowRepairService::FLOW_TRIGGER_SCHEMA
			);
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: PayrollRunApprovalGuard could not read whether the Loonrun flow is adopted', ['exception' => $e->getMessage()]);
			return false;
		}
	}//end flowAdopted()

	/**
	 * Whether the entity is a PayrollRun.
	 *
	 * @param object $entity The OpenRegister object.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-run-pay-date-and-schedule/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-003
	 */
	private function isRun(object $entity): bool {
		return strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) === self::SCHEMA_SLUG;
	}//end isRun()
}//end class
