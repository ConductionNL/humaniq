<?php

/**
 * Contract of ForwardCapacityService
 *
 * The public surface of ForwardCapacityService that other components call, so they depend
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
 * Contract of ForwardCapacityService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ForwardCapacityServiceInterface {
	/**
	 * Planned against contracted, per employee, over a forward window.
	 *
	 * @param array<int, string> $employeeIds The employees to read.
	 * @param DateTimeImmutable $from First day, inclusive.
	 * @param DateTimeImmutable $to Last day, inclusive.
	 * @param array<string, mixed> $sources Rows per source, as {@see \OCA\Humaniq\Service\AvailabilityService::availability()} takes them.
	 *
	 * @return array{from: string, to: string, employees: array<int, array<string, mixed>>, totals: array<string, mixed>}
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C03
	 */
	public function capacity(
		array $employeeIds,
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		array $sources,
	): array;
}//end interface
