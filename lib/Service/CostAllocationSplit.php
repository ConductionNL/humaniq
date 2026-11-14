<?php

/**
 * Humaniq CostAllocationSplit
 *
 * The state of splitting one employee's cost in one period inside
 * CostAllocationService: the employee, the period's inputs and first and
 * last day, the covering allocation and the placements' cost centres.
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

/**
 * One employee's cost split in one period.
 *
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
 */
final class CostAllocationSplit {

	/**
	 * The employee.
	 *
	 * @var string
	 */
	public string $employeeId = '';

	/**
	 * The inputs from CostAllocationService::inputs().
	 *
	 * @var array<string, mixed>
	 */
	public array $inputs = [];

	/**
	 * The period's first day.
	 *
	 * @var string
	 */
	public string $from = '';

	/**
	 * The period's last day.
	 *
	 * @var string
	 */
	public string $until = '';

	/**
	 * The employee's allocation covering any day of the period, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $allocation = null;

	/**
	 * The distinct cost centres of the placements covering the period.
	 *
	 * @var list<string>
	 */
	public array $placement = [];
}//end class
