<?php

/**
 * Reads Recorded Fields
 *
 * The field readers the payroll check providers share: whether a field
 * holds a non-blank value, whether it holds a number, and whether two
 * percentages agree to the hundredth.
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
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
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Presence and numeric readers over an object's fields.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */
trait ReadsRecordedFields {

	/**
	 * True when an object field holds a non-empty value.
	 *
	 * @param array<string, mixed> $object Object.
	 * @param string $key Field.
	 *
	 * @return bool
	 */
	private static function present(array $object, string $key): bool {
		return isset($object[$key]) === true && trim((string)$object[$key]) !== '';
	}//end present()

	/**
	 * True when an object field holds a present, numeric value.
	 *
	 * @param array<string, mixed> $object Object.
	 * @param string $key Field.
	 *
	 * @return bool
	 */
	private static function numeric(array $object, string $key): bool {
		return isset($object[$key]) === true && $object[$key] !== '' && is_numeric($object[$key]) === true;
	}//end numeric()
	/**
	 * Compare two rate percentages at basis-point precision.
	 *
	 * @param float $first Left rate.
	 * @param float $second Right rate.
	 *
	 * @return bool
	 */
	private static function ratesEqual(float $first, float $second): bool {
		return (int)round($first * 100) === (int)round($second * 100);
	}//end ratesEqual()
}//end trait
