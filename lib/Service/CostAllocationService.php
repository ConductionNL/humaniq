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
	 * @param HoursRegisterGateway $gateway       The register plumbing.
	 * @param OrgResolutionService $orgResolution The placement date rule.
	 * @param LoggerInterface      $logger        The logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly OrgResolutionService $orgResolution,
		private readonly LoggerInterface $logger,
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
	 * @param string               $employeeId The employee.
	 * @param string               $period     The wage period, YYYY-MM.
	 * @param array<string, mixed> $inputs     The inputs from inputs().
	 *
	 * @return list<array{costCenter: string|null, projectId: string|null, percentage: float, allocationSource: string}>
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
	 */
	public function splitFor(string $employeeId, string $period, array $inputs): array {
		[$from, $until] = $this->periodBounds($period);
		$allocation = $this->coveringAllocation(employeeId: $employeeId, allocations: $inputs['allocations'], from: $from, until: $until);
		$placement = $this->placementCostCenters(employeeId: $employeeId, inputs: $inputs, from: $from, until: $until);

		if ($allocation !== null && ($allocation['basis'] ?? 'fixed') === 'fixed') {
			$shares = $this->fixedShares($allocation);
			if ($shares !== []) {
				return $shares;
			}
		}

		if ($allocation !== null && ($allocation['basis'] ?? '') === 'hours') {
			$fallback = (count($placement) === 1 ? $placement[0] : null);
			$shares = $this->hoursShares(entries: ($inputs['hoursByEmployee'][$employeeId] ?? []), fallbackCostCenter: $fallback);
			if ($shares !== []) {
				return $shares;
			}
		}

		if ($placement !== []) {
			$source = (count($placement) === 1 ? 'placement' : 'placement-equal-split');
			$each = round((100 / count($placement)), 2);
			return array_map(
				static fn (string $costCenter): array => ['costCenter' => $costCenter, 'projectId' => null, 'percentage' => $each, 'allocationSource' => $source],
				$placement
			);
		}

		return [['costCenter' => null, 'projectId' => null, 'percentage' => 100.0, 'allocationSource' => 'unallocated']];
	}//end splitFor()

	/**
	 * The allocation lines of one payslip: its gross and employer charges
	 * split by the shares in cents, the rounding remainder on the largest
	 * share, so the lines add up to the payslip exactly (design D3).
	 *
	 * @param list<array<string, mixed>> $shares       The shares from splitFor().
	 * @param int                        $grossCents   The payslip's gross, in cents.
	 * @param int                        $chargesCents The payslip's employer charges, in cents.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
	 */
	public function linesFor(array $shares, int $grossCents, int $chargesCents): array {
		$weights = array_map(static fn (array $share): float => max(0.0, (float)$share['percentage']), $shares);
		$gross = $this->splitCents(total: $grossCents, weights: $weights);
		$charges = $this->splitCents(total: $chargesCents, weights: $weights);

		$lines = [];
		foreach ($shares as $i => $share) {
			$lines[] = [
				'costCenter' => $share['costCenter'],
				'projectId' => $share['projectId'],
				'percentage' => (float)$share['percentage'],
				'gross' => round(($gross[$i] / 100), 2),
				'employerCharges' => round(($charges[$i] / 100), 2),
				'totalCost' => round((($gross[$i] + $charges[$i]) / 100), 2),
				'allocationSource' => $share['allocationSource'],
			];
		}

		return $lines;
	}//end linesFor()

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

		foreach ($this->gateway->findFiltered(self::LINE_SCHEMA, ['payrollRunId' => $runId]) as $stale) {
			$this->gateway->delete((string)($stale['id'] ?? ''), self::LINE_SCHEMA);
		}

		if ($payslips === []) {
			return 0;
		}

		$inputs = $this->inputs($period);
		$written = 0;
		foreach ($payslips as $payslip) {
			$employeeId = (string)($payslip['employeeId'] ?? '');
			$shares = $this->splitFor(employeeId: $employeeId, period: $period, inputs: $inputs);
			foreach ($this->linesFor($shares, (int)$payslip['grossCents'], (int)$payslip['chargesCents']) as $line) {
				$this->gateway->save(
					payload: array_merge(
						$line,
						[
							'payslipId' => (string)$payslip['payslipId'],
							'payrollRunId' => $runId,
							'period' => $period,
							'employeeId' => ($employeeId === '' ? null : $employeeId),
							'administrationId' => ($administrationId === '' ? null : $administrationId),
						]
					),
					schema: self::LINE_SCHEMA
				);
				$written++;
			}
		}

		$this->logger->debug('CostAllocationService: ' . $written . ' allocation lines for run ' . $runId);

		return $written;
	}//end allocateRun()

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
	 * @param string                     $employeeId  The employee.
	 * @param list<array<string, mixed>> $allocations Every CostAllocation.
	 * @param string                     $from        The period's first day.
	 * @param string                     $until       The period's last day.
	 *
	 * @return array<string, mixed>|null
	 */
	private function coveringAllocation(string $employeeId, array $allocations, string $from, string $until): ?array {
		foreach ($allocations as $allocation) {
			if ((string)($allocation['employeeId'] ?? '') === $employeeId
				&& $this->overlaps(start: (string)($allocation['startDate'] ?? ''), end: (string)($allocation['endDate'] ?? ''), from: $from, until: $until) === true
			) {
				return $allocation;
			}
		}

		return null;
	}//end coveringAllocation()

	/**
	 * The shares of a fixed split.
	 *
	 * @param array<string, mixed> $allocation The allocation.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function fixedShares(array $allocation): array {
		$shares = [];
		foreach ((array)($allocation['splits'] ?? []) as $split) {
			$costCenter = trim((string)($split['costCenter'] ?? ''));
			if ($costCenter === '' || is_numeric($split['percentage'] ?? null) === false) {
				continue;
			}

			$shares[] = ['costCenter' => $costCenter, 'projectId' => $this->codeOrNull($split['projectId'] ?? null), 'percentage' => (float)$split['percentage'], 'allocationSource' => 'fixed'];
		}

		return $shares;
	}//end fixedShares()

	/**
	 * The shares of the hours booked, per cost centre and project, in the
	 * order they first appear.
	 *
	 * @param list<array<string, mixed>> $entries            The approved entries.
	 * @param string|null                $fallbackCostCenter The placement's cost centre for entries without one.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function hoursShares(array $entries, ?string $fallbackCostCenter): array {
		$hours = [];
		$keys = [];
		foreach ($entries as $entry) {
			$value = (is_numeric($entry['hours'] ?? null) === true ? (float)$entry['hours'] : 0.0);
			if ($value <= 0.0) {
				continue;
			}

			$costCenter = ($this->codeOrNull($entry['costCenter'] ?? null) ?? $fallbackCostCenter);
			$projectId = $this->codeOrNull($entry['projectId'] ?? null);
			$key = ($costCenter ?? '') . '|' . ($projectId ?? '');
			$keys[$key] = [$costCenter, $projectId];
			$hours[$key] = (($hours[$key] ?? 0.0) + $value);
		}

		$total = array_sum($hours);
		if ($total <= 0.0) {
			return [];
		}

		$shares = [];
		foreach ($hours as $key => $value) {
			$shares[] = ['costCenter' => $keys[$key][0], 'projectId' => $keys[$key][1], 'percentage' => round(($value / $total * 100), 2), 'allocationSource' => 'hours'];
		}

		return $shares;
	}//end hoursShares()

	/**
	 * The distinct cost centres of the placements covering any day of the
	 * period, in placement order.
	 *
	 * @param string               $employeeId The employee.
	 * @param array<string, mixed> $inputs     The inputs.
	 * @param string               $from       The period's first day.
	 * @param string               $until      The period's last day.
	 *
	 * @return list<string>
	 */
	private function placementCostCenters(string $employeeId, array $inputs, string $from, string $until): array {
		$found = [];
		foreach ($inputs['assignments'] as $assignment) {
			if ((string)($assignment['employeeId'] ?? '') !== $employeeId
				|| $this->overlaps(start: (string)($assignment['startDate'] ?? ''), end: (string)($assignment['endDate'] ?? ''), from: $from, until: $until) === false
			) {
				continue;
			}

			$costCenter = $this->codeOrNull($inputs['unitsById'][(string)($assignment['orgUnitId'] ?? '')]['costCenter'] ?? null);
			if ($costCenter !== null && in_array($costCenter, $found, true) === false) {
				$found[] = $costCenter;
			}
		}

		return $found;
	}//end placementCostCenters()

	/**
	 * Whether a dated record covers any day between two dates: active on
	 * the first day, or starting within the range.
	 *
	 * @param string $start The record's start date, or '' for always.
	 * @param string $end   The record's end date, or '' for open-ended.
	 * @param string $from  The range's first day.
	 * @param string $until The range's last day.
	 *
	 * @return bool
	 */
	private function overlaps(string $start, string $end, string $from, string $until): bool {
		$record = ['startDate' => $start, 'endDate' => $end];
		if ($this->orgResolution->isActiveOn($record, $from) === true) {
			return true;
		}

		return (trim($start) !== '' && $start > $from && $start <= $until);
	}//end overlaps()

	/**
	 * Split an amount in cents by weights; the remainder goes to the
	 * largest weight.
	 *
	 * @param int         $total   The amount, in cents.
	 * @param list<float> $weights The weights.
	 *
	 * @return list<int>
	 */
	private function splitCents(int $total, array $weights): array {
		$sum = array_sum($weights);
		if ($sum <= 0.0) {
			$weights = array_fill(0, count($weights), 1.0);
			$sum = (float)count($weights);
		}

		$parts = array_map(static fn (float $weight): int => (int)round($total * $weight / $sum), $weights);
		$largest = array_keys($weights, max($weights), true)[0];
		$parts[$largest] += ($total - array_sum($parts));

		return $parts;
	}//end splitCents()

	/**
	 * A trimmed code, or null when empty.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null
	 */
	private function codeOrNull(mixed $value): ?string {
		$code = trim((string)($value ?? ''));
		return ($code === '' ? null : $code);
	}//end codeOrNull()

	/**
	 * The first and last day of a YYYY-MM period.
	 *
	 * @param string $period The period.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function periodBounds(string $period): array {
		$first = \DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
		if ($first === false) {
			$first = new \DateTimeImmutable('first day of this month');
		}

		return [$first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
	}//end periodBounds()

}//end class
