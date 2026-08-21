<?php

/**
 * Hrmq TimesheetAggregationService
 *
 * The ONE recompute of a Timesheet's aggregates from its TimeEntry rows
 * (hours-process-redesign Decision 3, REQ-TEC-004): `hours` (sum, 2
 * decimals), `entryCount`, and the denormalized event-contract aggregates —
 * `projectId`/`costCenter` carry the single shared value when every entry
 * agrees and null otherwise; `billable` is true iff every entry (of at least
 * one) is billable. Recompute-from-truth, never increment, so a re-run over
 * unchanged entries is a byte-identical no-op.
 *
 * Shared on purpose: TimesheetAggregateListener invokes it on every entry
 * write and the MigrateHoursProcess repair step invokes it directly, so
 * migration and runtime can never produce different totals (Decision 9).
 * The persisting write runs under the request-scoped
 * {@see InternalWriteMarker}, under which TimesheetProcessStampListener lets
 * the aggregates through while still keeping process fields inert.
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use Psr\Container\ContainerInterface;

/**
 * Recomputes and persists a Timesheet's aggregates from its entries.
 *
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
 */
class TimesheetAggregationService {

	/**
	 * Upper bound for the entries query (dev-stage volumes).
	 *
	 * @var int
	 */
	private const LOOKUP_LIMIT = 2000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy OpenRegister lookup).
	 * @param InternalWriteMarker $marker The request-scoped internal-writer marker.
	 * @param SettingsService $settingsService The hrmq settings (register slug).
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly InternalWriteMarker $marker,
		private readonly SettingsService $settingsService,
	) {

	}//end __construct()

	/**
	 * Recompute one timesheet's aggregates from its entries and persist them
	 * when they differ from the stored values.
	 *
	 * @param string $timesheetId The timesheet uuid.
	 *
	 * @return array<string, mixed>|null The recomputed aggregate values, or
	 *                                   null when the timesheet is gone.
	 *
	 * @spec openspec/changes/hrmq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
	 */
	public function recomputeForTimesheet(string $timesheetId): ?array {
		$timesheetId = trim($timesheetId);
		if ($timesheetId === '') {
			return null;
		}

		$stored = $this->findTimesheet($timesheetId);
		if ($stored === null) {
			// A dangling timesheetId on an entry — nothing to maintain.
			return null;
		}

		$aggregates = $this->computeAggregates($this->findEntries($timesheetId));

		$unchanged = true;
		foreach ($aggregates as $field => $value) {
			if (($stored[$field] ?? null) !== $value) {
				$unchanged = false;
				break;
			}
		}

		if ($unchanged === true) {
			// Recompute-from-truth is idempotent by construction — skip the
			// write entirely rather than trusting a downstream no-op diff.
			return $aggregates;
		}

		$payload = array_merge($this->stripSelf($stored), $aggregates);
		$this->marker->runInternal(function () use ($payload, $timesheetId): void {
			$this->objects()->saveObject(
				object: $payload,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'Timesheet',
				uuid: $timesheetId,
				_rbac: false,
				_multitenancy: false
			);
		});

		return $aggregates;
	}//end recomputeForTimesheet()

	/**
	 * The pure aggregate computation over a set of entry payloads.
	 *
	 * Public so the unit tests assert the recomputed VALUES (sum,
	 * homogeneous-vs-null, all-billable) — not merely that a write occurred.
	 *
	 * @param array<int, array<string, mixed>> $entries The TimeEntry payloads.
	 *
	 * @return array<string, mixed> hours, entryCount, projectId, costCenter, billable.
	 *
	 * @spec openspec/changes/hrmq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
	 */
	public function computeAggregates(array $entries): array {
		$hours = 0.0;
		$billable = (count($entries) > 0);
		$projects = [];
		$costCenters = [];

		foreach ($entries as $entry) {
			$hours += (float)($entry['hours'] ?? 0);
			if ((bool)($entry['billable'] ?? false) === false) {
				$billable = false;
			}

			$projects[] = trim((string)($entry['projectId'] ?? ''));
			$costCenters[] = trim((string)($entry['costCenter'] ?? ''));
		}

		return [
			'hours' => round($hours, 2),
			'entryCount' => count($entries),
			'projectId' => $this->homogeneousOrNull($projects),
			'costCenter' => $this->homogeneousOrNull($costCenters),
			'billable' => $billable,
		];
	}//end computeAggregates()

	/**
	 * The single value every entry shares, or null (mixed, empty, or no
	 * entries) — the REQ-TEC-003 aggregate semantics.
	 *
	 * @param array<int, string> $values One (trimmed) value per entry.
	 *
	 * @return string|null The homogeneous value, or null.
	 *
	 * @spec openspec/changes/hrmq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-The-event-carries-what-a-finance-consumer-needs-(REQ-TEC-003)
	 */
	private function homogeneousOrNull(array $values): ?string {
		$distinct = array_values(array_unique($values));
		if (count($distinct) !== 1 || $distinct[0] === '') {
			return null;
		}

		return $distinct[0];
	}//end homogeneousOrNull()

	/**
	 * The TimeEntry payloads referencing a timesheet. Pushed-down filter,
	 * re-checked in PHP (belt and braces against filter-grammar drift).
	 *
	 * @param string $timesheetId The timesheet uuid.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 */
	private function findEntries(string $timesheetId): array {
		$rows = $this->objects()
			->setRegister($this->settingsService->getRegisterSlug())
			->setSchema('TimeEntry')
			->findAll(['limit' => self::LOOKUP_LIMIT, 'filters' => ['timesheetId' => $timesheetId]], false, false);

		$entries = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$entry = $this->toArray($row);
			if (trim((string)($entry['timesheetId'] ?? '')) === $timesheetId) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}//end findEntries()

	/**
	 * Load the timesheet's stored payload, or null when it is gone.
	 *
	 * @param string $timesheetId The timesheet uuid.
	 *
	 * @return array<string, mixed>|null The payload, or null.
	 */
	private function findTimesheet(string $timesheetId): ?array {
		try {
			$entity = $this->objects()->find(
				id: $timesheetId,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'Timesheet',
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		$data = $entity->getObject();

		return is_array($data) === true ? $data : null;
	}//end findTimesheet()

	/**
	 * Drop the read-model `@self` envelope before writing back (the
	 * AssetDialectMigrationService stripSelf precedent).
	 *
	 * @param array<string, mixed> $row The row as read.
	 *
	 * @return array<string, mixed> The writable payload.
	 */
	private function stripSelf(array $row): array {
		unset($row['@self']);

		return $row;
	}//end stripSelf()

	/**
	 * A CLONED OpenRegister ObjectService — never the shared instance, whose
	 * register/schema context may belong to an outer save in progress (this
	 * service runs from a post-save listener and from the repair step).
	 *
	 * @return object The cloned ObjectService.
	 */
	private function objects(): object {
		$service = $this->container->get('OCA\OpenRegister\Service\ObjectService');

		return clone $service;
	}//end objects()

	/**
	 * Normalise a findAll row to an array.
	 *
	 * @param mixed $row The row (array or ObjectEntity).
	 *
	 * @return array<string, mixed> The payload array.
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()

}//end class
