<?php

/**
 * Contract of DepartmentLeaveScheduleService
 *
 * The public surface of DepartmentLeaveScheduleService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
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
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Kernel\Contracts;

use DateTimeImmutable;

/**
 * Contract of DepartmentLeaveScheduleService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DepartmentLeaveScheduleServiceInterface {
	/**
	 * Compose the schedule for one unit over a period.
	 *
	 * @param array<array<string, mixed>> $requests LeaveRequest objects, any employee and any status.
	 * @param array<int, string> $memberEmployeeIds The employees placed in this org unit.
	 * @param DateTimeImmutable $from First day of the period, inclusive.
	 * @param DateTimeImmutable $to Last day of the period, inclusive.
	 * @param array<int, string>|null $visibleRequestIds Ids the reader may see in full, or null when they may see them all.
	 *
	 * @return array<int, array<string, mixed>> The entries, in start-date order.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S01
	 */
	public function compose(
		array $requests,
		array $memberEmployeeIds,
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		?array $visibleRequestIds = null,
	): array;
}//end interface
