<?php

/**
 * RoundsToCents.
 *
 * Reads a numeric value as integer cents, for the checks and services that compare amounts exactly.
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
 * @spec exclude helper shared by services and checks, no behavioural spec surface.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Support;

/**
 * Reads a numeric value as integer cents.
 */
trait RoundsToCents {
	/**
	 * Convert a euro-denominated value to integer cents (`round($euros *
	 * 100)`, the PayrollMutationService read-time boundary). Non-numeric/null
	 * values convert to 0.
	 *
	 * @param mixed $euros The raw value.
	 *
	 * @return int
	 */
	private static function cents(mixed $euros): int {
		if (is_numeric($euros) === false) {
			return 0;
		}

		return (int)round(((float)$euros) * 100);
	}//end cents()
}//end class
