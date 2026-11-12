<?php

/**
 * Humaniq Migrate Hours Process Repair Step
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * ONE summary line per run — `humaniq: hours-process migration: N timesheets
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
 * @package  OCA\Humaniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Repair;

use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\TimesheetAggregationService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\Humaniq\Support\KeepsReadableRows;

/**
 * Backfills identity caches, synthesizes migration entries, recomputes
 * aggregates — idempotently, with one summary line per run.
 *
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
 */
class MigrateHoursProcess implements IRepairStep {

	use KeepsReadableRows;

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
	 * @param SettingsService $settingsService The humaniq settings (register slug).
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
		return 'Migrate humaniq timesheets onto the hours-process entry/aggregate split';
	}//end getName()

	/**
	 * Run the migration (safe to re-run; one summary line per run).
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
	 */
	public function run(IOutput $output): void {
		if (class_exists('OCA\OpenRegister\Service\ObjectService') === false) {
			// Fail soft: OpenRegister not (yet) enabled — the InitializeRegister precedent.
			$output->info('humaniq: hours-process migration skipped (OpenRegister is not available).');
			return;
		}

		try {
			$summary = $this->runner()->runAsActingUser(fn (): array => $this->migrate());
		} catch (\Throwable $e) {
			if ($this->runner()->deferIfMaintenanceDenied($e, 'OCA\Humaniq\BackgroundJob\CompleteHoursMigrationJob') === true) {
				$message = 'humaniq: hours-process migration deferred to a background job '
					. '(object folders are unreachable while maintenance mode is on).';
				$output->info($message);
				$this->logger->info($message);
				return;
			}

			$this->logger->warning(
				'humaniq: hours-process migration failed',
				['exception' => $e->getMessage()]
			);
			$output->warning('humaniq: hours-process migration failed: ' . $e->getMessage());
			return;
		}

		$line = sprintf(
			'humaniq: hours-process migration: %d timesheets processed, %d entries created, %d rows with unresolvable user link',
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
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
	 */
	public function runDeferred(): array {
		return $this->runner()->runAsActingUser(fn (): array => $this->migrate());
	}//end runDeferred()

	/**
	 * The lazily resolved execution-context runner (acting user + deferral).
	 *
	 * @return \OCA\Humaniq\Service\HoursMigrationRunner The runner.
	 *
	 * @spec exclude Trivial lazy container accessor; behaviour lives on HoursMigrationRunner.
	 */
	private function runner(): \OCA\Humaniq\Service\HoursMigrationRunner {
		return $this->container->get(\OCA\Humaniq\Service\HoursMigrationRunner::class);
	}//end runner()

	/**
	 * The migration pass. Public-facing summary counts; exactly one caller
	 * pass per run — the counters ARE the warn-once semantics.
	 *
	 * @return array{processed: int, entriesCreated: int, unresolvableUserLinks: int} The summary counters.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
	 */
	public function migrate(): array {
		$identity = new TimesheetIdentityCaches(
			orgResolution: $this->orgResolution,
			employeesById: $this->indexById($this->loadAll('Employee')),
			unitsById: $this->indexById($this->loadAll('OrgUnit')),
			assignmentsByEmployeeId: $this->assignmentsByEmployeeId($this->loadAll('OrgAssignment'))
		);
		$entryCountByTimesheetId = $this->entryCountsByTimesheetId($this->loadAll('TimeEntry'));

		$processed = 0;
		$entriesCreated = 0;
		$unresolvable = 0;

		foreach ($this->loadAll('Timesheet') as $timesheet) {
			$id = $this->rowId($timesheet);
			if ($id === '') {
				continue;
			}

			$processed++;

			// 1. Backfill the identity caches.
			$resolvedUser = $this->backfillCaches(timesheet: $timesheet, identity: $identity);
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
	 * @param array<string, mixed> $timesheet The timesheet payload.
	 * @param TimesheetIdentityCaches $identity The run's cache resolver.
	 *
	 * @return bool True when the user link resolved, false when it stays null.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
	 */
	private function backfillCaches(array $timesheet, TimesheetIdentityCaches $identity): bool {
		$timesheetId = $this->rowId($timesheet);
		$caches = $identity->resolve($timesheet);

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

		return $caches['userId'] !== null;
	}//end backfillCaches()

	/**
	 * Create the one `origin: "migration"` entry for a legacy timesheet.
	 *
	 * @param string $timesheetId The timesheet uuid.
	 * @param array<string, mixed> $timesheet The timesheet payload.
	 *
	 * @return bool True when an entry was created.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
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

		// The synthetic entry inherits the timesheet's employee link verbatim —
		// it is the SAME person's hours, and `TimeEntry.employeeId` is
		// `$ref: Employee` + `format: uuid`, so the value carried over is the
		// target's uuid. A legacy timesheet with no employee link contributes no
		// key at all rather than an empty string: `employeeId` is deliberately
		// absent from TimeEntry's `required` list (the stamp listener resolves it
		// after validation), so omitting it is legal, whereas writing "" would be
		// a uuid-format violation that fails the whole migration write.
		$employeeId = trim((string)($timesheet['employeeId'] ?? ''));
		if ($employeeId !== '') {
			$entry['employeeId'] = $employeeId;
		}

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
			$id = $this->rowId($row);
			if ($id !== '') {
				$index[$id] = $row;
			}
		}

		return $index;
	}//end indexById()

	/**
	 * A row's id, falling back to `@self.id`, trimmed ('' when absent).
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id.
	 */
	private function rowId(array $row): string {
		return trim((string)($row['id'] ?? $row['@self']['id'] ?? ''));
	}//end rowId()

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
			$this->logger->warning('humaniq: MigrateHoursProcess could not load ' . $schema . ': ' . $e->getMessage());
			return [];
		}

		return $this->normaliseRows($rows);
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
