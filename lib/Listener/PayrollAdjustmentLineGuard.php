<?php

/**
 * Payroll Adjustment Line Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Refuses a retro correction that names a payslip line which is not a line of
 * its own original payslip (payroll-mutation-lineage D4). The link is
 * optional; when it is set it must hold, or the chain from change to payslip
 * line to correction would point at someone else's pay.
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
 * @spec openspec/changes/payroll-mutation-lineage/specs/payroll-mutation-lineage/spec.md#requirement-corrections-and-mutation-reports-shall-point-at-payslip-lines-req-pml-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Pre-save guard on PayrollAdjustment.correctsPayslipLineId.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/payroll-mutation-lineage/specs/payroll-mutation-lineage/spec.md#requirement-corrections-and-mutation-reports-shall-point-at-payslip-lines-req-pml-003
 */
class PayrollAdjustmentLineGuard implements IEventListener {

	/**
	 * The schema slug this guard watches, lower-cased as the filter compares.
	 */
	public const PAYROLLADJUSTMENT_SLUG = 'payrolladjustment';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Reads the named payslip line and resolves schema slugs.
	 * @param LoggerInterface      $logger  Logger.
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Refuse the save when the named line is not a line of the original payslip.
	 *
	 * @param Event $event An ObjectCreatingEvent or ObjectUpdatingEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/tasks.md#task-2.4
	 */
	public function handle(Event $event): void {
		$incoming = $this->incoming($event);
		if ($incoming === null) {
			return;
		}

		$lineId = trim((string)($incoming['correctsPayslipLineId'] ?? ''));
		if ($lineId === '') {
			return;
		}

		try {
			$line = $this->gateway->findObjectData($lineId, 'PayslipLine');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'humaniq: PayrollAdjustmentLineGuard could not read a payslip line',
				['exception' => $e->getMessage()]
			);
			PreSaveEvents::refuse($event, 'De loonstrookregel kon niet worden gecontroleerd; probeer het later opnieuw.');
			return;
		}

		$refusal = self::refusal(adjustment: $incoming, line: $line);
		if ($refusal !== null) {
			PreSaveEvents::refuse($event, $refusal);
		}
	}//end handle()

	/**
	 * Why a correction may not name this line, or null when it may.
	 *
	 * @param array<string, mixed>      $adjustment The incoming PayrollAdjustment.
	 * @param array<string, mixed>|null $line       The named PayslipLine, or null when it does not exist.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/tasks.md#task-2.4
	 */
	public static function refusal(array $adjustment, ?array $line): ?string {
		$lineId = trim((string)($adjustment['correctsPayslipLineId'] ?? ''));
		if ($lineId === '') {
			return null;
		}

		if ($line === null) {
			return 'De gekozen loonstrookregel bestaat niet.';
		}

		$originalPayslipId = trim((string)($adjustment['originalPayslipId'] ?? ''));
		if ($originalPayslipId === '' || trim((string)($line['payslipId'] ?? '')) !== $originalPayslipId) {
			return 'Een correctie kan alleen een regel van haar eigen oorspronkelijke loonstrook corrigeren.';
		}

		return null;
	}//end refusal()

	/**
	 * The incoming PayrollAdjustment of a pre-save event, or null for any
	 * other object.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function incoming(Event $event): ?array {
		$entity = null;
		if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent) {
			$entity = $event->getObject();
		} else if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
		}

		if ($entity === null || strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== self::PAYROLLADJUSTMENT_SLUG) {
			return null;
		}

		return ($entity->getObject() ?? []);
	}//end incoming()
}//end class
