<?php

/**
 * Commitment Span
 *
 * When one agenda entry holds a person, and what share of a working day it costs when it is a whole-day entry.
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One agenda entry's start and end, and its share of a working day.
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
 */
final class CommitmentSpan {

	/**
	 * @param int        $start The entry's start timestamp.
	 * @param int        $end   The entry's end timestamp.
	 * @param float|null $share The share of each working day a whole-day entry commits, null for a timed one.
	 */
	public function __construct(
		public readonly int $start,
		public readonly int $end,
		public readonly ?float $share,
	) {

	}//end __construct()

}//end class
