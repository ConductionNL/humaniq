<?php

/**
 * Handoff Employee View
 *
 * The employee-level half of a payroll handoff (payroll-external-bureau-handoff
 * D2): an employee's payroll facts per mutation kind (salary, bank account,
 * tax settings, leaving, contract), and the mutations that differ from what
 * earlier handoffs sent. Someone never sent travels as one start.
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
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Compiles the employee-level mutations of one employee.
 *
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
 */
class HandoffEmployeeView {

	/**
	 * The employee-level payroll facts per mutation kind.
	 *
	 * @var array<string, list<string>>
	 */
	private const EMPLOYEE_SLICES = [
		'salary' => ['grossMonthlySalary'],
		'bank-account' => ['iban'],
		'tax-settings' => ['taxTableColor', 'loonheffingskortingToegepast'],
		'leave' => ['endDate'],
	];

	/**
	 * The contract facts a bureau needs.
	 *
	 * @var list<string>
	 */
	private const CONTRACT_FIELDS = ['type', 'hoursPerWeek', 'hourlyWage', 'cao', 'caoSchaal'];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The register plumbing.
	 * @param HandoffPeriodItems   $items   Supplies the value normalisation.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly HandoffPeriodItems $items,
	) {

	}//end __construct()

	/**
	 * The payroll view of one employee, per mutation kind.
	 *
	 * @param array<string, mixed> $employee The employee.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function viewOf(array $employee): array {
		$view = [];
		foreach (self::EMPLOYEE_SLICES as $kind => $fields) {
			foreach ($fields as $field) {
				$view[$kind][$field] = $this->items->normalise($employee[$field] ?? null);
			}
		}

		$contract = $this->latestContract((string)$employee['id']);
		foreach (self::CONTRACT_FIELDS as $field) {
			$view['contract'][$field] = $this->items->normalise($contract[$field] ?? null);
		}

		return $view;
	}//end viewOf()

	/**
	 * The mutations of one employee: one start for someone never sent, else
	 * one per kind whose values differ from what was sent.
	 *
	 * @param array<string, mixed>      $employee The employee.
	 * @param array<string, mixed>|null $sent     What earlier handoffs sent, or null.
	 * @param string                    $period   The period.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
	 */
	public function differences(array $employee, ?array $sent, string $period): array {
		$employeeId = (string)$employee['id'];
		$view = $this->viewOf(employee: $employee);
		if ($sent === null) {
			$fields = [];
			foreach ($view as $values) {
				foreach ($values as $field => $value) {
					$fields[$field] = ['old' => null, 'new' => $value];
				}
			}

			return [['employeeId' => $employeeId, 'kind' => 'start', 'effectiveDate' => ((string)($employee['startDate'] ?? '') === '' ? $period . '-01' : (string)$employee['startDate']), 'fields' => $fields, 'sourceSchema' => 'Employee', 'sourceId' => $employeeId, 'sentState' => $view]];
		}

		$mutations = [];
		foreach ($view as $kind => $values) {
			$fields = [];
			foreach ($values as $field => $value) {
				$old = ($sent[$kind][$field] ?? null);
				if ($old !== $value) {
					$fields[$field] = ['old' => $old, 'new' => $value];
				}
			}

			if ($fields === []) {
				continue;
			}

			[$sourceSchema, $sourceId, $effective] = $this->sourceOf(kind: $kind, employee: $employee, period: $period);
			$mutations[] = ['employeeId' => $employeeId, 'kind' => $kind, 'effectiveDate' => $effective, 'fields' => $fields, 'sourceSchema' => $sourceSchema, 'sourceId' => $sourceId, 'sentState' => [$kind => $values]];
		}

		return $mutations;
	}//end differences()

	/**
	 * Where a change came from and from when it applies: a salary change
	 * from the latest applied pay change, a contract change from the
	 * contract, anything else from the employee record.
	 *
	 * @param string               $kind     The mutation kind.
	 * @param array<string, mixed> $employee The employee.
	 * @param string               $period   The period.
	 *
	 * @return array{0: string, 1: string, 2: string}
	 */
	private function sourceOf(string $kind, array $employee, string $period): array {
		$employeeId = (string)$employee['id'];
		if ($kind === 'salary') {
			$latest = $this->latestRaise($employeeId);
			if ($latest !== null) {
				return ['CompAdjustment', (string)$latest['id'], substr((string)($latest['appliedAt'] ?? ''), 0, 10)];
			}
		}

		if ($kind === 'contract') {
			$contract = $this->latestContract($employeeId);
			if ($contract !== []) {
				return ['EmploymentContract', (string)$contract['id'], (string)($contract['startDate'] ?? ($period . '-01'))];
			}
		}

		if ($kind === 'leave' && (string)($employee['endDate'] ?? '') !== '') {
			return ['Employee', $employeeId, (string)$employee['endDate']];
		}

		return ['Employee', $employeeId, $period . '-01'];
	}//end sourceOf()

	/**
	 * The employee's most recently applied pay change, or null.
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return array<string, mixed>|null
	 */
	private function latestRaise(string $employeeId): ?array {
		$latest = null;
		foreach ($this->gateway->findFiltered('CompAdjustment', ['employeeId' => $employeeId, 'status' => 'applied']) as $adjustment) {
			if ($latest === null || (string)($adjustment['appliedAt'] ?? '') > (string)($latest['appliedAt'] ?? '')) {
				$latest = $adjustment;
			}
		}

		return $latest;
	}//end latestRaise()

	/**
	 * The employee's most recent contract, or [].
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return array<string, mixed>
	 */
	private function latestContract(string $employeeId): array {
		$latest = [];
		foreach ($this->gateway->findFiltered('EmploymentContract', ['employeeId' => $employeeId]) as $contract) {
			if ($latest === [] || (string)($contract['startDate'] ?? '') > (string)($latest['startDate'] ?? '')) {
				$latest = $contract;
			}
		}

		return $latest;
	}//end latestContract()

}//end class
