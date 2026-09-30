<?php

/**
 * CAO Registry
 *
 * Loads the versioned CAO (collectieve arbeidsovereenkomst) corpus
 * (`lib/Standards/cao/{cao-id}.json`, cao-library design.md D1/D2) and exposes it
 * as typed resolvers for `NlCaoChecks` and the read-only `Cao` reference seed.
 * Mirrors `TaxTables` (one-versioned-file loader) and `RuleCatalogue` (glob +
 * merge a directory of `*.json`, memoised) — here, both at once: a directory of
 * per-CAO files, merged and memoised.
 *
 * Pure PHP, zero Nextcloud dependencies: file IO only, no container, no clock.
 *
 * `minMaandloonCents()` / `minLeaveHours()` resolve to `null` — never a wrong
 * number — whenever the underlying leaf is unknown, or `verified: false` /
 * `placeholder: true` (cao/SCHEMA.md). This is the single lever that keeps the
 * two below-CAO-minimum checks honest under RuleEngine's one-severity-per-rule
 * constraint (design.md D5): a placeholder figure resolves to null, and
 * `NlCaoChecks` treats null as a vacuous pass.
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
 * @spec openspec/specs/cao-library/spec.md#REQ-CAO-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards;

/**
 * Read-only accessor over the per-CAO JSON corpus files.
 */
final class CaoRegistry {

	/**
	 * Registry version — bump on any change to a `cao/*.json` file.
	 *
	 * @var string
	 */
	public const VERSION = '2026-09.20';

	/**
	 * Required top-level keys on every well-formed CAO file.
	 *
	 * @var string[]
	 */
	private const REQUIRED_KEYS = [
		'id',
		'name',
		'sector',
		'version',
		'effectiveDate',
		'payScales',
		'allowances',
		'leaveEntitlement',
		'workingTime',
	];

	/**
	 * The four leaf-group keys every well-formed CAO file must carry, each a
	 * `{value, source, verified}` leaf (cao/SCHEMA.md).
	 *
	 * @var string[]
	 */
	private const LEAF_GROUPS = [
		'payScales',
		'allowances',
		'leaveEntitlement',
		'workingTime',
	];

	/**
	 * Loaded + merged CAOs, keyed by id, memoised.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $cache = null;

	/**
	 * Every CAO id in the corpus, mapped to its display summary
	 * (`{name, sector, version, effectiveDate}`) — drives the `Cao` seed
	 * (`NlCaoChecks::seedObjects()`) and the `Caos` reference page.
	 *
	 * @return array<string, array{name: string, sector: string, version: string, effectiveDate: string}>
	 *
	 * @spec openspec/specs/cao-library/spec.md#REQ-CAO-001
	 */
	public static function availableCaos(): array {
		$out = [];
		foreach (self::all() as $id => $cao) {
			$out[$id] = [
				'name' => (string)($cao['name'] ?? $id),
				'sector' => (string)($cao['sector'] ?? ''),
				'version' => (string)($cao['version'] ?? ''),
				'effectiveDate' => (string)($cao['effectiveDate'] ?? ''),
			];
		}

		return $out;
	}//end availableCaos()

	/**
	 * The full decoded CAO record for `$caoId`, or null when unknown.
	 *
	 * @param string $caoId The CAO id (e.g. `cao-metaal-techniek`).
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/cao-library/spec.md#REQ-CAO-001
	 */
	public static function get(string $caoId): ?array {
		return (self::all()[$caoId] ?? null);
	}//end get()

	/**
	 * The minimum maandloon (integer cents) for `$schaal` within `$caoId`, or
	 * `null` when the CAO/scale is unknown OR the CAO's `payScales` leaf is
	 * `verified: false` / `placeholder: true` (design.md D5 — a placeholder
	 * figure must never raise a false mandatory violation).
	 *
	 * @param string $caoId The CAO id.
	 * @param string $schaal The pay scale within that CAO.
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/cao-library/spec.md#REQ-CAO-001
	 * @spec openspec/specs/cao-library/spec.md#REQ-CAO-003
	 */
	public static function minMaandloonCents(string $caoId, string $schaal): ?int {
		$cao = self::get($caoId);
		if ($cao === null) {
			return null;
		}

		$leaf = ($cao['payScales'] ?? null);
		if (self::isUsableLeaf($leaf) === false) {
			return null;
		}

		$scales = (array)$leaf['value'];
		if (array_key_exists($schaal, $scales) === false || is_numeric($scales[$schaal]) === false) {
			return null;
		}

		return (int)round((float)$scales[$schaal]);
	}//end minMaandloonCents()

	/**
	 * The total statutory + bovenwettelijk annual leave, in hours, for a
	 * contract working `$contractHoursPerWeek` hours a week under `$caoId`, or
	 * `null` when the CAO is unknown OR its `leaveEntitlement` leaf is
	 * `verified: false` / `placeholder: true` (design.md D5).
	 *
	 * The CAO's `vakantiedagenWettelijk` + `vakantiedagenBovenwettelijk` day
	 * counts are full-time reference counts; this resolver prorates them to
	 * the contract's actual weekly hours assuming a 5-day working week
	 * (documented limitation, design.md Risks — per-trede/part-time nuance
	 * beyond this is a named fast-follow), independent of the CAO's own
	 * `workingTime.fulltimeHoursPerWeek` (display-only, never read here).
	 *
	 * @param string $caoId The CAO id.
	 * @param float $contractHoursPerWeek Contracted hours per week.
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/cao-library/spec.md#REQ-CAO-001
	 * @spec openspec/specs/cao-library/spec.md#REQ-CAO-004
	 */
	public static function minLeaveHours(string $caoId, float $contractHoursPerWeek): ?int {
		if ($contractHoursPerWeek <= 0.0) {
			return null;
		}

		$cao = self::get($caoId);
		if ($cao === null) {
			return null;
		}

		$leaf = ($cao['leaveEntitlement'] ?? null);
		if (self::isUsableLeaf($leaf) === false) {
			return null;
		}

		$value = (array)$leaf['value'];
		$wettelijk = (float)($value['vakantiedagenWettelijk'] ?? 0);
		$bovenwettelijk = (float)($value['vakantiedagenBovenwettelijk'] ?? 0);
		$totalDays = ($wettelijk + $bovenwettelijk);

		$hoursPerDay = ($contractHoursPerWeek / 5.0);

		return (int)round($totalDays * $hoursPerDay);
	}//end minLeaveHours()

	/**
	 * Resolve a CAO's overwerktoeslag percentages — the extra percentage paid
	 * on top of the normal hourly wage per overtime category (`doordeweeks`,
	 * `zaterdag`, `zondag`, `feestdag`).
	 *
	 * Percentages are the SURCHARGE, not the total: `50` means 150% of the
	 * normal wage is paid for that hour. Stated because the two readings
	 * differ by the whole base wage and both are common in CAO texts.
	 *
	 * Honours the same verified/placeholder lever as every other resolver: an
	 * unconfirmed leaf resolves to `null`, never to a wrong number, so a CAO
	 * whose overtime article has not been transcribed yields no uplift rather
	 * than an invented one.
	 *
	 * @param string $caoId The CAO id (e.g. `cao-gemeenten`).
	 *
	 * @return array<string, float>|null Category to surcharge percentage, or null when unresolvable.
	 */
	public static function overtimeToeslagPercentages(string $caoId): ?array {
		$cao = self::get($caoId);
		if ($cao === null) {
			return null;
		}

		$leaf = ($cao['overtime'] ?? null);
		if (self::isUsableLeaf($leaf) === false) {
			return null;
		}

		$percentages = ((array)$leaf['value'])['toeslagPercentages'] ?? null;
		if (is_array($percentages) === false || $percentages === []) {
			return null;
		}

		$out = [];
		foreach ($percentages as $category => $percentage) {
			if (is_numeric($percentage) === false) {
				continue;
			}

			$out[(string)$category] = (float)$percentage;
		}

		if ($out === []) {
			return null;
		}

		return $out;
	}//end overtimeToeslagPercentages()

	/**
	 * The CAO's default settlement of overtime, `tijd-voor-tijd` or another
	 * value, from a confirmed overtime leaf only; null otherwise
	 * (time-hours-and-overtime-to-payroll D3).
	 *
	 * @param string $caoId The CAO id.
	 *
	 * @return string|null The preference, or null when unresolvable.
	 *
	 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
	 */
	public static function overtimeCompensationPreference(string $caoId): ?string {
		$leaf = (self::get($caoId)['overtime'] ?? null);
		if (self::isUsableLeaf($leaf) === false) {
			return null;
		}

		$preference = trim((string)(((array)$leaf['value'])['compensationPreference'] ?? ''));
		return ($preference === '' ? null : $preference);
	}//end overtimeCompensationPreference()

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
	public static function components(string $caoId): ?array {
		$leaf = (self::get($caoId)['allowances'] ?? null);
		if (self::isUsableLeaf($leaf) === false) {
			return null;
		}

		$components = self::componentShapes($caoId);
		return ($components === [] ? null : $components);
	}//end components()

	/**
	 * Every component a CAO declares, confirmed or not, normalised: which
	 * keys exist and their kind. Only components() may be paid from.
	 *
	 * @param string $caoId The CAO id.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-001
	 */
	public static function componentShapes(string $caoId): array {
		$value = ((self::get($caoId)['allowances'] ?? [])['value'] ?? []);
		$components = [];
		foreach ((array)$value as $key => $raw) {
			$component = self::normaliseComponent(raw: $raw);
			if ($component !== null) {
				$components[(string)$key] = $component;
			}
		}

		return $components;
	}//end componentShapes()

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
	public static function normaliseComponent(mixed $raw): ?array {
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
	}//end normaliseComponent()

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

	/**
	 * Reset the memoised cache (test hook).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$cache = null;

	}//end reset()

	/**
	 * All CAOs from every `cao/*.json` file, keyed by id, memoised. Malformed
	 * files (missing a required top-level key, or a leaf group not shaped
	 * `{value, source, verified}`) are skipped defensively — mirrors
	 * `RuleCatalogue::all()`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function all(): array {
		if (self::$cache !== null) {
			return self::$cache;
		}

		$caos = [];
		foreach ((glob(self::caoDir() . '/*.json') ?: []) as $file) {
			$decoded = json_decode((string)file_get_contents($file), true);
			if (self::isWellFormed($decoded) === false) {
				continue;
			}

			$caos[(string)$decoded['id']] = $decoded;
		}

		self::$cache = $caos;
		return $caos;
	}//end all()

	/**
	 * Whether a leaf is present and usable for hard enforcement: an array
	 * carrying `value`, with `verified === true` and `placeholder` not
	 * `true`. A leaf that fails this is unknown/unverified/placeholder — the
	 * resolvers return `null` for it (design.md D5).
	 *
	 * @param mixed $leaf The candidate leaf.
	 *
	 * @return bool
	 */
	private static function isUsableLeaf(mixed $leaf): bool {
		if (is_array($leaf) === false || array_key_exists('value', $leaf) === false) {
			return false;
		}

		if (($leaf['verified'] ?? false) !== true) {
			return false;
		}

		return ($leaf['placeholder'] ?? false) !== true;
	}//end isUsableLeaf()

	/**
	 * Whether a decoded CAO file has every required top-level key and every
	 * leaf group is shaped `{value, source, verified}` (cao/SCHEMA.md).
	 *
	 * @param mixed $decoded Decoded JSON.
	 *
	 * @return bool
	 */
	private static function isWellFormed(mixed $decoded): bool {
		if (is_array($decoded) === false) {
			return false;
		}

		foreach (self::REQUIRED_KEYS as $key) {
			if (array_key_exists($key, $decoded) === false) {
				return false;
			}
		}

		if (is_string($decoded['id']) === false || trim($decoded['id']) === '') {
			return false;
		}

		foreach (self::LEAF_GROUPS as $group) {
			$leaf = $decoded[$group];
			if (is_array($leaf) === false
				|| array_key_exists('value', $leaf) === false
				|| array_key_exists('source', $leaf) === false
				|| array_key_exists('verified', $leaf) === false
				|| is_bool($leaf['verified']) === false
			) {
				return false;
			}
		}

		return true;
	}//end isWellFormed()

	/**
	 * The absolute path to `lib/Standards/cao/`.
	 *
	 * @return string
	 */
	private static function caoDir(): string {
		return __DIR__ . '/cao';
	}//end caoDir()

}//end class
