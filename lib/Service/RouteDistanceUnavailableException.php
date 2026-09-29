<?php

/**
 * Route Distance Unavailable Exception
 *
 * No road distance could be obtained: integriq is not installed, no route
 * planner source is configured, or the source gave no distance
 * (expenses-travel-calculation D3). The message says which, for the page.
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
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use RuntimeException;

/**
 * The route planner could not answer.
 *
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-003
 */
class RouteDistanceUnavailableException extends RuntimeException {
}//end class
