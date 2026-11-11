<?php

/**
 * Humaniq CaoPayBasis
 *
 * What a period's CAO components are computed on: the regular wage, the
 * hourly rate, the paid time entries, the public holidays and the share of
 * the month the contract covers.
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
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The pay a period's CAO components are computed from.
 *
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-003
 */
final class CaoPayBasis {

	/**
	 * The regular wage of the period, in cents.
	 *
	 * @var int
	 */
	public int $regularWageCents = 0;

	/**
	 * The hourly rate, or null when unknown.
	 *
	 * @var float|null
	 */
	public ?float $hourlyRate = null;

	/**
	 * The approved time entries the run pays.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $entries = [];

	/**
	 * Public holidays, `YYYY-MM-DD`.
	 *
	 * @var list<string>
	 */
	public array $nonWorkingDates = [];

	/**
	 * The share of the month the contract covers.
	 *
	 * @var float
	 */
	public float $monthFraction = 1.0;
}//end class
