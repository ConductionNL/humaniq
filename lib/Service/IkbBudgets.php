<?php

/**
 * IKB Budgets
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The individual choice budgets touched by one run approval
 * (payroll-reservation-payouts D8), kept in memory so that accrual and
 * spending on the same budget end in one write. A budget remembers the runs
 * it accrued, so a run is never added twice; the balance is always accrued
 * minus spent.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-individual-choice-budget-shall-accrue-and-be-spent-by-approved-request-req-rsp-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Accrue and spend IKB budgets by employee and year.
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-individual-choice-budget-shall-accrue-and-be-spent-by-approved-request-req-rsp-004
 */
final class IkbBudgets {

	/**
	 * The budgets by `employeeId|year`, each with its `uuid` and `row`.
	 *
	 * @var array<string, array{uuid: string, row: array<string, mixed>}>
	 */
	private array $byKey = [];

	/**
	 * The keys written to.
	 *
	 * @var array<string, true>
	 */
	private array $changed = [];

	/**
	 * @param list<array<string, mixed>> $rows The stored IkbBudget rows.
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-individual-choice-budget-shall-accrue-and-be-spent-by-approved-request-req-rsp-004
	 */
	public function __construct(array $rows) {
		foreach ($rows as $row) {
			$key = (string)($row['employeeId'] ?? '') . '|' . (int)($row['year'] ?? 0);
			$this->byKey[$key] = ['uuid' => (string)($row['id'] ?? ($row['@self']['id'] ?? '')), 'row' => $row];
		}
	}//end __construct()

	/**
	 * Add a run's accrual, once per run. A missing budget is created.
	 *
	 * @param string      $employeeId The employee.
	 * @param int         $year       The year.
	 * @param float       $amount     The accrual, in euros.
	 * @param string      $runId      The run.
	 * @param string|null $userId     The employee's account, for a new budget.
	 *
	 * @return bool Whether it was added; false when the run was already added.
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-individual-choice-budget-shall-accrue-and-be-spent-by-approved-request-req-rsp-004
	 */
	public function accrue(string $employeeId, int $year, float $amount, string $runId, ?string $userId = null): bool {
		if ($employeeId === '' || $year <= 0 || $amount <= 0) {
			return false;
		}

		$key = $this->ensure(employeeId: $employeeId, year: $year, userId: $userId);
		$row = $this->byKey[$key]['row'];
		$runs = array_values(array_map('strval', (array)($row['accruedRunIds'] ?? [])));
		if (in_array($runId, $runs, true) === true) {
			return false;
		}

		$runs[] = $runId;
		$row['accruedRunIds'] = $runs;
		$row['accrued'] = round(((float)($row['accrued'] ?? 0) + $amount), 2);
		$this->put(key: $key, row: $row);

		return true;
	}//end accrue()

	/**
	 * Take a settled spend off the budget.
	 *
	 * @param string $employeeId The employee.
	 * @param int    $year       The year.
	 * @param float  $amount     The spend, in euros.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-individual-choice-budget-shall-accrue-and-be-spent-by-approved-request-req-rsp-004
	 */
	public function spend(string $employeeId, int $year, float $amount): void {
		if ($employeeId === '' || $year <= 0 || $amount <= 0) {
			return;
		}

		$key = $this->ensure(employeeId: $employeeId, year: $year, userId: null);
		$row = $this->byKey[$key]['row'];
		$row['spent'] = round(((float)($row['spent'] ?? 0) + $amount), 2);
		$this->put(key: $key, row: $row);
	}//end spend()

	/**
	 * The budget of an employee and year as it now stands, or null.
	 *
	 * @param string $employeeId The employee.
	 * @param int    $year       The year.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-individual-choice-budget-shall-accrue-and-be-spent-by-approved-request-req-rsp-004
	 */
	public function get(string $employeeId, int $year): ?array {
		return ($this->byKey[$employeeId . '|' . $year]['row'] ?? null);
	}//end get()

	/**
	 * The budgets written to, by uuid (`new:<key>` for a budget to create).
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-individual-choice-budget-shall-accrue-and-be-spent-by-approved-request-req-rsp-004
	 */
	public function changed(): array {
		$out = [];
		foreach (array_keys($this->changed) as $key) {
			$uuid = $this->byKey[$key]['uuid'];
			$out[($uuid === '' ? 'new:' . $key : $uuid)] = $this->byKey[$key]['row'];
		}

		return $out;
	}//end changed()

	/**
	 * The key of the budget, created empty when missing.
	 *
	 * @param string      $employeeId The employee.
	 * @param int         $year       The year.
	 * @param string|null $userId     The employee's account.
	 *
	 * @return string
	 */
	private function ensure(string $employeeId, int $year, ?string $userId): string {
		$key = $employeeId . '|' . $year;
		if (isset($this->byKey[$key]) === false) {
			$this->byKey[$key] = ['uuid' => '', 'row' => ['employeeId' => $employeeId, 'userId' => $userId, 'year' => $year, 'accrued' => 0.0, 'spent' => 0.0, 'balance' => 0.0, 'accruedRunIds' => []]];
		}

		return $key;
	}//end ensure()

	/**
	 * Store a row with its balance recomputed, and note it changed.
	 *
	 * @param string               $key The key.
	 * @param array<string, mixed> $row The row.
	 *
	 * @return void
	 */
	private function put(string $key, array $row): void {
		$row['balance'] = round(((float)($row['accrued'] ?? 0) - (float)($row['spent'] ?? 0)), 2);
		$this->byKey[$key]['row'] = $row;
		$this->changed[$key] = true;
	}//end put()
}//end class
