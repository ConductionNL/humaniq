<?php

/**
 * Employee Window
 *
 * One employee, the days an availability answer covers, and the source rows it reads.
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * One employee over a window of days, with the source rows to read.
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
 */
final class EmployeeWindow {

	/**
	 * @param string               $employeeId The employee.
	 * @param DateTimeImmutable    $from       First day, inclusive.
	 * @param DateTimeImmutable    $to         Last day, inclusive.
	 * @param array<string, mixed> $sources    Rows per source, plus working patterns, non-working times, competences and calendar dates.
	 */
	public function __construct(
		public readonly string $employeeId,
		public readonly DateTimeImmutable $from,
		public readonly DateTimeImmutable $to,
		public readonly array $sources,
	) {

	}//end __construct()

}//end class
