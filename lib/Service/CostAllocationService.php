<?php

/**
 * Cost Allocation Service
 *
 * Splits each payslip's wage costs over cost centres and projects
 * (payroll-cost-allocation D2, D3): by the employee's fixed split, by the
 * approved hours booked, by the department they are placed in, or as
 * unallocated when nothing resolves. The lines add up to the payslip to the
 * cent and are written as WageCostAllocation objects, which the payroll
 * journal and the wage costs page read.
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
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Resolves and writes the wage cost allocation of a payroll run.
 *
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
 */
class CostAllocationService {

	/**
	 * The schema of the allocation lines.
	 *
	 * @var string
	 */
	private const LINE_SCHEMA = 'WageCostAllocation';

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface $gateway       The register plumbing.
	 * @param OrgResolutionService $orgResolution The placement date rule.
	 * @param LoggerInterface      $logger        The logger.
	 * @param CostAllocationShares $shares        The share arithmetic.
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
		private readonly OrgResolutionService $orgResolution,
		private readonly LoggerInterface $logger,
		private readonly CostAllocationShares $shares = new CostAllocationShares(),
	) {

	}//end __construct()

	/**
	 * Everything the split of one period reads, loaded once per run.
	 *
	 * @param string $period The wage period, YYYY-MM.
	 *
	 * @return array{allocations: list<array<string, mixed>>, assignments: list<array<string, mixed>>, unitsById: array<string, array<string, mixed>>, hoursByEmployee: array<string, list<array<string, mixed>>>}
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
	 */
	public function inputs(string $period): array {
		$allocations = $this->gateway->loadAll('CostAllocation');

		$unitsById = [];
		foreach ($this->gateway->loadAll('OrgUnit') as $unit) {
			$unitsById[(string)($unit['id'] ?? '')] = $unit;
		}

		return [
			'allocations' => $allocations,
			'assignments' => $this->gateway->loadAll('OrgAssignment'),
			'unitsById' => $unitsById,
			'hoursByEmployee' => $this->approvedHours(period: $period, allocations: $allocations),
		];
	}//end inputs()

	/**
	 * The shares of one employee's cost in a period, in the order of design
	 * D2: fixed split, hours, placement, unallocated.
	 *
	 * @param string            $employeeId The employee.
	 * @param CostAllocationRun $run        The run: its wage period (YYYY-MM) and the inputs from inputs().
	 *
	 * @return list<array{costCenter: string|null, projectId: string|null, percentage: float, allocationSource: string}>
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
	 */
	public function splitFor(string $employeeId, CostAllocationRun $run): array {
		$split = new CostAllocationSplit();
		$split->employeeId = $employeeId;
		$split->inputs = $run->inputs;
		$bounds = $this->periodBounds($run->period);
		$split->from = $bounds[0];
		$split->until = $bounds[1];
		$split->allocation = $this->coveringAllocation(split: $split);
		$split->placement = $this->placementCostCenters(split: $split);

		return ($this->fixedShares(split: $split) ?? $this->hoursShares(split: $split) ?? $this->placementShares($split->placement));
	}//end splitFor()

	/**
	 * The fixed split of a covering fixed allocation, or null when there is
	 * none or it splits nothing.
	 *
	 * @param CostAllocationSplit $split The split: its covering allocation.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	private function fixedShares(CostAllocationSplit $split): ?array {
		$allocation = $split->allocation;
		if ($allocation === null || ($allocation['basis'] ?? 'fixed') !== 'fixed') {
			return null;
		}

		$shares = $this->shares->fixedShares($allocation);
		return ($shares === [] ? null : $shares);
	}//end fixedShares()

	/**
	 * The split by the period's approved hours of a covering hours
	 * allocation, or null when there is none or no hours count.
	 *
	 * @param CostAllocationSplit $split The split: its employee, inputs, covering allocation and placements.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	private function hoursShares(CostAllocationSplit $split): ?array {
		if ($split->allocation === null || ($split->allocation['basis'] ?? '') !== 'hours') {
			return null;
		}

		$shares = $this->shares->hoursShares(entries: ($split->inputs['hoursByEmployee'][$split->employeeId] ?? []), fallbackCostCenter: self::soleCostCenter(split: $split));
		return ($shares === [] ? null : $shares);
	}//end hoursShares()

	/**
	 * The placements' cost centre when there is exactly one, else null.
	 *
	 * @param CostAllocationSplit $split The split: its placements' cost centres.
	 *
	 * @return string|null
	 */
	private static function soleCostCenter(CostAllocationSplit $split): ?string {
		return (count($split->placement) === 1 ? $split->placement[0] : null);
	}//end soleCostCenter()

	/**
	 * The shares of the placements' cost centres, split equally; one
	 * unallocated share when there is none.
	 *
	 * @param list<string> $placement The distinct cost centres.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function placementShares(array $placement): array {
		if ($placement === []) {
			return [['costCenter' => null, 'projectId' => null, 'percentage' => 100.0, 'allocationSource' => 'unallocated']];
		}

		$source = (count($placement) === 1 ? 'placement' : 'placement-equal-split');
		$each = round((100 / count($placement)), 2);
		return array_map(
			static fn (string $costCenter): array => ['costCenter' => $costCenter, 'projectId' => null, 'percentage' => $each, 'allocationSource' => $source],
			$placement
		);
	}//end placementShares()

	/**
	 * The allocation lines of one payslip: its gross and employer charges
	 * split by the shares in cents, the rounding remainder on the largest
	 * share, so the lines add up to the payslip exactly (design D3).
	 *
	 * @param list<array<string, mixed>> $shares  The shares from splitFor().
	 * @param array<string, mixed>       $payslip The payslip: its grossCents and chargesCents.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
	 */
	public function linesFor(array $shares, array $payslip): array {
		$weights = array_map(static fn (array $share): float => max(0.0, (float)$share['percentage']), $shares);
		$gross = $this->shares->splitCents(total: (int)$payslip['grossCents'], weights: $weights);
		$charges = $this->shares->splitCents(total: (int)$payslip['chargesCents'], weights: $weights);

		$lines = [];
		foreach ($shares as $i => $share) {
			$lines[] = self::line(share: $share, cents: ['gross' => $gross[$i], 'charges' => $charges[$i]]);
		}

		return $lines;
	}//end linesFor()

	/**
	 * One allocation line: a share with its part of the gross and charges.
	 *
	 * @param array<string, mixed>             $share The share.
	 * @param array{gross: int, charges: int} $cents Its gross and employer charges, in cents.
	 *
	 * @return array<string, mixed>
	 */
	private static function line(array $share, array $cents): array {
		return [
			'costCenter' => $share['costCenter'],
			'projectId' => $share['projectId'],
			'percentage' => (float)$share['percentage'],
			'gross' => round(($cents['gross'] / 100), 2),
			'employerCharges' => round(($cents['charges'] / 100), 2),
			'totalCost' => round((($cents['gross'] + $cents['charges']) / 100), 2),
			'allocationSource' => $share['allocationSource'],
		];
	}//end line()

	/**
	 * Replace a run's allocation lines with the lines of its payslips.
	 *
	 * @param string                     $runId            The run.
	 * @param string                     $period           The wage period, YYYY-MM.
	 * @param string                     $administrationId The run's administration.
	 * @param list<array<string, mixed>> $payslips         Per payslip: payslipId, employeeId, grossCents, chargesCents.
	 *
	 * @return int The number of lines written.
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
	 */
	public function allocateRun(string $runId, string $period, string $administrationId, array $payslips): int {
		if ($runId === '') {
			return 0;
		}

		$this->deleteLinesOf(runId: $runId);
		if ($payslips === []) {
			return 0;
		}

		$run = new CostAllocationRun();
		$run->runId = $runId;
		$run->period = $period;
		$run->administrationId = $administrationId;
		$run->inputs = $this->inputs($period);
		$written = 0;
		foreach ($payslips as $payslip) {
			$written += $this->writeLinesOf(run: $run, payslip: $payslip);
		}

		$this->logger->debug('CostAllocationService: ' . $written . ' allocation lines for run ' . $runId);

		return $written;
	}//end allocateRun()

	/**
	 * Delete the lines a run wrote before.
	 *
	 * @param string $runId The run.
	 *
	 * @return void
	 */
	private function deleteLinesOf(string $runId): void {
		foreach ($this->gateway->findFiltered(self::LINE_SCHEMA, ['payrollRunId' => $runId]) as $stale) {
			$this->gateway->delete((string)($stale['id'] ?? ''), self::LINE_SCHEMA);
		}
	}//end deleteLinesOf()

	/**
	 * Write one payslip's allocation lines.
	 *
	 * @param CostAllocationRun    $run     The run.
	 * @param array<string, mixed> $payslip payslipId, employeeId, grossCents, chargesCents.
	 *
	 * @return int The number of lines written.
	 */
	private function writeLinesOf(CostAllocationRun $run, array $payslip): int {
		$employeeId = (string)($payslip['employeeId'] ?? '');
		$shares = $this->splitFor(employeeId: $employeeId, run: $run);
		$written = 0;
		foreach ($this->linesFor(shares: $shares, payslip: $payslip) as $line) {
			$this->gateway->save(payload: array_merge($line, self::lineReferences(run: $run, payslip: $payslip)), schema: self::LINE_SCHEMA);
			$written++;
		}

		return $written;
	}//end writeLinesOf()

	/**
	 * What a line refers to: the payslip, the run, the period, the employee
	 * and the administration (null when blank).
	 *
	 * @param CostAllocationRun    $run     The run.
	 * @param array<string, mixed> $payslip The payslip.
	 *
	 * @return array<string, string|null>
	 */
	private static function lineReferences(CostAllocationRun $run, array $payslip): array {
		$employeeId = (string)($payslip['employeeId'] ?? '');

		return [
			'payslipId' => (string)$payslip['payslipId'],
			'payrollRunId' => $run->runId,
			'period' => $run->period,
			'employeeId' => ($employeeId === '' ? null : $employeeId),
			'administrationId' => ($run->administrationId === '' ? null : $run->administrationId),
		];
	}//end lineReferences()

	/**
	 * The approved time entries of the period, per employee, for the
	 * employees with an hours allocation only.
	 *
	 * @param string                     $period      The wage period.
	 * @param list<array<string, mixed>> $allocations Every CostAllocation.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function approvedHours(string $period, array $allocations): array {
		$employees = [];
		foreach ($allocations as $allocation) {
			if (($allocation['basis'] ?? '') === 'hours') {
				$employees[(string)($allocation['employeeId'] ?? '')] = true;
			}
		}

		if ($employees === []) {
			return [];
		}

		$timesheets = [];
		foreach ($this->gateway->findFiltered('Timesheet', ['period' => $period, 'status' => 'approved']) as $timesheet) {
			if (isset($employees[(string)($timesheet['employeeId'] ?? '')]) === true) {
				$timesheets[(string)($timesheet['id'] ?? '')] = true;
			}
		}

		$byEmployee = [];
		foreach ($this->gateway->loadAll('TimeEntry') as $entry) {
			if (isset($timesheets[(string)($entry['timesheetId'] ?? '')]) === true) {
				$byEmployee[(string)($entry['employeeId'] ?? '')][] = $entry;
			}
		}

		return $byEmployee;
	}//end approvedHours()

	/**
	 * The employee's allocation covering any day of the period, or null.
	 *
	 * @param CostAllocationSplit $split The split: the employee, every CostAllocation and the period.
	 *
	 * @return array<string, mixed>|null
	 */
	private function coveringAllocation(CostAllocationSplit $split): ?array {
		foreach ($split->inputs['allocations'] as $allocation) {
			if ((string)($allocation['employeeId'] ?? '') === $split->employeeId && $this->overlaps(record: $allocation, split: $split) === true) {
				return $allocation;
			}
		}

		return null;
	}//end coveringAllocation()

	/**
	 * The distinct cost centres of the placements covering any day of the
	 * period, in placement order.
	 *
	 * @param CostAllocationSplit $split The split: the employee, the inputs and the period.
	 *
	 * @return list<string>
	 */
	private function placementCostCenters(CostAllocationSplit $split): array {
		$found = [];
		foreach ($split->inputs['assignments'] as $assignment) {
			if ($this->placedInPeriod(assignment: $assignment, split: $split) === false) {
				continue;
			}

			$costCenter = $this->shares->codeOrNull($split->inputs['unitsById'][(string)($assignment['orgUnitId'] ?? '')]['costCenter'] ?? null);
			if ($costCenter !== null && in_array($costCenter, $found, true) === false) {
				$found[] = $costCenter;
			}
		}

		return $found;
	}//end placementCostCenters()

	/**
	 * Whether a placement is the employee's and covers any day of the period.
	 *
	 * @param array<string, mixed> $assignment The OrgAssignment.
	 * @param CostAllocationSplit  $split      The split: the employee and the period.
	 *
	 * @return bool
	 */
	private function placedInPeriod(array $assignment, CostAllocationSplit $split): bool {
		return (string)($assignment['employeeId'] ?? '') === $split->employeeId && $this->overlaps(record: $assignment, split: $split) === true;
	}//end placedInPeriod()

	/**
	 * Whether a dated record covers any day of the period: active on its
	 * first day, or starting within it.
	 *
	 * @param array<string, mixed> $record The record: its startDate ('' for always) and endDate ('' for open-ended).
	 * @param CostAllocationSplit  $split  The split: the period's first and last day.
	 *
	 * @return bool
	 */
	private function overlaps(array $record, CostAllocationSplit $split): bool {
		$start = (string)($record['startDate'] ?? '');
		if ($this->orgResolution->isActiveOn(['startDate' => $start, 'endDate' => (string)($record['endDate'] ?? '')], $split->from) === true) {
			return true;
		}

		return (trim($start) !== '' && $start > $split->from && $start <= $split->until);
	}//end overlaps()

	/**
	 * The first and last day of a YYYY-MM period.
	 *
	 * @param string $period The period.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function periodBounds(string $period): array {
		try {
			$first = new DateTimeImmutable($period . '-01');
		} catch (\Exception $e) {
			$first = new DateTimeImmutable('first day of this month');
		}

		return [$first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
	}//end periodBounds()

}//end class
