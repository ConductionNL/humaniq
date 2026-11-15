<?php

/**
 * Humaniq PersonnelBudgetMonth
 *
 * The state of budgeting one formation place month by month inside
 * PersonnelBudgetService: the rows, the scenario's mutations, the CAO raise
 * factor, the place and its scale minimum, and the month being added.
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
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One place being budgeted over the months of a year.
 *
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
 */
final class PersonnelBudgetMonth {

	/**
	 * The rows: places, contracts, employees, normfuncties, orgUnits, compAdjustments.
	 *
	 * @var array<string, mixed>
	 */
	public array $rows = [];

	/**
	 * The scenario's mutations.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $mutations = [];

	/**
	 * The CAO raise factor.
	 *
	 * @var float
	 */
	public float $raise = 1.0;

	/**
	 * The place.
	 *
	 * @var array<string, mixed>
	 */
	public array $place = [];

	/**
	 * The monthly scale minimum of the place's reference job, or null when not sourced.
	 *
	 * @var float|null
	 */
	public ?float $minimum = null;

	/**
	 * The first of the month being added (Y-m-d).
	 *
	 * @var string
	 */
	public string $day = '';
}//end class
