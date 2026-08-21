<?php

/**
 * Hrmq Migrate Hours Process Repair Step
 *
 * hours-process-redesign Decision 9: idempotent one-pass migration of
 * pre-existing Timesheet rows onto the entry/aggregate split. Per row:
 *
 * 1. **Backfill the identity caches** — `userId` from the linked Employee's
 *    `nextcloudUserId`, `managerUserId` via the shared OrgResolutionService
 *    chain, `administrationId` from the Employee. Rows whose chain does not
 *    resolve keep null (fail-closed for the `@me` filters, REQ-MHS-002) and
 *    are COUNTED, never per-row-logged.
 * 2. **Synthesize one entry** for a Timesheet with `hours > 0` and ZERO
 *    existing TimeEntry rows: `origin: "migration"`, `startedAt` = the first
 *    day of the period at 00:00 UTC (month grain; week grains via the same
 *    ISO-week parsing family as the typed event's grain classifier),
 *    `endedAt = startedAt + hours`, description/projectId/costCenter/
 *    billable copied down from the legacy timesheet fields. The synthetic
 *    entry is a bookkeeping artifact, not a claim about when work happened —
 *    its `origin` marker says so.
 * 3. **Recompute the aggregates** through the SAME
 *    TimesheetAggregationService the runtime listener uses, so migration and
 *    runtime can never produce different totals.
 *
 * Idempotency: step 2's guard is "zero existing entries" (a re-run creates
 * nothing); steps 1 and 3 are pure recomputes. Warn-once semantics: exactly
 * ONE summary line per run — `hrmq: hours-process migration: N timesheets
 * processed, M entries created, K rows with unresolvable user link` — never
 * one warning per row.
 *
 * Every write runs under the request-scoped {@see InternalWriteMarker}, so
 * the mutability guard admits entries for approved historical rows (which
 * become immutable to everyone else immediately) and the stamping listener
 * lets the backfilled caches through.
 *
 * Registered under BOTH `<post-migration>` and `<install>` in
 * appinfo/info.xml — `<install>` is the only hook that runs unconditionally
 * on a fresh install (the InitializeRegister precedent documents the fleet
 * lesson); on fresh installs this step finds the seed rows and behaves
 * identically. Fails soft when OpenRegister is not (yet) enabled.
 *
 * @category Repair
 * @package  OCA\Hrmq\Repair
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
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
 */

declare(strict_types=1);

namespace OCA\Hrmq\Repair;

use OCA\Hrmq\Service\InternalWriteMarker;
use OCA\Hrmq\Service\OrgResolutionService;
use OCA\Hrmq\Service\SettingsService;
use OCA\Hrmq\Service\TimesheetAggregationService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Backfills identity caches, synthesizes migration entries, recomputes
 * aggregates — idempotently, with one summary line per run.
 *
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
 */
class MigrateHoursProcess implements IRepairStep {

	/**
	 * Upper bound for lookup queries (dev-stage volumes).
	 *
	 * @var int
	 */
	private const LOOKUP_LIMIT = 2000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy OpenRegister lookup).
	 * @param InternalWriteMarker $marker The request-scoped internal-writer marker.
	 * @param OrgResolutionService $orgResolution The shared org-chain resolver.
	 * @param TimesheetAggregationService $aggregationService The shared aggregate recompute.
	 * @param SettingsService $settingsService The hrmq settings (register slug).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly InternalWriteMarker $marker,
		private readonly OrgResolutionService $orgResolution,
		private readonly TimesheetAggregationService $aggregationService,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The repair step's display name.
	 *
	 * @return string The name.
	 *
	 * @spec exclude IRepairStep boilerplate — a display label with no behaviour
	 */
	public function getName(): string {
		return 'Migrate hrmq timesheets onto the hours-process entry/aggregate split';
	}//end getName()

	/**
	 * Run the migration (safe to re-run; one summary line per run).
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
	 */
	public function run(IOutput $output): void {
		if (class_exists('OCA\OpenRegister\Service\ObjectService') === false) {
			// Fail soft: OpenRegister not (yet) enabled — the InitializeRegister precedent.
			$output->info('hrmq: hours-process migration skipped (OpenRegister is not available).');
			return;
		}

		try {
			$summary = $this->runner()->runAsActingUser(fn (): array => $this->migrate());
		} catch (\Throwable $e) {
			if ($this->runner()->deferIfMaintenanceDenied($e, 'OCA\Hrmq\BackgroundJob\CompleteHoursMigrationJob') === true) {
				$message = 'hrmq: hours-process migration deferred to a background job '
					. '(object folders are unreachable while maintenance mode is on).';
				$output->info($message);
				$this->logger->info($message);
				return;
			}

			$this->logger->warning(
				'hrmq: hours-process migration failed',
				['exception' => $e->getMessage()]
			);
			$output->warning('hrmq: hours-process migration failed: ' . $e->getMessage());
			return;
		}

		$line = sprintf(
			'hrmq: hours-process migration: %d timesheets processed, %d entries created, %d rows with unresolvable user link',
			$summary['processed'],
			$summary['entriesCreated'],
			$summary['unresolvableUserLinks']
		);
		$output->info($line);
		$this->logger->info($line);
	}//end run()

	/**
	 * Run the full migration pass for the background-job completion vehicle.
	 *
	 * @return array{processed: int, entriesCreated: int, unresolvableUserLinks: int} The summary counters.
	 *
	 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
	 */
	public function runDeferred(): array {
		return $this->runner()->runAsActingUser(fn (): array => $this->migrate());
	}//end runDeferred()

	/**
	 * The lazily resolved execution-context runner (acting user + deferral).
	 *
	 * @return \OCA\Hrmq\Service\HoursMigrationRunner The runner.
	 *
	 * @spec exclude Trivial lazy container accessor; behaviour lives on HoursMigrationRunner.
	 */
	private function runner(): \OCA\Hrmq\Service\HoursMigrationRunner {
		return $this->container->get(\OCA\Hrmq\Service\HoursMigrationRunner::class);
	}//end runner()

	/**
	 * The migration pass. Public-facing summary counts; exactly one caller
	 * pass per run — the counters ARE the warn-once semantics.
	 *
	 * @return array{processed: int, entriesCreated: int, unresolvableUserLinks: int} The summary counters.
	 *
	 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
	 */
	public function migrate(): array {
		$employeesById = $this->indexById($this->loadAll('Employee'));
		$unitsById = $this->indexById($this->loadAll('OrgUnit'));
		$assignmentsByEmployeeId = $this->assignmentsByEmployeeId($this->loadAll('OrgAssignment'));
		$entryCountByTimesheetId = $this->entryCountsByTimesheetId($this->loadAll('TimeEntry'));

		$processed = 0;
		$entriesCreated = 0;
		$unresolvable = 0;

		foreach ($this->loadAll('Timesheet') as $timesheet) {
			$id = trim((string)($timesheet['id'] ?? $timesheet['@self']['id'] ?? ''));
			if ($id === '') {
				continue;
			}

			$processed++;

			// 1. Backfill the identity caches.
			$resolvedUser = $this->backfillCaches(
				timesheetId: $id,
				timesheet: $timesheet,
				employeesById: $employeesById,
				unitsById: $unitsById,
				assignmentsByEmployeeId: $assignmentsByEmployeeId
			);
			if ($resolvedUser === false) {
				$unresolvable++;
			}

			// 2. Synthesize one migration entry for legacy hours.
			$hasEntries = (($entryCountByTimesheetId[$id] ?? 0) > 0);
			if ($hasEntries === false && (float)($timesheet['hours'] ?? 0) > 0) {
				if ($this->synthesizeEntry($id, $timesheet) === true) {
					$entriesCreated++;
				}
			}

			// 3. Recompute the aggregates through the shared service.
			$this->aggregationService->recomputeForTimesheet($id);
		}//end foreach

		return [
			'processed' => $processed,
			'entriesCreated' => $entriesCreated,
			'unresolvableUserLinks' => $unresolvable,
		];
	}//end migrate()

	/**
	 * Backfill `userId` / `managerUserId` / `administrationId` on one row.
	 * Writes only when a value changes (pure recompute — a re-run no-ops).
	 *
	 * @param string $timesheetId The timesheet uuid.
	 * @param array<string, mixed> $timesheet The timesheet payload.
	 * @param array<string, array<string, mixed>> $employeesById Employee index.
	 * @param array<string, array<string, mixed>> $unitsById OrgUnit index.
	 * @param array<string, array<int, array<string, mixed>>> $assignmentsByEmployeeId OrgAssignment index.
	 *
	 * @return bool True when the user link resolved, false when it stays null.
	 *
	 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
	 */
	private function backfillCaches(
		string $timesheetId,
		array $timesheet,
		array $employeesById,
		array $unitsById,
		array $assignmentsByEmployeeId,
	): bool {
		$employeeId = trim((string)($timesheet['employeeId'] ?? ''));
		$employee = ($employeesById[$employeeId] ?? []);

		$userId = $this->nullableTrim($employee['nextcloudUserId'] ?? null);
		$administrationId = $this->nullableTrim($employee['administrationId'] ?? null);
		$managerUserId = $this->orgResolution->uniqueOrNull(
			$this->orgResolution->resolveManagerUserIds(
				employeeId: $employeeId,
				assignmentsByEmployeeId: $assignmentsByEmployeeId,
				unitsById: $unitsById,
				employeesById: $employeesById,
				onDate: gmdate('Y-m-d')
			)
		);

		$caches = [
			'userId' => $userId,
			'managerUserId' => $managerUserId,
			'administrationId' => $administrationId,
		];

		$changed = false;
		foreach ($caches as $field => $value) {
			if ($this->nullableTrim($timesheet[$field] ?? null) !== $value) {
				$changed = true;
				break;
			}
		}

		if ($changed === true) {
			$payload = array_merge($this->stripSelf($timesheet), $caches);
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
		}

		return $userId !== null;
	}//end backfillCaches()

	/**
	 * Create the one `origin: "migration"` entry for a legacy timesheet.
	 *
	 * @param string $timesheetId The timesheet uuid.
	 * @param array<string, mixed> $timesheet The timesheet payload.
	 *
	 * @return bool True when an entry was created.
	 *
	 * @spec openspec/changes/hrmq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
	 */
	private function synthesizeEntry(string $timesheetId, array $timesheet): bool {
		$startedAt = $this->periodStart(trim((string)($timesheet['period'] ?? '')));
		if ($startedAt === null) {
			// An unparseable period cannot anchor a synthetic booking; the
			// aggregates still recompute (to the truth: zero entries).
			return false;
		}

		$hours = (float)($timesheet['hours'] ?? 0);
		$endedAt = ($startedAt + (int)round($hours * 3600));

		$entry = [
			'employeeId' => trim((string)($timesheet['employeeId'] ?? '')),
			'timesheetId' => $timesheetId,
			'startedAt' => gmdate('Y-m-d\TH:i:s\Z', $startedAt),
			'endedAt' => gmdate('Y-m-d\TH:i:s\Z', $endedAt),
			'breakMinutes' => 0,
			'hours' => round($hours, 2),
			'description' => (string)($timesheet['description'] ?? ''),
			'projectId' => $this->nullableTrim($timesheet['projectId'] ?? null),
			'costCenter' => $this->nullableTrim($timesheet['costCenter'] ?? null),
			'billable' => (bool)($timesheet['billable'] ?? false),
			'userId' => $this->nullableTrim($timesheet['userId'] ?? null),
			'administrationId' => $this->nullableTrim($timesheet['administrationId'] ?? null),
			'origin' => 'migration',
		];

		$this->marker->runInternal(function () use ($entry): void {
			$this->objects()->saveObject(
				object: $entry,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'TimeEntry',
				_rbac: false,
				_multitenancy: false
			);
		});

		return true;
	}//end synthesizeEntry()

	/**
	 * The period's first moment at 00:00 UTC — month grain (`YYYY-MM`),
	 * week grain (`YYYY-Www`) and week-day grain (`YYYY-Www-D`), the same
	 * grain family the typed event's classifier recognises.
	 *
	 * @param string $period The period string.
	 *
	 * @return int|null The UTC start timestamp, or null when unparseable.
	 */
	private function periodStart(string $period): ?int {
		if (preg_match('/^(\d{4})-(\d{2})$/', $period, $match) === 1) {
			$start = strtotime(sprintf('%s-%s-01T00:00:00Z', $match[1], $match[2]));
			return ($start === false) ? null : $start;
		}

		if (preg_match('/^(\d{4})-W(\d{2})(?:-(\d))?$/', $period, $match) === 1) {
			// strtotime understands ISO week dates; anchor to UTC midnight.
			$day = isset($match[3]) === true ? (int)$match[3] : 1;
			$start = strtotime(sprintf('%d-W%02d-%dT00:00:00Z', (int)$match[1], (int)$match[2], $day));
			return ($start === false) ? null : $start;
		}

		return null;
	}//end periodStart()

	/**
	 * How many TimeEntry rows reference each timesheet.
	 *
	 * @param array<int, array<string, mixed>> $entries All TimeEntry rows.
	 *
	 * @return array<string, int> Counts keyed by timesheet uuid.
	 */
	private function entryCountsByTimesheetId(array $entries): array {
		$counts = [];
		foreach ($entries as $entry) {
			$timesheetId = trim((string)($entry['timesheetId'] ?? ''));
			if ($timesheetId !== '') {
				$counts[$timesheetId] = (($counts[$timesheetId] ?? 0) + 1);
			}
		}

		return $counts;
	}//end entryCountsByTimesheetId()

	/**
	 * The OrgAssignment index keyed by employeeId (shared chain shape).
	 *
	 * @param array<int, array<string, mixed>> $assignments All OrgAssignment rows.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The index.
	 */
	private function assignmentsByEmployeeId(array $assignments): array {
		$index = [];
		foreach ($assignments as $assignment) {
			$employeeId = trim((string)($assignment['employeeId'] ?? ''));
			if ($employeeId !== '') {
				$index[$employeeId][] = $assignment;
			}
		}

		return $index;
	}//end assignmentsByEmployeeId()

	/**
	 * Key rows by their id.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, array<string, mixed>> The byId index.
	 */
	private function indexById(array $rows): array {
		$index = [];
		foreach ($rows as $row) {
			$id = trim((string)($row['id'] ?? $row['@self']['id'] ?? ''));
			if ($id !== '') {
				$index[$id] = $row;
			}
		}

		return $index;
	}//end indexById()

	/**
	 * Load every row of a schema as arrays (the AssetDialectMigrationService
	 * loadAll precedent).
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objects()
				->setRegister($this->settingsService->getRegisterSlug())
				->setSchema($schema)
				->findAll(['limit' => self::LOOKUP_LIMIT], false, false);
		} catch (\Throwable $e) {
			$this->logger->warning('hrmq: MigrateHoursProcess could not load ' . $schema . ': ' . $e->getMessage());
			return [];
		}

		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$out[] = (array)$row->jsonSerialize();
			}
		}

		return $out;
	}//end loadAll()

	/**
	 * Drop the read-model `@self` envelope before writing back.
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
	 * register/schema context may be someone else's live state.
	 *
	 * @return object The cloned ObjectService.
	 */
	private function objects(): object {
		$service = $this->container->get('OCA\OpenRegister\Service\ObjectService');

		return clone $service;
	}//end objects()

	/**
	 * Trim to a non-empty string or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The trimmed value, or null.
	 */
	private function nullableTrim(mixed $value): ?string {
		$trimmed = trim((string)($value ?? ''));

		return $trimmed === '' ? null : $trimmed;
	}//end nullableTrim()

}//end class
