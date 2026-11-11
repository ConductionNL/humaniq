<?php

/**
 * Humaniq CostAllocationRun
 *
 * The run whose allocation lines are being written: its id, wage period,
 * administration and the period's allocation inputs.
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
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One run's allocation.
 *
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
 */
final class CostAllocationRun {

	/**
	 * The run.
	 *
	 * @var string
	 */
	public string $runId = '';

	/**
	 * The wage period, YYYY-MM.
	 *
	 * @var string
	 */
	public string $period = '';

	/**
	 * The run's administration.
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * The period's allocation inputs.
	 *
	 * @var array<string, mixed>
	 */
	public array $inputs = [];
}//end class
