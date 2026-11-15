<?php

/**
 * Humaniq CompScope
 *
 * The scope a compensation cycle matches employees against: its trimmed
 * scope keys, who is placed in its unit, its effective date and the
 * contracts per employee.
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
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-002
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * What one cycle's scope matches on.
 *
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 */
final class CompScope {

	/**
	 * The cycle's scope, trimmed.
	 *
	 * @var array<string, string>
	 */
	public array $keys = [];

	/**
	 * Employees placed in the scope's unit, or null for no unit.
	 *
	 * @var array<string, true>|null
	 */
	public ?array $placed = null;

	/**
	 * The cycle's effective date.
	 *
	 * @var string
	 */
	public string $onDate = '';

	/**
	 * Contracts by employee id.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $contracts = [];
}//end class
