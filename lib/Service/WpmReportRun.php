<?php

/**
 * Humaniq WpmReportRun
 *
 * The state of compiling one WPM report inside WpmReportService: the
 * administration and year, who compiles it, the kilometre groups, the trips
 * without a mode and the employee count.
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
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One WPM report being compiled.
 *
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-004
 */
final class WpmReportRun {

	/**
	 * The administration.
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * The calendar year.
	 *
	 * @var int
	 */
	public int $year = 0;

	/**
	 * Who compiles it.
	 *
	 * @var string
	 */
	public string $userId = '';

	/**
	 * Business kilometres per mode and fuel.
	 *
	 * @var array<string, array{transportMode: string, fuelType: string|null, km: float}>
	 */
	public array $business = [];

	/**
	 * Commuting kilometres per mode and fuel.
	 *
	 * @var array<string, array{transportMode: string, fuelType: string|null, km: float}>
	 */
	public array $commute = [];

	/**
	 * The trips without a mode.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $missing = [];

	/**
	 * The people employed on at least one day of the year.
	 *
	 * @var int
	 */
	public int $employees = 0;
}//end class
