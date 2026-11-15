<?php

/**
 * KeepsReadableRows.
 *
 * Turns the rows an ObjectService read answers into a list of arrays and
 * leaves out what is neither an array nor serialisable.
 *
 * @category Support
 * @package  OCA\Humaniq\Support
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
 * @spec exclude object row plumbing shared by services, no behavioural spec surface.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Support;

/**
 * The readable rows of an ObjectService answer.
 */
trait KeepsReadableRows {
	/**
	 * The arrays and the serialised entities of the answer; anything else is
	 * left out, and an answer that is no list gives an empty list.
	 *
	 * @param mixed $rows The answer.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function normaliseRows(mixed $rows): array {
		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$out[] = (array)$row->jsonSerialize();
			}
		}

		return $out;
	}//end normaliseRows()
}//end class
