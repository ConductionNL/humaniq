<?php

/**
 * Humaniq StatutoryFloorHold
 *
 * A request to hold an object until 31 December of its period year plus the
 * statutory retention duration.
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Which field carries the period, how many years the law requires, and the
 * citation recorded on the hold.
 *
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
 */
final class StatutoryFloorHold {

	/**
	 * Constructor.
	 *
	 * @param string $schema       The schema name (for the warning log message only).
	 * @param string $periodField  The period-shaped field to read the year from (e.g. `period`).
	 * @param int    $years        The statutory retention duration in years (e.g. 7 for AWR art. 52 lid 4).
	 * @param string $lawReference A short citation recorded on the hold reason (e.g. "AWR art. 52 lid 4").
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
	 */
	public function __construct(
		public readonly string $schema,
		public readonly string $periodField,
		public readonly int $years,
		public readonly string $lawReference,
	) {

	}//end __construct()
}//end class
