<?php

/**
 * Humaniq HoursPayRun
 *
 * The state of working out one employee's hours pay: the contract, the
 * time entries, the calendar, the rate, whether the employee is salaried,
 * and the pay so far.
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
 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-001
 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One employee's hours pay being added up.
 *
 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-001
 */
final class HoursPayRun {

	/**
	 * The covering EmploymentContract.
	 *
	 * @var array<string, mixed>
	 */
	public array $contract = [];

	/**
	 * Every TimeEntry.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $entries = [];

	/**
	 * The calendar's non-working dates, null when unread.
	 *
	 * @var list<string>|null
	 */
	public ?array $nonWorkingDates = null;

	/**
	 * The hourly rate, or null.
	 *
	 * @var float|null
	 */
	public ?float $rate = null;

	/**
	 * Whether the employee has a monthly salary.
	 *
	 * @var bool
	 */
	public bool $salaried = false;

	/**
	 * The pay so far.
	 *
	 * @var array<string, mixed>
	 */
	public array $pay = [];
}//end class
