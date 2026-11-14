<?php

/**
 * Humaniq AnnouncementReader
 *
 * One employee as a reader of announcements inside AnnouncementService: the
 * employee and their id, the units of their live placements, and the day an
 * announcement must be shown on ('' when only the audience counts).
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
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * An employee reading announcements.
 *
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-001
 */
final class AnnouncementReader {

	/**
	 * The Employee.
	 *
	 * @var array<string, mixed>
	 */
	public array $employee = [];

	/**
	 * The employee's id.
	 *
	 * @var string
	 */
	public string $employeeId = '';

	/**
	 * The units of the employee's live assignments.
	 *
	 * @var array<int, string>
	 */
	public array $units = [];

	/**
	 * The day an announcement must be shown on, or '' for the audience only.
	 *
	 * @var string
	 */
	public string $today = '';
}//end class
