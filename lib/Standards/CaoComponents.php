<?php

/**
 * The components of a CAO's allowances leaf, in their computable shape.
 *
 * Kept beside CaoRegistry, which loads the corpus: a component is a
 * percentage of wage, a fixed monthly amount or an hourly surcharge with
 * time windows (cao/SCHEMA.md). Only a confirmed leaf (verified, not a
 * placeholder) may be paid from (payroll-cao-components D1).
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards
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
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards;

/**
 * Reads and normalises CAO components.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) CaoRegistry is the corpus's static accessor; every reader of the corpus calls it statically.
 *
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-001
 */
final class CaoComponents {

	/**
	 * The component kinds a CAO allowance can have (cao/SCHEMA.md).
	 *
	 * @var list<string>
	 */
	public const COMPONENT_KINDS = ['percentage-of-wage', 'fixed-monthly', 'hourly-surcharge'];

	/**
	 * The components of a CAO's confirmed allowances leaf, keyed by
	 * component key, or null when the CAO is unknown, the leaf is unverified
	 * or a placeholder, or it holds no component (payroll-cao-components D1).
	 *
	 * @param string $caoId The CAO id.
	 *
	 * @return array<string, array<string, mixed>>|null
	 *
	 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-001
	 */
	public static function confirmed(string $caoId): ?array {
		$leaf = (CaoRegistry::get($caoId)['allowances'] ?? null);
		if (CaoRegistry::isUsableLeaf($leaf) === false) {
			return null;
		}

		$components = self::declared($caoId);
		return ($components === [] ? null : $components);
	}//end confirmed()

	/**
	 * Every component a CAO declares, confirmed or not, normalised: which
	 * keys exist and their kind. Only confirmed() may be paid from.
	 *
	 * @param string $caoId The CAO id.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-001
	 */
	public static function declared(string $caoId): array {
		$value = ((CaoRegistry::get($caoId)['allowances'] ?? [])['value'] ?? []);
		$components = [];
		foreach ((array)$value as $key => $raw) {
			$component = self::normalise(raw: $raw);
			if ($component !== null) {
				$components[(string)$key] = $component;
			}
		}

		return $components;
	}//end declared()

	/**
	 * One component in its computable form, or null when its kind is not one
	 * of the three. Unknown keys (notes, history) are dropped.
	 *
	 * @param mixed $raw The corpus entry or a contract override.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-001
	 */
	public static function normalise(mixed $raw): ?array {
		if (is_array($raw) === false || in_array(($raw['kind'] ?? null), self::COMPONENT_KINDS, true) === false) {
			return null;
		}

		$component = ['kind' => $raw['kind']];
		foreach (['pct', 'holidayPct'] as $field) {
			if (array_key_exists($field, $raw) === true) {
				$component[$field] = (is_numeric($raw[$field]) === true ? (float)$raw[$field] : null);
			}
		}

		foreach (['amountCents', 'minAmountCents'] as $field) {
			if (is_numeric($raw[$field] ?? null) === true) {
				$component[$field] = (int)$raw[$field];
			}
		}

		if (is_array($raw['windows'] ?? null) === true) {
			$component['windows'] = array_values(array_map(static fn (mixed $window): array => self::normaliseWindow(raw: $window), $raw['windows']));
		}

		return $component;
	}//end normalise()

	/**
	 * One surcharge window: days (weekday names, null when not transcribed),
	 * from and to (HH:MM, null when not transcribed; `to` may be 24:00, and
	 * a `from` after `to` crosses midnight into the next day) and pct.
	 *
	 * @param mixed $raw The window.
	 *
	 * @return array{label: string|null, days: list<string>|null, from: string|null, to: string|null, pct: float}
	 */
	private static function normaliseWindow(mixed $raw): array {
		$raw = (array)$raw;
		$days = (is_array($raw['days'] ?? null) === true ? array_values(array_map(static fn (mixed $day): string => strtolower((string)$day), $raw['days'])) : null);
		$time = static fn (mixed $value): ?string => ((is_string($value) === true && preg_match('/^([01]\d|2[0-4]):[0-5]\d$/', $value) === 1) ? $value : null);

		return [
			'label' => (isset($raw['label']) === true ? (string)$raw['label'] : null),
			'days' => $days,
			'from' => $time($raw['from'] ?? null),
			'to' => $time($raw['to'] ?? null),
			'pct' => (float)($raw['pct'] ?? 0),
		];
	}//end normaliseWindow()

}//end class
