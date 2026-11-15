<?php

/**
 * Humaniq PackSelfTestRun
 *
 * The state of running one pack's golden vectors: the pack, its tables,
 * the fixture component map and the position of the vector being run.
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

/**
 * One pack's self-test being run.
 *
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
 */
final class PackSelfTestRun {

	/**
	 * The pack.
	 *
	 * @var JurisdictionPack
	 */
	public JurisdictionPack $pack;

	/**
	 * The tables.
	 *
	 * @var TaxTables
	 */
	public TaxTables $tables;

	/**
	 * The fixture component map.
	 *
	 * @var array<string, string>
	 */
	public array $map = [];

	/**
	 * The position of the vector being run (for errors).
	 *
	 * @var int
	 */
	public int $index = 0;
}//end class
