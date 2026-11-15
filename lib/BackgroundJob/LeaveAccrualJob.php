<?php

/**
 * Leave Accrual Job
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @package  OCA\Humaniq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-001
 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-002
 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-003
 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-004
 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\BackgroundJob;

use DateTimeImmutable;
use OCA\Humaniq\Service\LeaveBalanceProjectionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Support\ConvertsRowsToArrays;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\Humaniq\Support\ResolvesObjectService;
use OCA\Humaniq\Support\LoadsAllRows;
use OCA\Humaniq\Support\ChecksPeriodCoverage;
use OCA\Humaniq\Support\ReadsEmployeeKeys;
use OCA\Humaniq\Support\FindsCoveringContract;

/**
 * Monthly statutory + bovenwettelijk leave accrual onto every active
 * employee's holiday LeaveBalance.
 *
 * @spec openspec/specs/leave-accrual-job/spec.md
 */
class LeaveAccrualJob extends TimedJob {

	use ConvertsRowsToArrays;
	use ResolvesObjectService;
	use LoadsAllRows;
	use ChecksPeriodCoverage;
	use ReadsEmployeeKeys;
	use FindsCoveringContract;

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
	 * The summary of a run while `leave_accrual_enabled` is off: no period,
	 * nothing written.
	 *
	 * @var array<string, mixed>
	 */
	private const DISABLED_SUMMARY = ['period' => '', 'year' => 0, 'enabled' => false, 'provisioned' => [], 'accrued' => [], 'skipped' => [], 'noop' => 0];

	/**
	 * @param ITimeFactory $time Time factory (also drives `run()`'s period/year).
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settingsService Register slug + `leave_accrual_enabled`/`leave_bovenwettelijk_annual_hours` config.
	 * @param LoggerInterface $logger Logger.
	 * @param LeaveBalanceProjectionService $projection Recomputes every balance, applying the lapses due today.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly LeaveBalanceProjectionService $projection,
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
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-001
	 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Statutory-hours-SHALL-lapse-on-their-expiry-date-unless-HR-waives-it-(REQ-LEX-003)
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

		// leave-expiry-and-carry-over REQ-LEX-003: the lapse is recomputed
		// daily from every balance and its requests, whether or not accrual
		// is switched on, so hours lapse on the day after their expiry date.
		$lapsed = $this->projection->recomputeAll(
			(new DateTimeImmutable())->setTimestamp($this->time->getTime())->format('Y-m-d')
		);
		$this->logger->info('LeaveAccrualJob: leave balances recomputed for lapses', ['written' => $lapsed]);

	}//end run()

	/**
	 * Run one accrual pass for the current period, returning a structured
	 * summary. Public (rather than folded into `run()`) so it can be driven
	 * directly by tests and by an `occ` command, mirroring
	 * `PayrollRunService::runFor()`'s outcome-array shape.
	 *
	 * @return array<string, mixed> {period, year, enabled, provisioned, accrued, skipped, noop}
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-001
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-006
	 */
	public function runAccrual(): array {
		if ($this->settingsService->isLeaveAccrualEnabled() === false) {
			return self::DISABLED_SUMMARY;
		}

		$pass = $this->startPass();
		foreach ($this->loadAll('Employee') as $employee) {
			$this->accrueEmployee($employee, $pass);
		}

		return $pass->summary;
	}//end runAccrual()

	/**
	 * Open the pass for the current period: the month being accrued, the
	 * contracts and this year's holiday balances loaded once, and an empty
	 * enabled summary.
	 *
	 * @return LeaveAccrualPass
	 */
	private function startPass(): LeaveAccrualPass {
		$now = (new DateTimeImmutable())->setTimestamp($this->time->getTime());
		$period = $now->format('Y-m');
		$year = (int)$now->format('Y');

		$pass = new LeaveAccrualPass();
		$pass->contractsByEmployeeKey = $this->contractsByEmployeeKey();
		$pass->balancesByEmployeeId = $this->holidayBalancesByEmployeeId($year);
		$pass->month = ['year' => $year, 'period' => $period, 'deltaBovenwettelijk' => $this->round1($this->settingsService->getLeaveBovenwettelijkAnnualHours() / 12)];
		$pass->summary = ['period' => $period, 'year' => $year] + self::DISABLED_SUMMARY;
		$pass->summary['enabled'] = true;

		return $pass;
	}//end startPass()

	/**
	 * Accrue one employee for the pass's month: not employed in the period
	 * is not selected and not reported; otherwise the employee is skipped
	 * with a reason, provisioned, counted as a no-op, or accrued.
	 *
	 * @param array<string, mixed> $employee The Employee row.
	 * @param LeaveAccrualPass $pass The pass being run.
	 *
	 * @return void
	 */
	private function accrueEmployee(array $employee, LeaveAccrualPass $pass): void {
		if ($this->coversPeriod((string)($employee['startDate'] ?? ''), (string)($employee['endDate'] ?? ''), $pass->month['period']) === false) {
			// Not employed in this period — not selected, not reported.
			return;
		}

		$pass->begin($this->employeeLabel($employee));
		$grant = $this->grantFor($employee, $pass);
		if ($grant === null) {
			return;
		}

		if (isset($pass->balancesByEmployeeId[$grant['employeeId']]) === false) {
			$this->provisionBalance($grant, $pass);
			return;
		}

		$this->accrueBalance($grant, $pass);
	}//end accrueEmployee()

	/**
	 * The month's grant for an employee, or null after reporting why the
	 * employee is skipped.
	 *
	 * @param array<string, mixed> $employee The Employee row.
	 * @param LeaveAccrualPass $pass The pass being run.
	 *
	 * @return array<string, mixed>|null
	 */
	private function grantFor(array $employee, LeaveAccrualPass $pass): ?array {
		$employeeId = $this->idOf($employee);
		if ($employeeId === '') {
			$pass->skip('no-employee-identity');
			return null;
		}

		$hoursPerWeek = $this->contractHoursPerWeek($employee, $pass);
		if ($hoursPerWeek === null) {
			return null;
		}

		// humaniq-personal-dashboard REQ-ACCR-006: the denormalized account
		// link, resolved from the SAME Employee row this iteration already
		// selected. Null for an employee with no linked account — never
		// guessed, never another account (fail-closed for @me surfaces).
		return $pass->month + ['employeeId' => $employeeId, 'hoursPerWeek' => $hoursPerWeek, 'userId' => $this->nullableTrim($employee['nextcloudUserId'] ?? null)];
	}//end grantFor()

	/**
	 * The hours per week of the employee's contract covering the pass's
	 * period, or null after reporting the employee as skipped when no
	 * contract covers it or its hours per week are not positive.
	 *
	 * @param array<string, mixed> $employee The Employee row.
	 * @param LeaveAccrualPass $pass The pass being run.
	 *
	 * @return float|null
	 */
	private function contractHoursPerWeek(array $employee, LeaveAccrualPass $pass): ?float {
		$contract = $this->coveringContract($employee, $pass->contractsByEmployeeKey, $pass->month['period']);
		if ($contract === null) {
			$pass->skip('no-covering-contract');
			return null;
		}

		$hoursPerWeek = ($contract['hoursPerWeek'] ?? null);
		if (is_numeric($hoursPerWeek) === false || ((float)$hoursPerWeek) <= 0.0) {
			$pass->skip('no-hours-per-week');
			return null;
		}

		return (float)$hoursPerWeek;
	}//end contractHoursPerWeek()

	/**
	 * Provision the employee's first balance of the year, reporting it as
	 * provisioned, or as `save-failed` when the write fails.
	 *
	 * @param array<string, mixed> $grant The month and the employee.
	 * @param LeaveAccrualPass $pass The pass being run.
	 *
	 * @return void
	 */
	private function provisionBalance(array $grant, LeaveAccrualPass $pass): void {
		try {
			$saved = $this->provision($grant);
		} catch (\Throwable $e) {
			$this->logger->warning('LeaveAccrualJob: kon LeaveBalance niet aanmaken voor ' . $pass->employeeLabel() . ': ' . $e->getMessage());
			$pass->skip('save-failed');
			return;
		}

		$pass->written('provisioned', $this->idOf($saved));
	}//end provisionBalance()

	/**
	 * Accrue the month onto the employee's existing balance, reporting it as
	 * accrued, as a no-op when this month was already accrued, or as
	 * `save-failed` when the write fails.
	 *
	 * @param array<string, mixed> $grant The month and the employee.
	 * @param LeaveAccrualPass $pass The pass being run.
	 *
	 * @return void
	 */
	private function accrueBalance(array $grant, LeaveAccrualPass $pass): void {
		$existing = $pass->balancesByEmployeeId[$grant['employeeId']];
		if ($this->alreadyAccrued($existing, $grant) === true) {
			// Already accrued this employee-month — a hard no-op, no write
			// (design.md D5, REQ-ACCR-004): correctness must not depend on
			// how often the TimedJob fires.
			$pass->noop();
			return;
		}

		try {
			$saved = $this->accrueExisting($existing, $grant);
		} catch (\Throwable $e) {
			$this->logger->warning('LeaveAccrualJob: kon LeaveBalance niet bijwerken voor ' . $pass->employeeLabel() . ': ' . $e->getMessage());
			$pass->skip('save-failed');
			return;
		}

		$pass->written('accrued', $this->idOf($saved));
	}//end accrueBalance()

	/**
	 * Whether the existing balance was already accrued for the grant's period
	 * (its `lastAccruedPeriod` idempotency marker).
	 *
	 * @param array<string, mixed> $existing The existing LeaveBalance.
	 * @param array<string, mixed> $grant The month and the employee.
	 *
	 * @return bool
	 */
	private function alreadyAccrued(array $existing, array $grant): bool {
		return (string)($existing['lastAccruedPeriod'] ?? '') === $grant['period'];
	}//end alreadyAccrued()

	/**
	 * First-accrual provisioning (design.md D2/D5): the full annual statutory
	 * figure, one bovenwettelijk slice, the vervaltermijn, and the
	 * idempotency marker — never a monthly statutory slice.
	 *
	 * @param array<string, mixed> $grant The month (`year`, `period` stamped as `lastAccruedPeriod`, `deltaBovenwettelijk`) and the
	 *                                    employee (`employeeId`, the covering contract's `hoursPerWeek`, the linked account `userId` or null).
	 *
	 * @return array<string, mixed> The saved LeaveBalance.
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-002
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-006
	 */
	private function provision(array $grant): array {
		$payload = [
			'employeeId' => $grant['employeeId'],
			'year' => $grant['year'],
			'leaveType' => self::LEAVE_TYPE,
			'entitledHours' => (4 * $grant['hoursPerWeek']),
			'bovenwettelijkHours' => $grant['deltaBovenwettelijk'],
			'usedHours' => 0.0,
			'contractHoursPerWeek' => $grant['hoursPerWeek'],
			'expiryDate' => sprintf('%04d-07-01', ($grant['year'] + 1)),
			'lastAccruedPeriod' => $grant['period'],
			// REQ-ACCR-006 / REQ-MHS-002: denormalized copy of the linked
			// Employee's nextcloudUserId, so the personal dashboard's
			// verlofsaldo widget can filter with the @me token. Null when the
			// employee has no account — the row then never appears on a Mijn
			// surface rather than being mis-attributed.
			'userId' => $grant['userId'],
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
	 * @param array<string, mixed> $grant The month (`period`, `deltaBovenwettelijk`) and the employee (`hoursPerWeek`, `userId`).
	 *
	 * @return array<string, mixed> The saved LeaveBalance.
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-003
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-004
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-006
	 */
	private function accrueExisting(array $existing, array $grant): array {
		$currentEntitled = (float)($existing['entitledHours'] ?? 0);
		$currentContractHours = (float)($existing['contractHoursPerWeek'] ?? 0);
		$currentBovenwettelijk = (float)($existing['bovenwettelijkHours'] ?? 0);

		$payload = array_merge(
			$existing,
			[
				'entitledHours' => max($currentEntitled, (4 * $grant['hoursPerWeek'])),
				'bovenwettelijkHours' => ($currentBovenwettelijk + $grant['deltaBovenwettelijk']),
				'contractHoursPerWeek' => max($currentContractHours, $grant['hoursPerWeek']),
				'lastAccruedPeriod' => $grant['period'],
				// REQ-ACCR-006: re-stamped on EVERY monthly slice, not only on
				// create, so a balance written before the property existed
				// self-heals on the next accrual run — no dedicated repair
				// step. Overwrites unconditionally: the Employee row is the
				// authority for the link, so a stale copy is corrected and an
				// unlinked employee is reset to null rather than keeping a
				// value that no longer names their account.
				'userId' => $grant['userId'],
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
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-006
	 */
	private function nullableTrim(mixed $value): ?string {
		$trimmed = trim((string)($value ?? ''));

		return $trimmed === '' ? null : $trimmed;
	}//end nullableTrim()

	/**
	 * @return string The configured humaniq register slug.
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()
}//end class
