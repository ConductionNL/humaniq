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
			PreSaveEvents::refuse($event, $refusal);
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
			PreSaveEvents::refuse($event, $refusal);
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

		$hundredths = self::percentageHundredths($splits);
		if ($hundredths !== 10000) {
			return sprintf('De percentages tellen op tot %s%%, niet tot 100%%.', rtrim(rtrim(number_format(($hundredths / 100), 2, '.', ''), '0'), '.'));
		}

		return null;
	}//end splitsRefusal()

	/**
	 * The splits' percentages summed in hundredths of a percent, so the
	 * 100% check never meets float drift.
	 *
	 * @param array<int|string, mixed> $splits The fixed splits.
	 *
	 * @return int
	 */
	private static function percentageHundredths(array $splits): int {
		$hundredths = 0;
		foreach ($splits as $split) {
			$hundredths += (int)round(((float)($split['percentage'] ?? 0)) * 100);
		}

		return $hundredths;
	}//end percentageHundredths()

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

		return $this->overlapWith(payload: $payload, rows: $this->otherAllocationsOf(employeeId: $employeeId, uuid: $uuid));
	}//end overlapRefusal()

	/**
	 * The employee's stored allocations, without the one being updated.
	 *
	 * @param string $employeeId The employee.
	 * @param string|null $uuid The uuid being updated, null on create.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function otherAllocationsOf(string $employeeId, ?string $uuid): array {
		$rows = [];
		foreach ($this->gateway->findFiltered('CostAllocation', ['employeeId' => $employeeId]) as $row) {
			if ($uuid !== null && (string)($row['id'] ?? '') === $uuid) {
				continue;
			}

			$rows[] = $row;
		}

		return $rows;
	}//end otherAllocationsOf()

	/**
	 * Why the allocation overlaps the first of the stored rows it shares a
	 * day with, or null.
	 *
	 * @param array<string, mixed> $payload The incoming allocation.
	 * @param array<int, array<string, mixed>> $rows The other stored allocations.
	 *
	 * @return string|null
	 */
	private function overlapWith(array $payload, array $rows): ?string {
		$start = trim((string)($payload['startDate'] ?? ''));
		$end = trim((string)($payload['endDate'] ?? ''));
		foreach ($rows as $row) {
			$rowStart = trim((string)($row['startDate'] ?? ''));
			if ($this->overlaps(start: $start, end: $end, rowStart: $rowStart, rowEnd: trim((string)($row['endDate'] ?? ''))) === true) {
				return sprintf('Deze medewerker heeft al een kostenverdeling vanaf %s die deze periode overlapt. Beëindig die eerst.', ($rowStart === '' ? '?' : $rowStart));
			}
		}

		return null;
	}//end overlapWith()

	/**
	 * Whether two date ranges share a day; an empty end is open-ended, an
	 * empty start has always applied.
	 *
	 * @param string $start    The incoming start.
	 * @param string $end      The incoming end, or ''.
	 * @param string $rowStart The stored start, or ''.
	 * @param string $rowEnd   The stored end, or ''.
	 *
	 * @return bool
	 */
	private function overlaps(string $start, string $end, string $rowStart, string $rowEnd): bool {
		$startsBeforeEnd = ($end === '' || $rowStart === '' || $rowStart <= $end);
		return ($startsBeforeEnd === true && ($rowEnd === '' || $start <= $rowEnd));
	}//end overlaps()

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
			return $this->costAllocationWrite(entity: $event->getObject(), isUpdate: false);
		}

		if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) {
			return $this->costAllocationWrite(entity: $event->getNewObject(), isUpdate: true);
		}

		return null;
	}//end incoming()

	/**
	 * The payload and uuid (an update's only) of a written entity, or null
	 * when it is not a CostAllocation.
	 *
	 * @param object $entity The ObjectEntity being written.
	 * @param bool $isUpdate Whether the write updates a stored object.
	 *
	 * @return array{0: array<string, mixed>, 1: string|null}|null
	 */
	private function costAllocationWrite(object $entity, bool $isUpdate): ?array {
		if ($this->isCostAllocation($entity) === false) {
			return null;
		}

		return [($entity->getObject() ?? []), ($isUpdate === true ? (string)$entity->getUuid() : null)];
	}//end costAllocationWrite()

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

}//end class
