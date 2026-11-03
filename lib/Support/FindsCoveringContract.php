<?php

/**
 * FindsCoveringContract.
 *
 * Finds the employment contract of an employee that covers a wage period, for a class that also uses ChecksPeriodCoverage and offers loadAll() and idOf().
 *
 * @category Support
 * @package  OCA\Humaniq\Support
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
 * @spec exclude helper shared by services and checks, no behavioural spec surface.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Support;

/**
 * Finds the employment contract of an employee that covers a wage period.
 */
trait FindsCoveringContract {
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
}//end class
