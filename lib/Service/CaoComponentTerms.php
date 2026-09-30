<?php

/**
 * Which CAO components a contract gets (payroll-cao-components D2).
 *
 * A contract names the components of its agreement that apply to it and may
 * improve a figure with a reason, never below the agreement: the rule
 * EmploymentTermsResolver applies to overtime.
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
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use InvalidArgumentException;
use OCA\Humaniq\Standards\CaoComponents;

/**
 * Resolves a contract's CAO components with its overrides.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) CaoComponents reads the static CAO corpus, the EmploymentTermsResolver precedent.
 *
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-002
 */
class CaoComponentTerms {

	/**
	 * The CAO components a contract names, resolved (payroll-cao-components D2).
	 *
	 * Each key in `caoComponents` resolves from the agreement's confirmed
	 * allowances leaf (source `cao`) or from `caoComponentOverrides` (source
	 * `contract-override`), which needs `caoComponentOverrideReason` and may
	 * not be below the agreement's figure. A key whose agreement leaf is not
	 * confirmed and that has no override is unresolved: never paid, never
	 * guessed. A key the agreement does not declare is unknown and
	 * unresolved.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract as an array.
	 *
	 * @return array{components: list<array<string, mixed>>, unresolved: list<string>, unknown: list<string>}
	 *
	 * @throws InvalidArgumentException When an override has no reason or is below the agreement.
	 *
	 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-002
	 */
	public function resolve(array $contract): array {
		$resolved = ['components' => [], 'unresolved' => [], 'unknown' => []];
		$keys = self::namedKeys(contract: $contract);
		if ($keys === []) {
			return $resolved;
		}

		$caoId = trim((string)($contract['cao'] ?? ''));
		$declared = CaoComponents::declared($caoId);
		$confirmed = (CaoComponents::confirmed($caoId) ?? []);
		$overrides = $this->componentOverrides(contract: $contract, keys: $keys);
		foreach ($keys as $key) {
			if (isset($declared[$key]) === false) {
				$resolved['unknown'][] = $key;
				$resolved['unresolved'][] = $key;
				continue;
			}

			$component = $this->resolveKey(key: $key, declared: $declared[$key], collective: ($confirmed[$key] ?? null), override: ($overrides[$key] ?? null));
			if ($component === null) {
				$resolved['unresolved'][] = $key;
				continue;
			}

			$resolved['components'][] = $component;
		}

		return $resolved;
	}//end resolve()

	/**
	 * One component: the override on top of the agreement (never below it),
	 * the agreement's confirmed figures, or null when neither applies.
	 *
	 * @param string                    $key        The component.
	 * @param array<string, mixed>      $declared   What the agreement declares.
	 * @param array<string, mixed>|null $collective The agreement's confirmed figures.
	 * @param array<string, mixed>|null $override   The contract's figures.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws InvalidArgumentException When the override is below the agreement.
	 */
	private function resolveKey(string $key, array $declared, ?array $collective, ?array $override): ?array {
		if ($override !== null) {
			$figures = array_merge(($collective ?? $declared), $override, ['kind' => $declared['kind']]);
			if ($collective !== null) {
				$this->assertComponentNotWorse(key: $key, override: $figures, collective: $collective);
			}

			return array_merge(['key' => $key], $figures, ['source' => EmploymentTermsResolver::SOURCE_CONTRACT]);
		}

		if ($collective === null) {
			return null;
		}

		return array_merge(['key' => $key], $collective, ['source' => EmploymentTermsResolver::SOURCE_CAO]);
	}//end resolveKey()

	/**
	 * The component keys the contract names, without blanks or repeats.
	 *
	 * @param array<string, mixed> $contract The contract.
	 *
	 * @return list<string>
	 */
	private static function namedKeys(array $contract): array {
		$keys = array_map('strval', (array)($contract['caoComponents'] ?? []));
		return array_values(array_unique(array_filter($keys, static fn (string $key): bool => trim($key) !== '')));
	}//end namedKeys()

	/**
	 * The contract's component overrides for the keys it names, normalised;
	 * any override needs the reason.
	 *
	 * @param array<string, mixed> $contract The contract.
	 * @param list<string>         $keys     The keys the contract names.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @throws InvalidArgumentException When an override has no reason.
	 */
	private function componentOverrides(array $contract, array $keys): array {
		$overrides = [];
		foreach ((array)($contract['caoComponentOverrides'] ?? []) as $key => $raw) {
			if (in_array((string)$key, $keys, true) === false || is_array($raw) === false || $raw === []) {
				continue;
			}

			$normalised = CaoComponents::normalise(raw: array_merge($raw, ['kind' => 'hourly-surcharge']));
			unset($normalised['kind']);
			$overrides[(string)$key] = (array)$normalised;
		}

		if ($overrides !== [] && trim((string)($contract['caoComponentOverrideReason'] ?? '')) === '') {
			throw new InvalidArgumentException('caoComponentOverrides is set without caoComponentOverrideReason; a contract departing from its CAO must give a reason');
		}

		return $overrides;
	}//end componentOverrides()

	/**
	 * Refuse a component override below the agreement: a lower percentage or
	 * amount, a lower holiday percentage, or an agreement window missing or
	 * paid lower.
	 *
	 * @param string               $key        The component.
	 * @param array<string, mixed> $override   The figures with the override applied.
	 * @param array<string, mixed> $collective The agreement's figures.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the override is below the agreement.
	 */
	private function assertComponentNotWorse(string $key, array $override, array $collective): void {
		foreach (['pct', 'amountCents', 'minAmountCents', 'holidayPct'] as $field) {
			if (isset($collective[$field]) === true && (float)($override[$field] ?? 0) < (float)$collective[$field]) {
				throw new InvalidArgumentException('the override of "' . $key . '" sets ' . $field . ' ' . ($override[$field] ?? 'nothing') . ', below the ' . $collective[$field] . ' the collective labour agreement pays; an individual contract may improve on collective terms, never undercut them');
			}
		}

		$this->assertWindowsNotWorse(key: $key, override: $override, collective: $collective);
	}//end assertComponentNotWorse()

	/**
	 * Refuse an override that leaves out an agreement window or pays it lower.
	 *
	 * @param string               $key        The component.
	 * @param array<string, mixed> $override   The figures with the override applied.
	 * @param array<string, mixed> $collective The agreement's figures.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a window is missing or lower.
	 */
	private function assertWindowsNotWorse(string $key, array $override, array $collective): void {
		foreach ((array)($collective['windows'] ?? []) as $window) {
			$best = null;
			foreach ((array)($override['windows'] ?? []) as $candidate) {
				if ($candidate['days'] === $window['days'] && $candidate['from'] === $window['from'] && $candidate['to'] === $window['to']) {
					$best = max(($best ?? 0.0), $candidate['pct']);
				}
			}

			if ($best === null || $best < $window['pct']) {
				throw new InvalidArgumentException('the override of "' . $key . '" pays the ' . ($window['from'] ?? '?') . '-' . ($window['to'] ?? '?') . ' window below the ' . $window['pct'] . '% the collective labour agreement pays, or leaves it out; an individual contract may improve on collective terms, never undercut them');
			}
		}
	}//end assertWindowsNotWorse()

}//end class
