<?php

/**
 * Humaniq EmployeeEarnings
 *
 * One employee's pay in a year being worked out inside
 * PayTransparencyService: the employee, the contract in the year, the year's
 * payslips, and the gross, hours and variable pay added up from them.
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
 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One employee's pay in the year.
 *
 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
 */
final class EmployeeEarnings {

	/**
	 * The employee.
	 *
	 * @var array<string, mixed>
	 */
	public array $employee = [];

	/**
	 * The employee's latest contract running in the year, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $contract = null;

	/**
	 * The year's payslips.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $slips = [];

	/**
	 * The gross pay so far.
	 *
	 * @var float
	 */
	public float $gross = 0.0;

	/**
	 * The hours so far.
	 *
	 * @var float
	 */
	public float $hours = 0.0;

	/**
	 * The pay above the monthly salary so far.
	 *
	 * @var float
	 */
	public float $variable = 0.0;
}//end class
