<?php

/**
 * Payroll Date
 *
 * Reads the Y-m-d date the payroll rows carry: the first ten characters of a
 * trimmed value, or nothing when they are not a Y-m-d date.
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
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-101
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

use DateTimeImmutable;

/**
 * Lenient Y-m-d date reading for payroll rows.
 *
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-101
 */
final class PayrollDate {

	/**
	 * A Y-m-d date (the first ten characters), or null.
	 *
	 * @param string $value The value.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-101
	 */
	public static function fromYmd(string $value): ?DateTimeImmutable {
		$value = substr(trim($value), 0, 10);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception) {
			return null;
		}

	}//end fromYmd()
}//end class
