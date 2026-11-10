<?php

/**
 * Behaviour fingerprint of a check provider's predicates.
 *
 * Evaluates every predicate of a provider against a fixed, generated grid of
 * objects (each seed sample, an empty object, and every single-field variation
 * of those over a fixed value list) and reduces the outcomes to one hash per
 * rule. A characterisation test stores the hashes measured on the code before
 * a refactor, so any change in an allowed or refused outcome turns it red.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
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
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

/**
 * Builds the object grid and hashes each predicate's outcomes over it.
 */
final class PredicateFingerprint {

	/**
	 * Marker for "the field is absent" in the value list.
	 *
	 * @var string
	 */
	private const ABSENT = "\0absent";

	/**
	 * The values every field is set to in turn.
	 *
	 * @var list<mixed>
	 */
	private const VALUES = [
		self::ABSENT, null, true, false, 0, -1, 0.5, 4.85, 5, 6.10, 8.0, 9, 10, 14.71, 20, 24, 30.0, 31.0,
		52.0, 60, 80.0, 500.0, 600.0, 5000.0, 9000.0, 100000.0, 200000.0, 'x', '', '12.5',
		'2000-01-01', '2026-01-31', '2026-02-15', '2099-12-31', 'month', 'quarter', 'year', 'week',
		'semiweekly', 'monthly', 'posted', 'paid', 'draft', 'low', 'high', 'permanent', 'bbl',
		'minijob', 'agency', 'werkgeversheffing', 'inhouding', 'lohnsteuer-anmeldung',
		'lohnsteuerbescheinigung', 'deuv-meldung', 'beitragsnachweis', 'dsn', 'form-941', 'deposit',
		'form-940', 'form-w2', 'wit', 'groen',
	];

	/**
	 * The grid of objects for one object type.
	 *
	 * @param list<array<string, mixed>> $seeds  The provider's seed samples of the type.
	 * @param list<string>               $fields The fields the predicates read.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function grid(array $seeds, array $fields): array {
		$grid = [];
		foreach (array_merge($seeds, [[]]) as $base) {
			$grid[] = $base;
			foreach ($fields as $field) {
				foreach (self::VALUES as $value) {
					$grid[] = self::withValue($base, $field, $value);
				}
			}
		}

		return $grid;

	}//end grid()

	/**
	 * The seeds plus each seed with one selector field set to each of its values.
	 *
	 * @param list<array<string, mixed>> $seeds     The seed samples.
	 * @param array<string, list<mixed>> $selectors Field => the values that select a branch.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function rebase(array $seeds, array $selectors): array {
		$bases = $seeds;
		foreach (array_merge($seeds, [[]]) as $seed) {
			foreach ($selectors as $field => $values) {
				foreach ($values as $value) {
					$bases[] = self::withValue($seed, $field, $value);
				}
			}
		}

		return $bases;

	}//end rebase()

	/**
	 * A hash of one predicate's outcome on every object of a grid.
	 *
	 * @param callable                   $predicate The predicate.
	 * @param list<array<string, mixed>> $grid      The objects.
	 *
	 * @return string
	 */
	public static function of(callable $predicate, array $grid): string {
		$outcomes = '';
		foreach ($grid as $object) {
			try {
				$outcomes .= ((bool)$predicate($object, [])) ? '1' : '0';
			} catch (\Throwable $e) {
				$outcomes .= 'E';
			}
		}

		return substr_count($outcomes, '1').':'.md5($outcomes);

	}//end of()

	/**
	 * The object with one field set to a value (or removed).
	 *
	 * @param array<string, mixed> $object The object.
	 * @param string               $field  The field.
	 * @param mixed                $value  The value, or the absent marker.
	 *
	 * @return array<string, mixed>
	 */
	private static function withValue(array $object, string $field, mixed $value): array {
		if ($value === self::ABSENT) {
			unset($object[$field]);
			return $object;
		}

		$object[$field] = $value;
		return $object;

	}//end withValue()
}//end class
