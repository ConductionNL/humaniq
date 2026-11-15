<?php

/**
 * Date Values
 *
 * The three lenient readings of a stored date value the humaniq services
 * use: a full parse of any non-empty string, the date part (first ten
 * characters) of a trimmed non-empty string, and the leading Y-m-d of a
 * string that starts with one. Each answers null instead of throwing.
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
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use Exception;

/**
 * Lenient date readings of stored values.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */
final class DateValues {

	/**
	 * Any non-empty string PHP can parse, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	public static function parse(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception) {
			return null;
		}

	}//end parse()

	/**
	 * The first ten characters of a trimmed non-empty string, parsed, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
	 */
	public static function datePart(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable(substr(trim($value), 0, 10));
		} catch (\Throwable $e) {
			return null;
		}

	}//end datePart()

	/**
	 * The leading Y-m-d of a string that starts with one, or null.
	 *
	 * @param string $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-001
	 */
	public static function leadingDate(string $value): ?DateTimeImmutable {
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
			return null;
		}

		try {
			return new DateTimeImmutable(substr($value, 0, 10));
		} catch (Exception) {
			return null;
		}

	}//end leadingDate()
}//end class
