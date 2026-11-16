<?php

/**
 * Contract of AgendaComposer
 *
 * The public surface of AgendaComposer that other components call, so they depend
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
 * Contract of AgendaComposer.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AgendaComposerInterface {
	/**
	 * Compose the agenda for one subject over a period.
	 *
	 * @param array<string, mixed> $sources Rows per source: assignments, shifts, leaveRequests, sickLeaveCases, interviews, bookings, subscriptions.
	 * @param array{subjectType: string, subjectId: string, from: string, to: string, memberEmployeeIds?: array<int, mixed>} $query The subject kind (`employee`, `orgUnit` or `resource`), its id, the first and last day (inclusive, ISO dates), and the employees in the org unit when the subject is one.
	 *
	 * @return array<int, array<string, mixed>> The entries, in start order.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	public function compose(array $sources, array $query): array;
}//end interface
