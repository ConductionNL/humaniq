<?php

/**
 * Analytics Service
 *
 * Backs the Dashboard's three `endpointSource` trend widgets' metrics
 * (`absence-rate` / `payroll-cost` / `approval-lead-time`,
 * humaniq-dashboard-steering-indicators): every method here is called ONLY
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
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Server-side aggregation for the Dashboard's guarded trend endpoint.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
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
		'absence-frequency',
	];

	/**
	 * The schemas each metric reads (department-figures: loaded once, then
	 * restricted per period to a unit's people).
	 *
	 * @var array<string, array<int, string>>
	 */
	private const METRIC_SCHEMAS = [
		'absence-rate' => ['SickLeaveCase', 'EmploymentContract'],
		'absence-frequency' => ['SickLeaveCase', 'EmploymentContract'],
		'payroll-cost' => ['PayrollRun'],
		'approval-lead-time' => self::APPROVAL_LEAD_TIME_SCHEMAS,
		'billable-ratio' => ['Timesheet'],
		'headcount' => ['Employee'],
	];

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
	 * @param EmployeeTimeline $timeline Places employees on a timeline and counts them per period (the AbsenceProgression split precedent — see that class for why).
	 * @param UnitTrends $unitTrends department-figures: a metric for one org unit's people, and the absence frequency.
	 * @param TrendPeriods $periods The trailing month windows and date parsing (split out for phpmd's class-complexity ceiling).
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly AbsenceRateService $absenceRateService,
		private readonly Percentile $percentile,
		private readonly LoggerInterface $logger,
		private readonly EmployeeTimeline $timeline = new EmployeeTimeline(),
		private readonly UnitTrends $unitTrends = new UnitTrends(),
		private readonly TrendPeriods $periods = new TrendPeriods(),
	) {

	}//end __construct()

	/**
	 * Time-series data for the Dashboard's three `endpointSource` trend
	 * charts.
	 *
	 * @param string $metric One of ALLOWED_TREND_METRICS.
	 * @param string $period One of TrendPeriods::PERIOD_MONTHS' keys.
	 * @param string $administrationId The caller's ALREADY-AUTHORIZED active administration (REQ-DSI-005) — never resolved here.
	 * @param string|null $orgUnitId department-figures: restrict to the people placed in this unit and its children.
	 * @param int $minimumMembers department-figures: withhold a period with fewer people than this (0 for none).
	 *
	 * @return array{metric: string, period: string, series: array<int, array<string, mixed>>}
	 *
	 * @throws InvalidArgumentException When metric or period is not recognised.
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-004
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-006
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-007
	 */
	public function getTrends(
		string $metric,
		string $period,
		string $administrationId,
		?string $orgUnitId=null,
		int $minimumMembers=0,
	): array {
		if (in_array($metric, self::ALLOWED_TREND_METRICS, true) === false) {
			throw new InvalidArgumentException('Unsupported metric');
		}

		$periodKeys = $this->periods->keys($period);
		$rows = [];
		foreach (self::METRIC_SCHEMAS[$metric] as $schema) {
			$rows[$schema] = $this->loadFiltered($schema, $administrationId);
		}

		if ($orgUnitId === null || $orgUnitId === '') {
			return ['metric' => $metric, 'period' => $period, 'series' => $this->seriesFor($metric, $periodKeys, $rows, null)];
		}

		$series = $this->unitTrends->series(
			unit: ['id' => $orgUnitId, 'minimumMembers' => $minimumMembers, 'metric' => $metric],
			periodKeys: $periodKeys,
			rows: $rows,
			load: fn (string $schema): array => $this->loadFiltered($schema, $administrationId),
			seriesFor: fn (array $keys, array $unitRows, array $shares): array => $this->seriesFor($metric, $keys, $unitRows, $shares)
		);

		return ['metric' => $metric, 'period' => $period, 'series' => $series];
	}//end getTrends()

	/**
	 * The rows of a schema in the caller's (already authorised)
	 * administration.
	 *
	 * @param string $schema           The schema name.
	 * @param string $administrationId The authorised administration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	public function rowsFor(string $schema, string $administrationId): array {
		return $this->loadFiltered($schema, $administrationId);
	}//end rowsFor()

	/**
	 * One metric's series over rows already loaded. With `$shares` the rows
	 * are one unit's people for one period ({@see UnitTrends}).
	 *
	 * @param string                                          $metric     The metric.
	 * @param array<int, string>                              $periodKeys `YYYY-MM` buckets.
	 * @param array<string, array<int, array<string, mixed>>> $rows       Rows per schema.
	 * @param array<string, float>|null                       $shares     A unit's people, or null for the administration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	private function seriesFor(string $metric, array $periodKeys, array $rows, ?array $shares): array {
		return match ($metric) {
			'absence-rate' => $this->absenceRateSeries($periodKeys, $rows['SickLeaveCase'], $rows['EmploymentContract']),
			'absence-frequency' => $this->unitTrends->frequencySeries($periodKeys, $rows['SickLeaveCase'], $rows['EmploymentContract'], $shares),
			'payroll-cost' => $this->payrollCostSeries($periodKeys, $rows['PayrollRun']),
			'approval-lead-time' => $this->approvalLeadTimeSeries($periodKeys, $rows),
			'billable-ratio' => $this->billableRatioSeries($periodKeys, $rows['Timesheet']),
			default => $this->headcountSeries($periodKeys, $rows['Employee']),
		};
	}//end seriesFor()

	/**
	 * Absence-rate series (REQ-DSI-004): `AbsenceRateService::absenceRate()`
	 * called once per bucketed period, carrying its `percentage` through
	 * unmodified — `null` stays `null`.
	 *
	 * @param array<int, string> $periodKeys `YYYY-MM` buckets, oldest first.
	 * @param array<int, array<string, mixed>> $cases SickLeaveCase rows of the administration (or of a unit's people).
	 * @param array<int, array<string, mixed>> $contracts EmploymentContract rows, likewise.
	 *
	 * @return array<int, array{date: string, value: float|null}>
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function absenceRateSeries(array $periodKeys, array $cases, array $contracts): array {

		$series = [];
		foreach ($periodKeys as $period) {
			[$start, $end] = $this->periods->bounds($period);
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
	 * @param array<int, array<string, mixed>> $runs PayrollRun rows of the administration.
	 *
	 * @return array<int, array{date: string, value: float|null}>
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function payrollCostSeries(array $periodKeys, array $runs): array {
		$sums = array_fill_keys($periodKeys, null);

		foreach ($runs as $run) {
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
	 * `Employee5050Connection`), not humaniq's Employee (1080), so
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
	 * @param array<int, array<string, mixed>> $timesheets Timesheet rows of the administration (or of a unit's people).
	 *
	 * @return array<int, array{date: string, value: float|null}>
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-002
	 */
	private function billableRatioSeries(array $periodKeys, array $timesheets): array {
		$billable = array_fill_keys($periodKeys, 0.0);
		$total = array_fill_keys($periodKeys, 0.0);

		foreach ($timesheets as $row) {
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
	 * @param array<int, array<string, mixed>> $employees Employee rows of the administration (or of a unit's people).
	 *
	 * @return array<int, array{date: string, headcount: int, starters: int, leavers: int}>
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-003
	 */
	private function headcountSeries(array $periodKeys, array $employees): array {
		$timeline = $this->timeline->place($employees);

		if ($timeline['excluded'] > 0) {
			$this->logger->warning(
				'AnalyticsService: ' . $timeline['excluded'] . ' employee(s) excluded from the headcount series — no parseable startDate'
			);
		}

		$series = [];
		foreach ($periodKeys as $period) {
			[$start, $end] = $this->periods->bounds($period);
			$series[] = (['date' => $period] + $this->timeline->countOver($timeline['placed'], $start, $end));
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
	 * @param array<string, array<int, array<string, mixed>>> $rows Timesheet/Expense/LeaveRequest rows per schema.
	 *
	 * @return array<int, array{date: string, median: float|null, p90: float|null}>
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function approvalLeadTimeSeries(array $periodKeys, array $rows): array {
		$durationsByPeriod = array_fill_keys($periodKeys, []);

		foreach (self::APPROVAL_LEAD_TIME_SCHEMAS as $schema) {
			foreach (($rows[$schema] ?? []) as $record) {
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
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function collectApprovalDuration(array $record, array &$durationsByPeriod): void {
		$approvedAt = $this->periods->parseDate($record['approvedAt'] ?? null);
		$submittedAt = $this->periods->parseDate($record['submittedAt'] ?? null);
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
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
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
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function loadAll(string $schema): array {
		try {
			// Read past the per-field strip (compliance-roles-and-field-access
			// D6): a team leader is authorised for the unit's figures by
			// AnalyticsAccess and is shown aggregates only, which the payslip
			// amounts they may not read individually still have to feed. Rows
			// are unaffected: no humaniq schema declares object-level
			// authorization.
			$rows = $this->objectService()
				->setRegister($this->settingsService->getRegisterSlug())
				->setSchema($schema)
				->findAll(['limit' => self::LOAD_LIMIT], false);
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
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
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
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function objectService(): mixed {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance. '
				. 'Install and enable it, then reload.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

}//end class
