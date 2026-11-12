<?php

/**
 * Humaniq OrgChartRequest
 *
 * What one organisation chart is drawn from: the rows the caller loaded,
 * the day, the root and the people the caller may read.
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
 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The rows and the view of one organisation chart.
 *
 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
 */
final class OrgChartRequest {

	/**
	 * OrgUnit rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $units = [];

	/**
	 * OrgAssignment rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $assignments = [];

	/**
	 * Employee rows by id, for names.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $employeesById = [];

	/**
	 * The day (Y-m-d).
	 *
	 * @var string
	 */
	public string $date = '';

	/**
	 * Only this unit's subtree.
	 *
	 * @var string|null
	 */
	public ?string $rootId = null;

	/**
	 * List these people; null lists none.
	 *
	 * @var array<int, string>|null
	 */
	public ?array $readableEmployeeIds = null;
}//end class
