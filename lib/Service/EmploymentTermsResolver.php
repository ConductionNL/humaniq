<?php

/**
 * Employment Terms Resolver
 *
 * Answers "what terms actually apply to THIS contract" for the terms that are
 * normally collective and occasionally individual: overwerktoeslag and
 * vakantiedagen.
 *
 * Terms are company-wide by default. A CAO is a collective agreement — it is
 * the norm, and an individual contract departing from it is the exception. So
 * resolution is **inherit first, override second**:
 *
 *   1. the contract's own value, when it carries one;
 *   2. otherwise the CAO the contract names, via {@see CaoRegistry};
 *   3. otherwise nothing — `null`, never a fabricated default.
 *
 * Every resolution reports WHERE it came from. A term that cannot say whether
 * it is the collective norm or a negotiated exception is unusable in a
 * conversation with an employee, a works council or an auditor, and the
 * provenance is exactly what someone asks for first.
 *
 * Two deliberate rules:
 *
 * - **An override wins IN FULL; it is never merged per category.** Taking
 *   `zondag` from the contract and `zaterdag` from the CAO would produce a set
 *   of terms that exists in neither document and that nobody agreed to.
 * - **An override MUST carry a reason.** Departing from a collective agreement
 *   is something a person decided and a person must be able to justify; an
 *   unexplained override is indistinguishable from a data-entry error, and it
 *   feeds a cost figure that reaches a statutory submission.
 *
 * Resolving to `null` is a first-class outcome, not a failure. The CAO corpus
 * marks unconfirmed figures `verified: false` / `placeholder: true` and
 * `CaoRegistry` deliberately resolves those to null, so a CAO whose overtime
 * article has not been transcribed yields no uplift rather than an invented
 * one. This service preserves that: no terms means no overtime addition, which
 * is a visible gap rather than a plausible wrong number.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use InvalidArgumentException;
use OCA\Humaniq\Standards\CaoRegistry;

/**
 * Resolves CAO-inherited employment terms with contract-level overrides.
 */
class EmploymentTermsResolver {

	/**
	 * The term came from the contract itself, departing from the CAO.
	 *
	 * @var string
	 */
	public const SOURCE_CONTRACT = 'contract-override';

	/**
	 * The term was inherited from the CAO the contract names.
	 *
	 * @var string
	 */
	public const SOURCE_CAO = 'cao';

	/**
	 * Overtime categories, in the order a UI should present them.
	 *
	 * @var string[]
	 */
	public const OVERTIME_CATEGORIES = [
		'doordeweeks',
		'zaterdag',
		'zondag',
		'feestdag',
	];

	/**
	 * Statutory full-time vakantiedagen floor. BW art. 7:634 states the
	 * entitlement as 4 x the contractual weekly working time; at the 5-day
	 * week the corpus's day counts assume, that is 20 days. Expressed in days
	 * here because the CLA corpus and the contract override both speak days —
	 * `nl-verlof-wettelijk-minimum` evaluates the hours form independently.
	 *
	 * @var float
	 */
	public const STATUTORY_LEAVE_DAYS_FULLTIME = 20.0;

	/**
	 * Resolve the overwerktoeslag percentages that apply to one contract.
	 *
	 * Percentages are the SURCHARGE, not the total: `50` means 150% of the
	 * normal hourly wage is paid for that hour.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract as an array.
	 *
	 * @return array{percentages: array<string, float>, source: string, basis: string, caoId: string|null}|null
	 *                                                                                                          Null when neither the contract nor its CAO yields usable terms.
	 *
	 * @throws InvalidArgumentException When an override carries no reason.
	 */
	public function resolveOvertimeToeslag(array $contract): ?array {
		$override = $this->normalisePercentages(raw: ($contract['overtimeToeslagPercentages'] ?? null));
		if ($override !== null) {
			$reason = trim((string)($contract['overtimeTermsOverrideReason'] ?? ''));
			if ($reason === '') {
				throw new InvalidArgumentException(
					'overtimeToeslagPercentages is set without overtimeTermsOverrideReason; '
					. 'a contract departing from its CAO must say why'
				);
			}

			$caoId = $this->caoId(contract: $contract);
			$this->assertOvertimeNotWorse(
				override: $override,
				collective: ($caoId === null ? null : CaoRegistry::overtimeToeslagPercentages($caoId))
			);

			return [
				'percentages' => $override,
				'source' => self::SOURCE_CONTRACT,
				'basis' => $reason,
				'caoId' => $caoId,
			];
		}//end if

		$caoId = $this->caoId(contract: $contract);
		if ($caoId === null) {
			return null;
		}

		$percentages = CaoRegistry::overtimeToeslagPercentages($caoId);
		if ($percentages === null) {
			return null;
		}

		return [
			'percentages' => $percentages,
			'source' => self::SOURCE_CAO,
			'basis' => $caoId,
			'caoId' => $caoId,
		];

	}//end resolveOvertimeToeslag()

	/**
	 * Resolve the full-time vakantiedagen entitlement for one contract.
	 *
	 * Statutory minimums are NOT applied here — `nl-verlof-wettelijk-minimum`
	 * evaluates the resulting entitlement, so an override below the wettelijk
	 * minimum surfaces as a violation rather than being silently corrected
	 * into compliance by this resolver.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract as an array.
	 *
	 * @return array{days: array<string, float>, source: string, basis: string, caoId: string|null}|null
	 *                                                                                                   Null when neither the contract nor its CAO yields usable terms.
	 *
	 * @throws InvalidArgumentException When an override carries no reason.
	 */
	public function resolveLeaveEntitlementDays(array $contract): ?array {
		$override = $this->normaliseLeaveDays(raw: ($contract['leaveEntitlementOverrideDays'] ?? null));
		if ($override !== null) {
			$reason = trim((string)($contract['leaveTermsOverrideReason'] ?? ''));
			if ($reason === '') {
				throw new InvalidArgumentException(
					'leaveEntitlementOverrideDays is set without leaveTermsOverrideReason; '
					. 'a contract departing from its CAO must say why'
				);
			}

			$caoId = $this->caoId(contract: $contract);
			$this->assertLeaveNotWorse(override: $override, caoId: $caoId);

			return [
				'days' => $override,
				'source' => self::SOURCE_CONTRACT,
				'basis' => $reason,
				'caoId' => $caoId,
			];
		}//end if

		$caoId = $this->caoId(contract: $contract);
		if ($caoId === null) {
			return null;
		}

		$cao = CaoRegistry::get($caoId);
		$leaf = ($cao['leaveEntitlement'] ?? null);
		// Re-uses CaoRegistry's own usability lever indirectly: minLeaveHours
		// returns null for an unverified/placeholder leaf, so asking it with a
		// nominal week tells us whether the leaf may be read at all without
		// duplicating the verified/placeholder logic here.
		if (CaoRegistry::minLeaveHours($caoId, 40.0) === null || is_array($leaf) === false) {
			return null;
		}

		$value = (array)($leaf['value'] ?? []);
		$days = [
			'vakantiedagenWettelijk' => (float)($value['vakantiedagenWettelijk'] ?? 0),
			'vakantiedagenBovenwettelijk' => (float)($value['vakantiedagenBovenwettelijk'] ?? 0),
		];

		return [
			'days' => $days,
			'source' => self::SOURCE_CAO,
			'basis' => $caoId,
			'caoId' => $caoId,
		];

	}//end resolveLeaveEntitlementDays()

	/**
	 * Project a contract's overtime terms into the cost ADDITION shape
	 * {@see EmployeeCostRateService} accepts, for one overtime category.
	 *
	 * This is the join between the two halves: the terms say "Sunday overtime
	 * carries a 100% surcharge", the cost rate needs "+100% of the wage base
	 * on that hour". Expressed as a PERCENTAGE addition rather than a
	 * pre-computed cents figure, so it resolves against whichever wage base
	 * applies to the employee rather than against one captured here.
	 *
	 * Returns null when no terms resolve — a CLA whose overtime article has
	 * not been transcribed yields no uplift rather than an invented one, and
	 * an absent addition is a visible gap where a zero would look like an
	 * answer.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract as an array.
	 * @param string $category Overtime category (see OVERTIME_CATEGORIES).
	 *
	 * @return array{key: string, percentageOfWage: float, source: string, basis: string}|null
	 *
	 * @throws InvalidArgumentException When an override carries no reason or is less favourable.
	 */
	public function overtimeAdditionFor(array $contract, string $category): ?array {
		$terms = $this->resolveOvertimeToeslag(contract: $contract);
		if ($terms === null) {
			return null;
		}

		$percentage = ($terms['percentages'][$category] ?? null);
		if ($percentage === null) {
			return null;
		}

		return [
			'key' => EmployeeCostRateService::ADDITION_OVERTIME,
			'percentageOfWage' => (float)$percentage,
			'source' => $terms['source'],
			'basis' => 'Overwerktoeslag ' . $category . ' ' . $percentage . '% — ' . $terms['basis'],
		];

	}//end overtimeAdditionFor()

	/**
	 * Refuse an overtime override that is LESS favourable than the collective
	 * terms it departs from.
	 *
	 * A collective labour agreement is a floor, not a menu: an individual
	 * contract may improve on it, never undercut it. Because an override wins
	 * in full rather than merging, this check also catches the quieter version
	 * of the same thing — an override that silently OMITS a category the CLA
	 * pays a surcharge for, which removes that surcharge entirely.
	 *
	 * @param array<string, float> $override The contract's percentages.
	 * @param array<string, float>|null $collective The CLA's percentages, when resolvable.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When any category is absent or lower.
	 */
	private function assertOvertimeNotWorse(array $override, ?array $collective): void {
		if ($collective === null) {
			return;
		}

		foreach ($collective as $category => $collectivePercentage) {
			if (array_key_exists($category, $override) === false) {
				throw new InvalidArgumentException(
					'the overtime override omits "' . $category . '", which the collective labour agreement '
					. 'pays ' . $collectivePercentage . '% for; an individual contract may improve on '
					. 'collective terms, never drop them'
				);
			}

			if ($override[$category] < $collectivePercentage) {
				throw new InvalidArgumentException(
					'the overtime override pays ' . $override[$category] . '% for "' . $category . '" where the '
					. 'collective labour agreement pays ' . $collectivePercentage . '%; an individual contract '
					. 'may improve on collective terms, never undercut them'
				);
			}
		}//end foreach

	}//end assertOvertimeNotWorse()

	/**
	 * Refuse a leave override that is LESS favourable than the collective
	 * terms or than the statutory floor.
	 *
	 * Two floors apply and the stricter one wins: BW art. 7:634's statutory
	 * minimum, which no agreement of any kind may go under, and the CLA's own
	 * entitlement where it is resolvable and higher.
	 *
	 * This REPLACES an earlier reading in which a below-minimum override was
	 * stored as given and left for `nl-verlof-wettelijk-minimum` to flag. That
	 * treated an unlawful term as data to be reported on; refusing the write
	 * means it never becomes a term of employment in the first place, which is
	 * what "may not be worse than the law" actually requires.
	 *
	 * @param array<string, float> $override The contract's day counts.
	 * @param string|null $caoId The CLA id, when the contract names one.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the override falls below either floor.
	 */
	private function assertLeaveNotWorse(array $override, ?string $caoId): void {
		$overrideTotal = (($override['vakantiedagenWettelijk'] ?? 0.0)
			+ ($override['vakantiedagenBovenwettelijk'] ?? 0.0));

		if ($overrideTotal < self::STATUTORY_LEAVE_DAYS_FULLTIME) {
			throw new InvalidArgumentException(
				'the leave override grants ' . $overrideTotal . ' full-time days, below the statutory minimum of '
				. self::STATUTORY_LEAVE_DAYS_FULLTIME . ' (BW art. 7:634); no agreement may go under the law'
			);
		}

		if ($caoId === null) {
			return;
		}

		$cao = CaoRegistry::get($caoId);
		$leaf = ($cao['leaveEntitlement'] ?? null);
		// Only a usable (verified, non-placeholder) leaf is a floor. An
		// unconfirmed figure must not block a legitimate override — the same
		// lever that keeps it from raising a false violation.
		if (CaoRegistry::minLeaveHours($caoId, 40.0) === null || is_array($leaf) === false) {
			return;
		}

		$value = (array)($leaf['value'] ?? []);
		$collectiveDays = ((float)($value['vakantiedagenWettelijk'] ?? 0)
			+ (float)($value['vakantiedagenBovenwettelijk'] ?? 0));

		if ($overrideTotal < $collectiveDays) {
			throw new InvalidArgumentException(
				'the leave override grants ' . $overrideTotal . ' full-time days where the collective labour '
				. 'agreement grants ' . $collectiveDays . '; an individual contract may improve on collective '
				. 'terms, never undercut them'
			);
		}

	}//end assertLeaveNotWorse()

	/**
	 * The CAO id the contract names, if any.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract as an array.
	 *
	 * @return string|null Null when the contract names no CAO.
	 */
	private function caoId(array $contract): ?string {
		$caoId = trim((string)($contract['cao'] ?? ''));

		return ($caoId === '' ? null : $caoId);
	}//end caoId()

	/**
	 * Coerce a raw override map into numeric surcharge percentages.
	 *
	 * @param mixed $raw Raw override value.
	 *
	 * @return array<string, float>|null Null when the override is absent or carries no numeric entry.
	 */
	private function normalisePercentages(mixed $raw): ?array {
		if (is_array($raw) === false || $raw === []) {
			return null;
		}

		$out = [];
		foreach ($raw as $category => $percentage) {
			if (is_numeric($percentage) === false) {
				continue;
			}

			if ((float)$percentage < 0.0) {
				throw new InvalidArgumentException(
					'an overwerktoeslag percentage must not be negative, got: ' . $percentage
					. ' for "' . $category . '"'
				);
			}

			$out[(string)$category] = (float)$percentage;
		}

		return ($out === [] ? null : $out);
	}//end normalisePercentages()

	/**
	 * Coerce a raw leave override into numeric day counts.
	 *
	 * @param mixed $raw Raw override value.
	 *
	 * @return array<string, float>|null Null when the override is absent or carries no numeric entry.
	 */
	private function normaliseLeaveDays(mixed $raw): ?array {
		if (is_array($raw) === false || $raw === []) {
			return null;
		}

		$out = [];
		foreach (['vakantiedagenWettelijk', 'vakantiedagenBovenwettelijk'] as $key) {
			$value = ($raw[$key] ?? null);
			if (is_numeric($value) === false) {
				continue;
			}

			if ((float)$value < 0.0) {
				throw new InvalidArgumentException('a leave entitlement must not be negative, got: ' . $value);
			}

			$out[$key] = (float)$value;
		}

		return ($out === [] ? null : $out);
	}//end normaliseLeaveDays()
}//end class
