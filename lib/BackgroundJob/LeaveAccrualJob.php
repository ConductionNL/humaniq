<?php

/**
 * Leave Accrual Job
 *
 * The automatic producer for the leave-verzuim `LeaveBalance` ledger
 * (leave-accrual-job): a `TimedJob` that provisions each active employee's
 * current-year `holiday` LeaveBalance with the full statutory (BW art. 7:634)
 * entitlement and accrues the bovenwettelijk opbouw slice, idempotently per
 * (employee, year, month) via the additive `lastAccruedPeriod` marker
 * (design.md D5), written through OpenRegister's ObjectService — mirroring
 * the active-employee/covering-contract selection idiom of
 * `PayrollRunService` (design.md D4).
 *
 * Statutory is granted in FULL on the year's first accrual — never built up
 * in monthly slices — so `nl-verlof-wettelijk-minimum` (mandatory) and
 * `nl-verlof-vervaltermijn` (mandatory) hold from the first write (design.md
 * D2). Only `bovenwettelijkHours` genuinely accrues 1/12 per month
 * (design.md D3). Correctness does not depend on the job's fire interval —
 * the per-balance `lastAccruedPeriod` guard defines "once a month"
 * (design.md D5); `run()` sets a modest ~1 day interval and relies entirely
 * on that guard.
 *
 * Employees the job cannot resolve honestly (no covering EmploymentContract,
 * no `hoursPerWeek`, no resolvable identity) are skipped with a counted
 * reason (design.md D4, the PayrollRunService skip-reporting precedent) —
 * never computed wrong, never silently dropped. `leave_accrual_enabled`
 * (SettingsService) is an operator off-switch checked first (design.md D6).
 *
 * @category BackgroundJob
 * @package  OCA\Hrmq\BackgroundJob
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
 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-001
 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-002
 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-003
 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-004
 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\BackgroundJob;

use DateTimeImmutable;
use OCA\Hrmq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Monthly statutory + bovenwettelijk leave accrual onto every active
 * employee's holiday LeaveBalance.
 */
class LeaveAccrualJob extends TimedJob {

	/**
	 * How often the job fires (~1 day). Correctness is independent of this
	 * value — the `lastAccruedPeriod` guard (design.md D5), not the
	 * interval, defines "once a month".
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Max objects loaded per type (matches PayrollRunService::LIMIT).
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * The leave type this job accrues (design.md non-goals: other leave
	 * types are excluded).
	 *
	 * @var string
	 */
	private const LEAVE_TYPE = 'holiday';

	/**
	 * @param ITimeFactory $time Time factory (also drives `run()`'s period/year).
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settingsService Register slug + `leave_accrual_enabled`/`leave_bovenwettelijk_annual_hours` config.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

	}//end __construct()

	/**
	 * The TimedJob entry point — runs the accrual and logs its summary.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-001
	 */
	protected function run($argument): void {
		$summary = $this->runAccrual();

		$this->logger->info(
			'LeaveAccrualJob: run complete',
			[
				'period' => $summary['period'],
				'provisioned' => count($summary['provisioned']),
				'accrued' => count($summary['accrued']),
				'noop' => $summary['noop'],
				'skipped' => count($summary['skipped']),
			]
		);

	}//end run()

	/**
	 * Run one accrual pass for the current period, returning a structured
	 * summary. Public (rather than folded into `run()`) so it can be driven
	 * directly by tests and by an `occ` command, mirroring
	 * `PayrollRunService::runFor()`'s outcome-array shape.
	 *
	 * @return array<string, mixed> {period, year, enabled, provisioned, accrued, skipped, noop}
	 *
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-001
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 * @spec openspec/changes/hrmq-personal-dashboard/specs/leave-accrual-job/spec.md#REQ-ACCR-006
	 */
	public function runAccrual(): array {
		if ($this->settingsService->isLeaveAccrualEnabled() === false) {
			return $this->summary('', 0, false, [], [], [], 0);
		}

		$now = (new DateTimeImmutable())->setTimestamp($this->time->getTime());
		$period = $now->format('Y-m');
		$year = (int)$now->format('Y');

		$contractsByEmployeeKey = $this->contractsByEmployeeKey();
		$balancesByEmployeeId = $this->holidayBalancesByEmployeeId($year);
		$deltaBovenwettelijk = $this->round1($this->settingsService->getLeaveBovenwettelijkAnnualHours() / 12);

		$provisioned = [];
		$accrued = [];
		$skipped = [];
		$noop = 0;

		foreach ($this->loadAll('Employee') as $employee) {
			if ($this->coversPeriod((string)($employee['startDate'] ?? ''), (string)($employee['endDate'] ?? ''), $period) === false) {
				// Not employed in this period — not selected, not reported.
				continue;
			}

			$employeeId = $this->idOf($employee);
			$employeeLabel = $this->employeeLabel($employee);

			if ($employeeId === '') {
				$skipped[] = ['employee' => $employeeLabel, 'reason' => 'no-employee-identity'];
				continue;
			}

			$contract = $this->coveringContract($employee, $contractsByEmployeeKey, $period);
			if ($contract === null) {
				$skipped[] = ['employee' => $employeeLabel, 'reason' => 'no-covering-contract'];
				continue;
			}

			$hoursPerWeek = ($contract['hoursPerWeek'] ?? null);
			if (is_numeric($hoursPerWeek) === false || ((float)$hoursPerWeek) <= 0.0) {
				$skipped[] = ['employee' => $employeeLabel, 'reason' => 'no-hours-per-week'];
				continue;
			}

			$hoursPerWeek = (float)$hoursPerWeek;
			$existing = ($balancesByEmployeeId[$employeeId] ?? null);
			// hrmq-personal-dashboard REQ-ACCR-006: the denormalized account
			// link, resolved from the SAME Employee row this iteration already
			// selected. Null for an employee with no linked account — never
			// guessed, never another account (fail-closed for @me surfaces).
			$userId = $this->nullableTrim($employee['nextcloudUserId'] ?? null);

			if ($existing === null) {
				try {
					$saved = $this->provision($employeeId, $year, $period, $hoursPerWeek, $deltaBovenwettelijk, $userId);
				} catch (\Throwable $e) {
					$this->logger->warning('LeaveAccrualJob: kon LeaveBalance niet aanmaken voor ' . $employeeLabel . ': ' . $e->getMessage());
					$skipped[] = ['employee' => $employeeLabel, 'reason' => 'save-failed'];
					continue;
				}

				$provisioned[] = ['employee' => $employeeLabel, 'leaveBalanceId' => $this->idOf($saved)];
				continue;
			}//end if

			$lastAccruedPeriod = (string)($existing['lastAccruedPeriod'] ?? '');
			if ($lastAccruedPeriod === $period) {
				// Already accrued this employee-month — a hard no-op, no write
				// (design.md D5, REQ-ACCR-004): correctness must not depend on
				// how often the TimedJob fires.
				$noop++;
				continue;
			}

			try {
				$saved = $this->accrueExisting($existing, $period, $hoursPerWeek, $deltaBovenwettelijk, $userId);
			} catch (\Throwable $e) {
				$this->logger->warning('LeaveAccrualJob: kon LeaveBalance niet bijwerken voor ' . $employeeLabel . ': ' . $e->getMessage());
				$skipped[] = ['employee' => $employeeLabel, 'reason' => 'save-failed'];
				continue;
			}

			$accrued[] = ['employee' => $employeeLabel, 'leaveBalanceId' => $this->idOf($saved)];
		}//end foreach

		return $this->summary($period, $year, true, $provisioned, $accrued, $skipped, $noop);
	}//end runAccrual()

	/**
	 * First-accrual provisioning (design.md D2/D5): the full annual statutory
	 * figure, one bovenwettelijk slice, the vervaltermijn, and the
	 * idempotency marker — never a monthly statutory slice.
	 *
	 * @param string $employeeId The Employee id.
	 * @param int $year Calendar year.
	 * @param string $period Current wage period (YYYY-MM), stamped as `lastAccruedPeriod`.
	 * @param float $hoursPerWeek The covering contract's `hoursPerWeek`.
	 * @param float $deltaBovenwettelijk This month's bovenwettelijk slice (`round1(annual/12)`).
	 * @param string|null $userId The employee's linked Nextcloud account id, or null when unlinked.
	 *
	 * @return array<string, mixed> The saved LeaveBalance.
	 *
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-002
	 * @spec openspec/changes/hrmq-personal-dashboard/specs/leave-accrual-job/spec.md#REQ-ACCR-006
	 */
	private function provision(string $employeeId, int $year, string $period, float $hoursPerWeek, float $deltaBovenwettelijk, ?string $userId = null): array {
		$payload = [
			'employeeId' => $employeeId,
			'year' => $year,
			'leaveType' => self::LEAVE_TYPE,
			'entitledHours' => (4 * $hoursPerWeek),
			'bovenwettelijkHours' => $deltaBovenwettelijk,
			'usedHours' => 0.0,
			'contractHoursPerWeek' => $hoursPerWeek,
			'expiryDate' => sprintf('%04d-07-01', ($year + 1)),
			'lastAccruedPeriod' => $period,
			// REQ-ACCR-006 / REQ-MHS-002: denormalized copy of the linked
			// Employee's nextcloudUserId, so the personal dashboard's
			// verlofsaldo widget can filter with the @me token. Null when the
			// employee has no account — the row then never appears on a Mijn
			// surface rather than being mis-attributed.
			'userId' => $userId,
		];

		return $this->toArray(
			$this->objectService()->saveObject(
				object: $payload,
				register: $this->register(),
				schema: 'LeaveBalance',
				_rbac: false,
				_multitenancy: false
			)
		);

	}//end provision()

	/**
	 * Monthly opbouw onto an already-provisioned balance (design.md D5 step
	 * 4): adds one bovenwettelijk slice, raises `entitledHours`/
	 * `contractHoursPerWeek` only when a mid-year contract-hours increase
	 * pushes `4 x hoursPerWeek` higher than what is already granted (never
	 * lowering — design.md Risks/Trade-offs), and stamps `lastAccruedPeriod`.
	 *
	 * @param array<string, mixed> $existing The existing LeaveBalance.
	 * @param string $period Current wage period (YYYY-MM).
	 * @param float $hoursPerWeek The covering contract's `hoursPerWeek`.
	 * @param float $deltaBovenwettelijk This month's bovenwettelijk slice.
	 * @param string|null $userId The employee's linked Nextcloud account id, or null when unlinked.
	 *
	 * @return array<string, mixed> The saved LeaveBalance.
	 *
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-003
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-004
	 * @spec openspec/changes/hrmq-personal-dashboard/specs/leave-accrual-job/spec.md#REQ-ACCR-006
	 */
	private function accrueExisting(array $existing, string $period, float $hoursPerWeek, float $deltaBovenwettelijk, ?string $userId = null): array {
		$currentEntitled = (float)($existing['entitledHours'] ?? 0);
		$currentContractHours = (float)($existing['contractHoursPerWeek'] ?? 0);
		$currentBovenwettelijk = (float)($existing['bovenwettelijkHours'] ?? 0);

		$payload = array_merge(
			$existing,
			[
				'entitledHours' => max($currentEntitled, (4 * $hoursPerWeek)),
				'bovenwettelijkHours' => ($currentBovenwettelijk + $deltaBovenwettelijk),
				'contractHoursPerWeek' => max($currentContractHours, $hoursPerWeek),
				'lastAccruedPeriod' => $period,
				// REQ-ACCR-006: re-stamped on EVERY monthly slice, not only on
				// create, so a balance written before the property existed
				// self-heals on the next accrual run — no dedicated repair
				// step. Overwrites unconditionally: the Employee row is the
				// authority for the link, so a stale copy is corrected and an
				// unlinked employee is reset to null rather than keeping a
				// value that no longer names their account.
				'userId' => $userId,
			]
		);
		unset($payload['@self']);

		$uuid = $this->idOf($existing);

		return $this->toArray(
			$this->objectService()->saveObject(
				object: $payload,
				register: $this->register(),
				schema: 'LeaveBalance',
				uuid: ($uuid === '' ? null : $uuid),
				_rbac: false,
				_multitenancy: false
			)
		);

	}//end accrueExisting()

	/**
	 * This year's `holiday` LeaveBalances, indexed by employeeId — the
	 * probe-before-write pattern (design.md D5). Assumes at most one
	 * `(employeeId, year, holiday)` row; the first match wins if duplicates
	 * exist.
	 *
	 * @param int $year Calendar year.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function holidayBalancesByEmployeeId(int $year): array {
		$out = [];
		foreach ($this->loadAll('LeaveBalance') as $balance) {
			if ((int)($balance['year'] ?? 0) !== $year || (string)($balance['leaveType'] ?? '') !== self::LEAVE_TYPE) {
				continue;
			}

			$employeeId = (string)($balance['employeeId'] ?? '');
			if ($employeeId !== '' && isset($out[$employeeId]) === false) {
				$out[$employeeId] = $balance;
			}
		}

		return $out;
	}//end holidayBalancesByEmployeeId()

	/**
	 * All EmploymentContracts, indexed by every employee-reference key (the
	 * PayrollRunService convention: contracts may reference the employee by
	 * object id, slug, or employeeNumber). Each key maps to the LIST of that
	 * employee's contracts.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function contractsByEmployeeKey(): array {
		$out = [];
		foreach ($this->loadAll('EmploymentContract') as $contract) {
			$key = trim((string)($contract['employeeId'] ?? ''));
			if ($key !== '') {
				$out[$key][] = $contract;
			}
		}

		return $out;
	}//end contractsByEmployeeKey()

	/**
	 * The employee's contract covering the period, resolved via the id/slug/
	 * employeeNumber keys, or null when none covers it (design.md D4,
	 * identical to `PayrollRunService::coveringContract()`).
	 *
	 * @param array<string, mixed> $employee The Employee.
	 * @param array<string, array<int, array<string, mixed>>> $contractsByEmployeeKey The contract index.
	 * @param string $period Wage period (YYYY-MM).
	 *
	 * @return array<string, mixed>|null
	 */
	private function coveringContract(array $employee, array $contractsByEmployeeKey, string $period): ?array {
		$keys = array_filter(
			[
				$this->idOf($employee),
				(string)($employee['@self']['slug'] ?? ''),
				trim((string)($employee['employeeNumber'] ?? '')),
			],
			static fn (string $key): bool => $key !== ''
		);

		foreach ($keys as $key) {
			foreach (($contractsByEmployeeKey[$key] ?? []) as $contract) {
				if ($this->coversPeriod((string)($contract['startDate'] ?? ''), (string)($contract['endDate'] ?? ''), $period) === true) {
					return $contract;
				}
			}
		}

		return null;
	}//end coveringContract()

	/**
	 * Whether a start/end date range covers the wage period: startDate on or
	 * before the period's last day AND endDate null/blank or on/after the
	 * period's first day (identical to `PayrollRunService::coversPeriod()`).
	 *
	 * @param string $startDate ISO start date.
	 * @param string $endDate ISO end date, or ''/null-ish while open.
	 * @param string $period Wage period (YYYY-MM).
	 *
	 * @return bool
	 */
	private function coversPeriod(string $startDate, string $endDate, string $period): bool {
		try {
			$periodStart = new DateTimeImmutable($period . '-01');
		} catch (\Throwable $e) {
			return false;
		}

		$periodEnd = $periodStart->modify('last day of this month');

		$start = strtotime(trim($startDate));
		if ($start === false || $start > $periodEnd->getTimestamp()) {
			return false;
		}

		$endDate = trim($endDate);
		if ($endDate === '') {
			return true;
		}

		$end = strtotime($endDate);
		return $end === false || $end >= $periodStart->getTimestamp();
	}//end coversPeriod()

	/**
	 * One decimal place rounding for the bovenwettelijk slice (design.md D3)
	 * — keeps the hours figure clean; the residual from integer-twelfths is
	 * immaterial and self-corrects at the next statutory full-grant.
	 *
	 * @param float $value The raw value.
	 *
	 * @return float
	 */
	private function round1(float $value): float {
		return round($value, 1);
	}//end round1()

	/**
	 * A human label for an Employee in the run summary.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 *
	 * @return string
	 */
	private function employeeLabel(array $employee): string {
		$name = trim(trim((string)($employee['firstName'] ?? '')) . ' ' . trim((string)($employee['lastName'] ?? '')));
		if ($name !== '') {
			return $name;
		}

		$number = trim((string)($employee['employeeNumber'] ?? ''));
		if ($number !== '') {
			return $number;
		}

		$id = $this->idOf($employee);
		return $id === '' ? 'onbekend' : $id;
	}//end employeeLabel()

	/**
	 * Build the run summary array.
	 *
	 * @param string $period Wage period (YYYY-MM), '' when the run never started (disabled).
	 * @param int $year Calendar year, 0 when the run never started.
	 * @param bool $enabled Whether `leave_accrual_enabled` was true.
	 * @param array<int, array<string, mixed>> $provisioned Newly-created balances.
	 * @param array<int, array<string, mixed>> $accrued Existing balances that received a bovenwettelijk slice.
	 * @param array<int, array<string, mixed>> $skipped Employees skipped, each with a reason.
	 * @param int $noop Count of balances already accrued this period (no write).
	 *
	 * @return array<string, mixed>
	 */
	private function summary(string $period, int $year, bool $enabled, array $provisioned, array $accrued, array $skipped, int $noop): array {
		return [
			'period' => $period,
			'year' => $year,
			'enabled' => $enabled,
			'provisioned' => $provisioned,
			'accrued' => $accrued,
			'skipped' => $skipped,
			'noop' => $noop,
		];

	}//end summary()

	/**
	 * Load all objects of a schema (capped), as plain arrays (identical to
	 * `PayrollRunService::loadAll()`).
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()->setRegister($this->register())->setSchema($schema)->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('LeaveAccrualJob: kon ' . $schema . ' niet laden: ' . $e->getMessage());
			return [];
		}

		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$out[] = $this->toArray($row);
		}

		return $out;
	}//end loadAll()

	/**
	 * Normalise an ObjectService row (entity or array) to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
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

	/**
	 * The object id of a row, falling back to `@self.id`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? $row['@self']['id'] ?? '');
	}//end idOf()

	/**
	 * Normalise a denormalized-link value: trim, and collapse an empty string
	 * to null so an absent link is stored as null rather than "" (the shape
	 * the @me filter and the fail-closed contract both expect). Mirrors the
	 * identically named helper on TimeEntryStampListener /
	 * TimesheetProcessStampListener — the same convention across every writer
	 * of a denormalized userId.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The trimmed value, or null when empty.
	 *
	 * @spec openspec/changes/hrmq-personal-dashboard/specs/leave-accrual-job/spec.md#REQ-ACCR-006
	 */
	private function nullableTrim(mixed $value): ?string {
		$trimmed = trim((string)($value ?? ''));

		return $trimmed === '' ? null : $trimmed;
	}//end nullableTrim()

	/**
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. Unguarded, an
		// instance without OpenRegister gets a container exception naming a
		// class the admin has never heard of; guarded, it is told which app to
		// install — which is rule 3's promise that the app still explains
		// itself.
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'hrmq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * @return string The configured hrmq register slug.
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()

}//end class
