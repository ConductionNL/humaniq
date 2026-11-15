<?php

/**
 * Humaniq PackDeclarationCheck
 *
 * The state of checking one pack's declarations inside PackValidator: the
 * tables its `@table.*` refs resolve against, the ids declared so far, and
 * the label of the declaration being checked.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

/**
 * One pack's declarations being checked.
 *
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-005
 */
final class PackDeclarationCheck {

	/**
	 * The tables the pack's `@table.*` refs resolve against.
	 *
	 * @var TaxTables
	 */
	public TaxTables $tables;

	/**
	 * The ids declared so far, per namespace (input, binding, step).
	 *
	 * @var array<string, array<int, string>>
	 */
	public array $scope = [];

	/**
	 * The label of the declaration being checked, for error messages.
	 *
	 * @var string
	 */
	public string $where = '';
}//end class
