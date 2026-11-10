<?php

/**
 * Humaniq ExpenseFold
 *
 * The claims and allowances folded into one employee's payslip in one run,
 * as they are added up.
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
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-002
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One employee's fold in one run.
 *
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
 */
final class ExpenseFold {

	/**
	 * From PayrollExpenseFoldService::inputs().
	 *
	 * @var array<string, mixed>
	 */
	public array $inputs = [];

	/**
	 * The employee.
	 *
	 * @var string
	 */
	public string $employeeId = '';

	/**
	 * The run's period, YYYY-MM.
	 *
	 * @var string
	 */
	public string $period = '';

	/**
	 * The run.
	 *
	 * @var string
	 */
	public string $runId = '';

	/**
	 * The period's last day, Y-m-d.
	 *
	 * @var string
	 */
	public string $lastDay = '';

	/**
	 * The fold so far.
	 *
	 * @var array{claimCents: int, claimIds: list<string>, taxedCents: int, untaxedCents: int, lines: list<array<string, mixed>>, wkr: list<array<string, mixed>>}
	 */
	public array $totals = ['claimCents' => 0, 'claimIds' => [], 'taxedCents' => 0, 'untaxedCents' => 0, 'lines' => [], 'wkr' => []];
}//end class
