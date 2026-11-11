<?php

/**
 * Humaniq CostRateRequest
 *
 * What one employer-cost-rate request asks for: the employee, the costing
 * period and the caller's own additions.
 *
 * @category Controller
 * @package  OCA\Humaniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://humaniq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

/**
 * One cost-rate request as the controller resolves it.
 *
 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
 */
final class CostRateRequest {

	/**
	 * The Employee object id.
	 *
	 * @var string
	 */
	public string $employeeId = '';

	/**
	 * Costing period `YYYY-MM`.
	 *
	 * @var string
	 */
	public string $period = '';

	/**
	 * Caller-computed additions, per ADR-081.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $additions = [];
}//end class
