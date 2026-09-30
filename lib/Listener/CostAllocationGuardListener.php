<?php

/**
 * Cost Allocation Guard Listener
 *
 * Refuses a CostAllocation save whose fixed splits do not add up to 100
 * percent, and one that overlaps another allocation of the same employee
 * (payroll-cost-allocation D1): two allocations would give two answers for
 * one payslip, and the run would take whichever the store returned first.
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
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * The two save guards of a cost allocation.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
 */
class CostAllocationGuardListener implements IEventListener {

	/**
	 * Lower-cased slug of the schema this listener guards.
	 *
	 * @var string
	 */
	public const COSTALLOCATION_SLUG = 'costallocation';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The register plumbing.
	 * @param LoggerInterface      $logger  The logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a pre-save event for a CostAllocation write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
	 */
	public function handle(Event $event): void {
		$incoming = $this->incoming($event);
		if ($incoming === null) {
			return;
		}

		[$payload, $uuid] = $incoming;

		$refusal = $this->splitsRefusal($payload);
		if ($refusal !== null) {
			$this->refuse($event, $refusal);
			return;
		}

		try {
			$refusal = $this->overlapRefusal(payload: $payload, uuid: $uuid);
		} catch (\Throwable $e) {
			// Fail closed: an unchecked allocation is a second answer waiting
			// to happen, and the journal would book on whichever came first.
			$this->logger->warning('humaniq: CostAllocationGuardListener could not check a CostAllocation write', ['exception' => $e->getMessage()]);
			$refusal = 'De kostenverdeling kon niet worden gecontroleerd op overlap; probeer het later opnieuw.';
		}

		if ($refusal !== null) {
			$this->refuse($event, $refusal);
		}
	}//end handle()

	/**
	 * Why the splits of a fixed allocation are refused, or null.
	 *
	 * @param array<string, mixed> $payload The incoming allocation.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
	 */
	private function splitsRefusal(array $payload): ?string {
		if (($payload['basis'] ?? 'fixed') !== 'fixed') {
			return null;
		}

		$splits = (array)($payload['splits'] ?? []);
		if ($splits === []) {
			return 'Een vaste kostenverdeling heeft minstens een kostenplaats met een percentage nodig.';
		}

		$hundredths = 0;
		foreach ($splits as $split) {
			$hundredths += (int)round(((float)($split['percentage'] ?? 0)) * 100);
		}

		if ($hundredths !== 10000) {
			return sprintf('De percentages tellen op tot %s%%, niet tot 100%%.', rtrim(rtrim(number_format(($hundredths / 100), 2, '.', ''), '0'), '.'));
		}

		return null;
	}//end splitsRefusal()

	/**
	 * Why the allocation overlaps a stored one of the same employee, or null.
	 *
	 * @param array<string, mixed> $payload The incoming allocation.
	 * @param string|null          $uuid    The uuid being updated.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
	 */
	private function overlapRefusal(array $payload, ?string $uuid): ?string {
		$employeeId = trim((string)($payload['employeeId'] ?? ''));
		$start = trim((string)($payload['startDate'] ?? ''));
		if ($employeeId === '' || $start === '') {
			// The schema requires both; nothing to compare without them.
			return null;
		}

		$end = trim((string)($payload['endDate'] ?? ''));
		foreach ($this->gateway->findFiltered('CostAllocation', ['employeeId' => $employeeId]) as $row) {
			if ($uuid !== null && (string)($row['id'] ?? '') === $uuid) {
				continue;
			}

			$rowStart = trim((string)($row['startDate'] ?? ''));
			$rowEnd = trim((string)($row['endDate'] ?? ''));
			if (($end === '' || $rowStart === '' || $rowStart <= $end) && ($rowEnd === '' || $start <= $rowEnd)) {
				return sprintf('Deze medewerker heeft al een kostenverdeling vanaf %s die deze periode overlapt. Beëindig die eerst.', ($rowStart === '' ? '?' : $rowStart));
			}
		}

		return null;
	}//end overlapRefusal()

	/**
	 * The payload and uuid of a CostAllocation write, or null for any other
	 * event or schema.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{0: array<string, mixed>, 1: string|null}|null
	 */
	private function incoming(Event $event): ?array {
		if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent) {
			$entity = $event->getObject();
			return ($this->isCostAllocation($entity) === true ? [($entity->getObject() ?? []), null] : null);
		}

		if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
			return ($this->isCostAllocation($entity) === true ? [($entity->getObject() ?? []), (string)$entity->getUuid()] : null);
		}

		return null;
	}//end incoming()

	/**
	 * Whether the entity is a CostAllocation.
	 *
	 * @param object $entity The ObjectEntity.
	 *
	 * @return bool
	 */
	private function isCostAllocation(object $entity): bool {
		return (strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) === self::COSTALLOCATION_SLUG);
	}//end isCostAllocation()

	/**
	 * Stop the write and carry the reason back to the caller.
	 *
	 * @param object $event   The dispatched event.
	 * @param string $message The refusal.
	 *
	 * @return void
	 */
	private function refuse(object $event, string $message): void {
		if (method_exists($event, 'setErrors') === false || method_exists($event, 'stopPropagation') === false) {
			return;
		}

		$event->setErrors(['message' => $message]);
		$event->stopPropagation();
	}//end refuse()

}//end class
