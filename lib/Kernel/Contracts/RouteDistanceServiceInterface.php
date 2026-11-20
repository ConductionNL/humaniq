<?php

/**
 * Contract of RouteDistanceService
 *
 * The public surface of RouteDistanceService that other components call, so they depend
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
 * Contract of RouteDistanceService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RouteDistanceServiceInterface {
	/**
	 * The road distance from one postcode to another.
	 *
	 * @param string $originPostcode      Where the trip starts.
	 * @param string $destinationPostcode Where it ends.
	 *
	 * @return array{distanceKm: float, provider: string}
	 *
	 * @throws \OCA\Humaniq\Service\RouteDistanceUnavailableException When no distance can be obtained.
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-003
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is the fleet's static
	 *  resolver for renamed apps; there is no instance to inject.
	 */
	public function lookup(string $originPostcode, string $destinationPostcode): array;
}//end interface
