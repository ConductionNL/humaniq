<?php

/**
 * Humaniq PayTransparencyYear
 *
 * The rows of one administration for one pay transparency year inside
 * PayTransparencyService: the rows, the year, the pay category of each
 * normfunctie and the year's payslips per employee.
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
 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One administration's year for the pay transparency report.
 *
 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
 */
final class PayTransparencyYear {

	/**
	 * The administration's rows.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $rows = [];

	/**
	 * The year.
	 *
	 * @var int
	 */
	public int $year = 0;

	/**
	 * The pay category of each normfunctie, by id.
	 *
	 * @var array<string, string>
	 */
	public array $categoryOf = [];

	/**
	 * The year's payslips, per employee.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	public array $slipsOf = [];
}//end class
