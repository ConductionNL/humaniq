<?php

/**
 * ICS Text
 *
 * Escaping of RFC 5545 TEXT property values (§3.3.11: backslash, semicolon,
 * comma, newline) for the calendar syncs, which write names, locations and
 * descriptions that are data, not trusted literal text, and its reverse for
 * comparing a stored value back to the desired one.
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
 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * RFC 5545 TEXT escaping.
 *
 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-002
 */
final class IcsText {

	/**
	 * Escape a TEXT property value per RFC 5545 §3.3.11.
	 *
	 * @param string $value The raw text.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-002
	 */
	public static function escape(string $value): string {
		$value = str_replace('\\', '\\\\', $value);
		$value = str_replace([';', ',', "\n"], ['\\;', '\\,', '\\n'], $value);
		return $value;

	}//end escape()

	/**
	 * Reverse escape(), for comparing a stored value back to the desired unescaped one.
	 *
	 * @param string $value The escaped text.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-002
	 */
	public static function unescape(string $value): string {
		$value = str_replace(['\\n', '\\,', '\\;'], ["\n", ',', ';'], $value);
		$value = str_replace('\\\\', '\\', $value);
		return $value;

	}//end unescape()
}//end class
