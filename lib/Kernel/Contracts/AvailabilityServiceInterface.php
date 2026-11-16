<?php

/**
 * Contract of AvailabilityService
 *
 * The public surface of AvailabilityService that other components call, so they depend
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
 * Contract of AvailabilityService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AvailabilityServiceInterface {
	/**
	 * Free hours per employee over a window.
	 *
	 * @param array<int, string> $employeeIds The employees to answer for.
	 * @param DateTimeImmutable $from First day, inclusive.
	 * @param DateTimeImmutable $to Last day, inclusive.
	 * @param array<string, mixed> $sources Rows per source, as {@see \OCA\Humaniq\Service\AgendaComposer::compose()} takes them, plus `workingPatterns`, `nonWorkingTimes`, `competences` and `nonWorkingDates`.
	 * @param array<int, string> $requiredCompetences Codes every answered employee must hold on every day of the window.
	 *
	 * @return array<int, array<string, mixed>> One row per employee, most free first.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	public function availability(
		array $employeeIds,
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		array $sources,
		array $requiredCompetences = [],
	): array;
}//end interface
