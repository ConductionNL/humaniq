<?php

/**
 * Contract of TimeEstimateService
 *
 * The public surface of TimeEstimateService that other components call, so they depend
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

/**
 * Contract of TimeEstimateService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TimeEstimateServiceInterface {
	/**
	 * Estimated, spent and remaining for one host object, in total and per
	 * role.
	 *
	 * @param array<array<string, mixed>> $estimates TimeEstimate rows, any object.
	 * @param array<array<string, mixed>> $entries TimeEntry rows, any object.
	 * @param string $domainObjectType The `<app>:<schema>` literal.
	 * @param string $domainObjectRef The object's uuid.
	 *
	 * @return array{hasEstimate: bool, estimatedHours: float|null, spentHours: float, remainingHours: float|null, roles: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-002
	 */
	public function summary(
		array $estimates,
		array $entries,
		string $domainObjectType,
		string $domainObjectRef,
	): array;

	/**
	 * The refusal for writing one estimate, or null when it may be written.
	 *
	 * @param array<string, mixed> $estimate The incoming TimeEstimate.
	 * @param array<array<string, mixed>> $existing Every stored TimeEstimate.
	 *
	 * @return string|null The refusal, or null.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-001
	 */
	public function refusalForEstimate(array $estimate, array $existing): ?string;

	/**
	 * The refusal for writing one time entry against an enforced estimate, or
	 * null when it may be written.
	 *
	 * @param array<string, mixed> $entry The incoming TimeEntry.
	 * @param array<array<string, mixed>> $estimates Every stored TimeEstimate.
	 * @param array<array<string, mixed>> $entries Every stored TimeEntry.
	 *
	 * @return string|null The refusal, naming the ceiling and what is left, or null.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-004
	 */
	public function refusalForEntry(array $entry, array $estimates, array $entries): ?string;
}//end interface
