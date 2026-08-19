<?php

/**
 * Analytics Service
 *
 * Backs the Dashboard's three `endpointSource` trend widgets' metrics
 * (`absence-rate` / `payroll-cost` / `approval-lead-time`,
 * hrmq-dashboard-steering-indicators): every method here is called ONLY
 * after `AnalyticsController` has resolved and authorized the caller's
 * active administration (REQ-DSI-005) — this class trusts the
 * `$administrationId` it is given and never resolves one of its own. The
 * Obligations list is a DIFFERENT job (a cross-schema merge, not a
 * time-bucketed metric) and lives in {@see ObligationsService} — the
 * `AbsenceProgression`/`AssetDialectMapper` split precedent, applied here
 * because one class doing both tripped phpmd's class-complexity threshold.
 *
 * THE TWO REFUSALS THIS CLASS CARRIES FORWARD
 * --------------------------------------------
 * 1. **A period bucket with no underlying data returns `null`, never `0`**
 *    (REQ-DSI-004/006/007) — the `AbsenceRateService::percentage` precedent,
 *    applied to every trend this class computes, not only the one it wraps.
 *    A `€0` payroll-cost bucket or a `0`-day lead time both read as "a
 *    measurement that ran and found nothing to report", which is not the
 *    same claim as "no PayrollRun/approval exists for this period" — so an
 *    empty bucket is tracked as absent, not summed to zero.
 * 2. **Approval lead time is a median + p90, never a mean** (REQ-DSI-007,
 *    orchestrator-revised 2026-08-19). The durations are already an array
 *    in PHP by the time this class touches them, so a percentile is a sort
 *    and an index — the "no MEDIAN aggregation metric" constraint that
 *    justifies a mean only binds OpenRegister's own `dataSource` aggregation
 *    path, which this `endpointSource`-bound metric does not use.
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
 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Server-side aggregation for the Dashboard's guarded trend endpoint.
 */
class AnalyticsService {

	/**
	 * Trend metric identifiers `GET /api/analytics/trends?metric=` accepts.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_TREND_METRICS = [
		'absence-rate',
		'payroll-cost',
		'approval-lead-time',
		'billable-ratio',
		'headcount',
	];

	/**
	 * Trailing-window period selectors, mirroring pipelinq's
	 * `AnalyticsService::ALLOWED_PERIODS` shape (a window name, not a single
	 * bucket): each resolves to a number of trailing CALENDAR-MONTH buckets
	 * ending at the current month, since every metric here is bucketed
	 * monthly (`PayrollRun.period`/`Timesheet.period`'s own `YYYY-MM` grain).
	 *
	 * @var array<string, int>
	 */
	private const PERIOD_MONTHS = ['quarter' => 3, 'half-year' => 6, 'year' => 12];

	/**
	 * Default period window when the caller supplies none.
	 *
	 * @var string
	 */
	public const DEFAULT_PERIOD = 'year';

	/**
	 * `PayrollRun.status` values whose totals are finalised and therefore
	 * countable (REQ-DSI-006) — `draft` is deliberately excluded.
	 *
	 * @var array<int, string>
	 */
	private const PAYROLL_FINALISED_STATUSES = ['approved', 'posted', 'paid'];

	/**
	 * Schemas whose `(submittedAt, approvedAt)` pair feeds the pooled
	 * approval-lead-time population (REQ-DSI-007).
	 *
	 * @var array<int, string>
	 */
	private const APPROVAL_LEAD_TIME_SCHEMAS = ['Timesheet', 'Expense', 'LeaveRequest'];

	/**
	 * Max objects loaded per schema — the `RuleAuditService::loadAll()` /
	 * `AdministrationService::loadAll()` `findAll(['limit' => N])`-then-
	 * filter-in-PHP convention.
	 *
	 * @var int
	 */
	private const LOAD_LIMIT = 10000;

	/**
	 * @param ContainerInterface $container DI container for the ObjectService resolve.
	 * @param SettingsService $settingsService The register-slug source.
	 * @param AbsenceRateService $absenceRateService The FTE-weighted verzuimpercentage calculator (absence-rate, landed on this branch).
	 * @param Percentile $percentile The median/p90 calculator (injected, never called statically — the phpmd StaticAccess fix).
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly AbsenceRateService $absenceRateService,
		private readonly Percentile $percentile,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Time-series data for the Dashboard's three `endpointSource` trend
	 * charts.
	 *
	 * @param string $metric One of ALLOWED_TREND_METRICS.
	 * @param string $period One of PERIOD_MONTHS' keys.
	 * @param string $administrationId The caller's ALREADY-AUTHORIZED active administration (REQ-DSI-005) — never resolved here.
	 *
	 * @return array{metric: string, period: string, series: array<int, array<string, mixed>>}
	 *
	 * @throws InvalidArgumentException When metric or period is not recognised.
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-004
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-006
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-007
	 */
	public function getTrends(string $metric, string $period, string $administrationId): array {
		if (in_array($metric, self::ALLOWED_TREND_METRICS, true) === false) {
			throw new InvalidArgumentException('Unsupported metric');
		}

		if (isset(self::PERIOD_MONTHS[$period]) === false) {
			throw new InvalidArgumentException('Invalid period');
		}

		$periodKeys = $this->trailingPeriodKeys(self::PERIOD_MONTHS[$period]);

		$series = match ($metric) {
			'absence-rate' => $this->absenceRateSeries($periodKeys, $administrationId),
			'payroll-cost' => $this->payrollCostSeries($periodKeys, $administrationId),
			'approval-lead-time' => $this->approvalLeadTimeSeries($periodKeys, $administrationId),
			'billable-ratio' => $this->billableRatioSeries($periodKeys, $administrationId),
			'headcount' => $this->headcountSeries($periodKeys, $administrationId),
		};

		return ['metric' => $metric, 'period' => $period, 'series' => $series];
	}//end getTrends()

	/**
	 * Absence-rate series (REQ-DSI-004): `AbsenceRateService::absenceRate()`
	 * called once per bucketed period, carrying its `percentage` through
	 * unmodified — `null` stays `null`.
	 *
	 * @param array<int, string> $periodKeys `YYYY-MM` buckets, oldest first.
	 * @param string $administrationId The caller's active administration.
	 *
	 * @return array<int, array{date: string, value: float|null}>
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function absenceRateSeries(array $periodKeys, string $administrationId): array {
		$cases = $this->loadFiltered('SickLeaveCase', $administrationId);
		$contracts = $this->loadFiltered('EmploymentContract', $administrationId);

		$series = [];
		foreach ($periodKeys as $period) {
			[$start, $end] = $this->periodBounds($period);
			$result = $this->absenceRateService->absenceRate($cases, $contracts, $start, $end);
			$series[] = ['date' => $period, 'value' => $result['percentage']];
		}

		return $series;
	}//end absenceRateSeries()

	/**
	 * Payroll-cost series (REQ-DSI-006): `totalGross + totalEmployerCharges`
	 * summed per period over finalised (`approved`/`posted`/`paid`) runs —
	 * `draft` runs are excluded. A period with no finalised run at all
	 * returns `null`, not `0` — the same "no measurement ran" refusal the
	 * absence-rate metric already carries, applied here so an unbilled
	 * period does not read as a good (zero-cost) one.
	 *
	 * @param array<int, string> $periodKeys `YYYY-MM` buckets, oldest first.
	 * @param string $administrationId The caller's active administration.
	 *
	 * @return array<int, array{date: string, value: float|null}>
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function payrollCostSeries(array $periodKeys, string $administrationId): array {
		$sums = array_fill_keys($periodKeys, null);

		foreach ($this->loadFiltered('PayrollRun', $administrationId) as $run) {
			$period = (string)($run['period'] ?? '');
			if (array_key_exists($period, $sums) === false) {
				continue;
			}

			if (in_array((string)($run['status'] ?? ''), self::PAYROLL_FINALISED_STATUSES, true) === false) {
				continue;
			}

			$amount = ((float)($run['totalGross'] ?? 0) + (float)($run['totalEmployerCharges'] ?? 0));
			$sums[$period] = (($sums[$period] ?? 0.0) + $amount);
		}

		$series = [];
		foreach ($periodKeys as $period) {
			$series[] = ['date' => $period, 'value' => $sums[$period] === null ? null : round($sums[$period], 2)];
		}

		return $series;
	}//end payrollCostSeries()

	/**
	 * Billable-ratio series (REQ-DSI-002): billable hours as a percentage of
	 * ALL registered hours, per `Timesheet.period` bucket.
	 *
	 * Server-side and register-scoped on purpose. The first cut of this
	 * widget bound to a raw-GraphQL `dataSource` — `{ Timesheet(filter:
	 * {billable: true}, groupBy: …) }` — which resolves a type by NAME
	 * across every register on the instance. Measured live: the sibling
	 * Headcount widget's `Employee` root field resolved to schema 5050 in
	 * an unrelated register (the GraphQL type is literally named
	 * `Employee5050Connection`), not hrmq's Employee (1080), so
	 * `startDate` came back "not a declared property" while
	 * `employeeNumber` returned three rows from a register this app does
	 * not own. A query that answers 200 with somebody else's data is worse
	 * than one that fails. `loadFiltered()` scopes by register AND by the
	 * caller's authorized administration, which the raw-GraphQL form also
	 * could not do (`useDataSource` passes `graphql.query` through without
	 * resolving `@workspace.*` tokens).
	 *
	 * A bucket with no registered hours at all yields `null`, never `0.0`:
	 * "nobody logged any hours" is an absent measurement, and a 0% billable
	 * ratio is a catastrophic reading — the two must not look alike.
	 *
	 * @param array<int, string> $periodKeys `YYYY-MM` buckets, oldest first.
	 * @param string $administrationId The caller's active administration.
	 *
	 * @return array<int, array{date: string, value: float|null}>
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-002
	 */
	private function billableRatioSeries(array $periodKeys, string $administrationId): array {
		$billable = array_fill_keys($periodKeys, 0.0);
		$total = array_fill_keys($periodKeys, 0.0);

		foreach ($this->loadFiltered('Timesheet', $administrationId) as $row) {
			$period = (string)($row['period'] ?? '');
			if (array_key_exists($period, $total) === false) {
				continue;
			}

			$hours = (float)($row['hours'] ?? 0);
			$total[$period] += $hours;
			if (($row['billable'] ?? false) === true) {
				$billable[$period] += $hours;
			}
		}

		$series = [];
		foreach ($periodKeys as $period) {
			$series[] = [
				'date' => $period,
				'value' => $total[$period] <= 0.0 ? null : round((($billable[$period] / $total[$period]) * 100), 1),
			];
		}

		return $series;
	}//end billableRatioSeries()

	/**
	 * Headcount-and-turnover series (REQ-DSI-003): active headcount at each
	 * period's END, plus the starters and leavers within that period.
	 *
	 * Register-scoped and administration-scoped for the same reason
	 * `billableRatioSeries()` is — see that method's docblock for the
	 * measured cross-register resolution defect this replaces.
	 *
	 * An `Employee` with no parseable `startDate` cannot be placed on the
	 * timeline at all, so it is excluded from every bucket rather than
	 * assumed to have started at some convenient moment — the same refusal
	 * `AbsenceRateService` makes for an absence with no covering contract.
	 * The count is logged rather than silently dropped, because an
	 * administration where many employees lack a start date produces a
	 * headcount line that is real-looking and wrong.
	 *
	 * @param array<int, string> $periodKeys `YYYY-MM` buckets, oldest first.
	 * @param string $administrationId The caller's active administration.
	 *
	 * @return array<int, array{date: string, headcount: int, starters: int, leavers: int}>
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-003
	 */
	private function headcountSeries(array $periodKeys, string $administrationId): array {
		$placeable = [];
		$excluded = 0;
		foreach ($this->loadFiltered('Employee', $administrationId) as $row) {
			$start = $this->parseDate($row['startDate'] ?? null);
			if ($start === null) {
				$excluded++;
				continue;
			}

			$placeable[] = ['start' => $start, 'end' => $this->parseDate($row['endDate'] ?? null)];
		}

		if ($excluded > 0) {
			$this->logger->warning(
				'AnalyticsService: ' . $excluded . ' employee(s) excluded from the headcount series — no parseable startDate'
			);
		}

		$series = [];
		foreach ($periodKeys as $period) {
			[$start, $end] = $this->periodBounds($period);
			$headcount = 0;
			$starters = 0;
			$leavers = 0;
			foreach ($placeable as $employee) {
				if ($employee['start'] <= $end && ($employee['end'] === null || $employee['end'] > $end)) {
					$headcount++;
				}

				if ($employee['start'] >= $start && $employee['start'] <= $end) {
					$starters++;
				}

				if ($employee['end'] !== null && $employee['end'] >= $start && $employee['end'] <= $end) {
					$leavers++;
				}
			}

			$series[] = [
				'date' => $period,
				'headcount' => $headcount,
				'starters' => $starters,
				'leavers' => $leavers,
			];
		}

		return $series;
	}//end headcountSeries()

	/**
	 * Approval-lead-time series (REQ-DSI-007): `Timesheet`/`Expense`/
	 * `LeaveRequest` records pooled into ONE population per bucket (keyed
	 * by `approvedAt`'s period), each bucket reduced to a median (p50) and
	 * a p90 — never a mean. Records with a null `submittedAt` or
	 * `approvedAt` are excluded entirely, never treated as a zero-day lead
	 * time. An empty bucket yields `{median: null, p90: null}`.
	 *
	 * @param array<int, string> $periodKeys `YYYY-MM` buckets, oldest first.
	 * @param string $administrationId The caller's active administration.
	 *
	 * @return array<int, array{date: string, median: float|null, p90: float|null}>
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function approvalLeadTimeSeries(array $periodKeys, string $administrationId): array {
		$durationsByPeriod = array_fill_keys($periodKeys, []);

		foreach (self::APPROVAL_LEAD_TIME_SCHEMAS as $schema) {
			foreach ($this->loadFiltered($schema, $administrationId) as $record) {
				$this->collectApprovalDuration($record, $durationsByPeriod);
			}
		}

		$series = [];
		foreach ($periodKeys as $period) {
			$durations = $durationsByPeriod[$period];
			sort($durations);
			$series[] = [
				'date' => $period,
				'median' => $this->percentile->value($durations, 50.0),
				'p90' => $this->percentile->value($durations, 90.0),
			];
		}

		return $series;
	}//end approvalLeadTimeSeries()

	/**
	 * Add one record's approval-lead-time duration (in days) to its
	 * `approvedAt` period's bucket, when both dates are present and the
	 * period is one of the requested buckets. Mutates `$durationsByPeriod`
	 * in place — the per-record half of `approvalLeadTimeSeries()`'s loop,
	 * split out so that loop stays a single pass with no nested branching.
	 *
	 * @param array<string, mixed> $record A Timesheet/Expense/LeaveRequest row.
	 * @param array<string, array<int, float>> $durationsByPeriod Bucket => duration list, keyed by the REQUESTED periods only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function collectApprovalDuration(array $record, array &$durationsByPeriod): void {
		$approvedAt = $this->parseDate($record['approvedAt'] ?? null);
		$submittedAt = $this->parseDate($record['submittedAt'] ?? null);
		if ($approvedAt === null || $submittedAt === null) {
			return;
		}

		$period = $approvedAt->format('Y-m');
		if (array_key_exists($period, $durationsByPeriod) === false) {
			return;
		}

		$durationsByPeriod[$period][] = (float)$approvedAt->diff($submittedAt)->days;
	}//end collectApprovalDuration()

	/**
	 * The trailing `$months` calendar-month `YYYY-MM` buckets ending at (and
	 * including) the current month, oldest first.
	 *
	 * @param int $months Number of trailing monthly buckets.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function trailingPeriodKeys(int $months): array {
		$keys = [];
		$currentMonth = new DateTimeImmutable('first day of this month');
		for ($offset = ($months - 1); $offset >= 0; $offset--) {
			$keys[] = $currentMonth->modify(sprintf('-%d months', $offset))->format('Y-m');
		}

		return $keys;
	}//end trailingPeriodKeys()

	/**
	 * First/last day of a `YYYY-MM` period, both at midnight. Built via the
	 * constructor (`new DateTimeImmutable(...)`), not the static
	 * `createFromFormat()` factory — PHP parses a bare `YYYY-MM-01` string
	 * natively, and the constructor form is not a phpmd `StaticAccess` call.
	 *
	 * @param string $period `YYYY-MM`.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function periodBounds(string $period): array {
		$start = new DateTimeImmutable($period . '-01');
		return [$start, $start->modify('last day of this month')];
	}//end periodBounds()

	/**
	 * Parse a stored date/date-time value.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return DateTimeImmutable|null Null when absent, blank, or unparseable.
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function parseDate(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception) {
			return null;
		}
	}//end parseDate()

	/**
	 * Load all objects of a schema, filtered to those denormalized-scoped
	 * to `$administrationId` — a row with a null/blank/mismatched
	 * `administrationId` is excluded, the same implicit page-scoping rule
	 * REQ-MULTI-004 already applies everywhere else.
	 *
	 * @param string $schema The schema name.
	 * @param string $administrationId The caller's active administration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function loadFiltered(string $schema, string $administrationId): array {
		$rows = [];
		foreach ($this->loadAll($schema) as $row) {
			if ((string)($row['administrationId'] ?? '') === $administrationId) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end loadFiltered()

	/**
	 * Load all objects of a schema (capped), as plain arrays — the
	 * `RuleAuditService::loadAll()` precedent.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()
				->setRegister($this->settingsService->getRegisterSlug())
				->setSchema($schema)
				->findAll(['limit' => self::LOAD_LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('AnalyticsService: could not load ' . $schema . ': ' . $e->getMessage());
			return [];
		}

		return $this->normaliseRows($rows);
	}//end loadAll()

	/**
	 * Normalise a list of ObjectService rows (entities or arrays) to arrays.
	 *
	 * @param mixed $rows Raw rows.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function normaliseRows(mixed $rows): array {
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
	}//end normaliseRows()

	/**
	 * The OpenRegister ObjectService, once availability has been
	 * established (ADR-083 — the AdministrationService precedent).
	 *
	 * @return mixed The OpenRegister ObjectService.
	 *
	 * @throws \RuntimeException When OpenRegister is not installed.
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function objectService(): mixed {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'hrmq requires the OpenRegister app, which is not installed on this instance. '
				. 'Install and enable it, then reload.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

}//end class
