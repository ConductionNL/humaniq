<?php

/**
 * Humaniq HireMatchRun
 *
 * One look for former or current employees matching a person: the keys
 * wanted, the employees read and the matches found so far.
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
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One look for employees matching a person.
 *
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
 */
final class HireMatchRun {

	/**
	 * The normalised value per match key, '' when the person has none.
	 *
	 * @var array<string, string>
	 */
	public array $wanted = [];

	/**
	 * Every employee.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $employees = [];

	/**
	 * The matches found, by employee id, in the order found.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $found = [];
}//end class
