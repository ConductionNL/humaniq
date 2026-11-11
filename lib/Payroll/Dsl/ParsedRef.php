<?php

/**
 * Humaniq ParsedRef
 *
 * One DSL reference as the resolver parsed it: the original text, its
 * namespace, its resolved path and whether it carries the `:cents` cast.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll\Dsl
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Dsl;

/**
 * A parsed reference.
 *
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
 */
final class ParsedRef {

	/**
	 * The original reference (for errors).
	 *
	 * @var string
	 */
	public string $ref = '';

	/**
	 * The reference namespace.
	 *
	 * @var string
	 */
	public string $namespace = '';

	/**
	 * The resolved path segments.
	 *
	 * @var array<int, string>
	 */
	public array $path = [];

	/**
	 * Whether the `:cents` cast was declared.
	 *
	 * @var bool
	 */
	public bool $cents = false;
}//end class
