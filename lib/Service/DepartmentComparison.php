<?php

/**
 * Humaniq DepartmentComparison
 *
 * One side-by-side comparison of units being built: the period and its
 * window, the administration's rows, the compared parent, the unit rows,
 * the total wage cost and each unit's share of it.
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
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One comparison of units being built.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */
final class DepartmentComparison {

	/**
	 * The requested period.
	 *
	 * @var string
	 */
	public string $period = '';

	/**
	 * The window: its months, first and last day.
	 *
	 * @var array{months: list<string>, from: \DateTimeImmutable, to: \DateTimeImmutable}
	 */
	public array $window;

	/**
	 * The administration's rows per schema.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	public array $data = [];

	/**
	 * The unit whose children are compared, '' for the top units.
	 *
	 * @var string
	 */
	public string $parent = '';

	/**
	 * The compared unit rows.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * The administration's total wage cost, null when no month has one.
	 *
	 * @var float|null
	 */
	public ?float $total = null;

	/**
	 * Each compared unit's wage cost, in row order.
	 *
	 * @var list<float|null>
	 */
	public array $costs = [];
}//end class
