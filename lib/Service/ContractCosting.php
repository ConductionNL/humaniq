<?php

/**
 * Humaniq ContractCosting
 *
 * The employee, the active contract and the costing period one cost-rate
 * resolution prices.
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
 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * What one cost-rate resolution prices.
 *
 * @spec openspec/specs/employer-hourly-cost-rate/spec.md
 */
final class ContractCosting {

	/**
	 * The Employee object as an array.
	 *
	 * @var array<string, mixed>
	 */
	public array $employee = [];

	/**
	 * The active EmploymentContract as an array, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $contract = null;

	/**
	 * Costing period as `YYYY-MM`.
	 *
	 * @var string
	 */
	public string $period = '';
}//end class
