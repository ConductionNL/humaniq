<?php

/**
 * Humaniq TimeEntryWrite
 *
 * One TimeEntry create or update being stamped inside
 * TimeEntryStampListener: the incoming and stored payloads, whether it is a
 * create, and what is resolved for it on the way (the employee, the start
 * and hours, the parent timesheet).
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
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
 * @spec openspec/specs/time-entry-capture/spec.md#Requirement:-humaniq-captures-time-entries-under-a-submit→approve-lifecycle-(REQ-TEC-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

/**
 * One TimeEntry write being stamped.
 *
 * @spec openspec/specs/time-entry-capture/spec.md#Requirement:-humaniq-captures-time-entries-under-a-submit→approve-lifecycle-(REQ-TEC-001)
 */
final class TimeEntryWrite {

	/**
	 * The incoming payload.
	 *
	 * @var array<string, mixed>
	 */
	public array $incoming = [];

	/**
	 * The stored payload (update only).
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $stored = null;

	/**
	 * Whether this is a create write.
	 *
	 * @var bool
	 */
	public bool $isCreate = false;

	/**
	 * The resolved employee id.
	 *
	 * @var string
	 */
	public string $employeeId = '';

	/**
	 * The resolved Employee payload ([] when dangling).
	 *
	 * @var array<string, mixed>
	 */
	public array $employee = [];

	/**
	 * The UTC start timestamp of the booking.
	 *
	 * @var int
	 */
	public int $startedAt = 0;

	/**
	 * The booked hours.
	 *
	 * @var float
	 */
	public float $hours = 0.0;

	/**
	 * The parent timesheet uuid.
	 *
	 * @var string
	 */
	public string $timesheetId = '';
}//end class
