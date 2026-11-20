<?php

/**
 * Contract of ResourceBookingService
 *
 * The public surface of ResourceBookingService that other components call, so they depend
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
 * Contract of ResourceBookingService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ResourceBookingServiceInterface {
	/**
	 * The refusal for one booking, or null when it may be written.
	 *
	 * @param array<string, mixed> $booking The incoming ResourceBooking payload.
	 * @param array<string, mixed>|null $resource The Resource it books, or null when it does not resolve.
	 * @param array<array<string, mixed>> $existing Every booking already stored, any resource.
	 *
	 * @return string|null The refusal, naming what blocks it, or null.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
	 */
	public function refusal(array $booking, ?array $resource, array $existing): ?string;
}//end interface
