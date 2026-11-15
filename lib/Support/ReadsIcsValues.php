<?php

/**
 * ReadsIcsValues.
 *
 * Reads one property value from an ICS document.
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
 * Reads one property value from an ICS document.
 */
trait ReadsIcsValues {
	/**
	 * Extract a single property's value from a raw ICS text
	 * (`PROPERTY[;PARAMS]:VALUE`), tolerant of `;` parameters and both CRLF
	 * and LF line endings. Returns null when the property is absent.
	 *
	 * @param string $ics The raw ICS text.
	 * @param string $property The property name (e.g. `DTSTART`).
	 *
	 * @return string|null
	 */
	private function extractIcsValue(string $ics, string $property): ?string {
		$lines = preg_split('/\r\n|\n|\r/', $ics) ?: [];
		foreach ($lines as $line) {
			if (str_starts_with($line, $property . ':') === true) {
				return substr($line, strlen($property) + 1);
			}

			if (str_starts_with($line, $property . ';') === true) {
				$colonPos = strpos($line, ':');
				if ($colonPos !== false) {
					return substr($line, $colonPos + 1);
				}
			}
		}

		return null;
	}//end extractIcsValue()
}//end class
