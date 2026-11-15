<?php

/**
 * Humaniq HandoffItem
 *
 * One period item of one employee being checked for the external bureau
 * handoff inside HandoffPeriodItems: its kind and row, the employee and the
 * period, and what an earlier handoff sent for it.
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
 * One period item and what was sent for it.
 *
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
 */
final class HandoffItem {

	/**
	 * The mutation kind: hours, claim, allowance, leave-transaction, sickness or garnishment.
	 *
	 * @var string
	 */
	public string $kind = '';

	/**
	 * The item.
	 *
	 * @var array<string, mixed>
	 */
	public array $row = [];

	/**
	 * The employee.
	 *
	 * @var string
	 */
	public string $employeeId = '';

	/**
	 * The wage period, YYYY-MM.
	 *
	 * @var string
	 */
	public string $period = '';

	/**
	 * The key of the item in the sent state.
	 *
	 * @var string
	 */
	public string $key = '';

	/**
	 * What was sent for the item, or null when it never went out.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $before = null;
}//end class
