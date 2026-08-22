<?php

/**
 * NL WNT Norm-overschrijding Check Provider
 *
 * Executable check for the wnt-disclosure corpus rule
 * (lib/Standards/rules/payroll.json, framework `wnt-2013`) -- unenforced
 * until this provider registered its predicate (the NlDgaChecks precedent):
 *
 * - `nl-wnt-norm-overschrijding` (WntDisclosure): vacuous when the referenced
 *   Employee cannot be resolved from the shared `related.Employee.byId`
 *   pre-pass (dangling reference -- a different data-integrity problem);
 *   vacuous when that Employee is not a `wntTopfunctionaris`; vacuous when the
 *   Employee has a non-null `wntUitzonderingReden` (a recorded transitional
 *   exemption -- overgangsrecht or ministerial ontheffing, presence-only gate
 *   per design.md D4); vacuous when the WNT-norm figure cannot be read yet
 *   (the `30-procent-regeling` dependency has not landed -- design.md D1's
 *   fail-safe direction, never a fabricated fallback); else violates when the
 *   hand-entered `totalCompensation` exceeds the WNT-norm's ANNUAL figure
 *   (`aftoppingsgrens.jaar`, EUR 262.000/jaar in 2026) read via
 *   `TaxTables::dertigProcentRegeling()` -- the SINGLE shared home for the
 *   datum, never re-declared here (WNT art. 2.3, BWBR0032249).
 *
 * This provider does NOT implement SeedsObjects: wnt-disclosure ships its
 * topfunctionaris Employee + WntDisclosure seeds declaratively in
 * lib/Settings/register.d/hr-seed.json (design.md Seed Data), so `seedSpec()`
 * returns [] -- the NlPayrollChecks/NlDgaChecks precedent for a check whose
 * fixtures live in the register seed, not in a provider seed-object.
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
 * @spec openspec/changes/wnt-disclosure/specs/wnt-disclosure/spec.md#REQ-WNT-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use OCA\Humaniq\Payroll\TaxTables;

/**
 * The WNT norm-overschrijding self-check: a topfunctionaris's annual
 * bezoldiging vs the sourced WNT-norm, cleared by a recorded exemption.
 */
final class NlWntChecks implements CheckProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/wnt-disclosure/specs/wnt-disclosure/spec.md#REQ-WNT-003
	 */
	public static function checks(): array {
		return [
			'WntDisclosure' => [
				'nl-wnt-norm-overschrijding' => static fn (array $disclosure, array $context): bool => self::withinWntNorm($disclosure, $context),
			],
		];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/wnt-disclosure/specs/wnt-disclosure/spec.md#REQ-WNT-003
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * The `nl-wnt-norm-overschrijding` predicate (spec.md REQ-WNT-003):
	 * vacuous on a dangling employee reference, vacuous for a
	 * non-topfunctionaris, vacuous when a transitional exemption is recorded,
	 * vacuous when the WNT-norm figure is not yet readable (the
	 * `30-procent-regeling` dependency has not landed) -- else the hand-entered
	 * annual `totalCompensation` must not exceed the WNT-norm's annual figure.
	 *
	 * @param array<string, mixed> $disclosure The WntDisclosure object.
	 * @param array<string, mixed> $context Evaluation context; reads `related.Employee.byId`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/wnt-disclosure/specs/wnt-disclosure/spec.md#REQ-WNT-003
	 */
	private static function withinWntNorm(array $disclosure, array $context): bool {
		$employeeId = trim((string)($disclosure['employeeId'] ?? ''));
		$employee = ($context['related']['Employee']['byId'][$employeeId] ?? null);
		if (is_array($employee) === false) {
			// Dangling employee reference -- a different data-integrity
			// problem, not this rule's job (the NlPayrollChecks
			// vacuous-on-dangling posture).
			return true;
		}

		if (($employee['wntTopfunctionaris'] ?? false) !== true) {
			// Not a topfunctionaris: the WNT bezoldigingsmaximum does not
			// apply, regardless of totalCompensation.
			return true;
		}

		if (($employee['wntUitzonderingReden'] ?? null) !== null) {
			// A valid transitional-exemption ground is recorded
			// (overgangsrecht / ontheffing-minister) -- presence-only gate
			// (design.md D4). Clears the flag.
			return true;
		}

		$normJaarCents = self::wntNormJaarCents();
		if ($normJaarCents === null) {
			// The WNT-norm figure is not yet readable (the 30-procent-regeling
			// dependency has not landed, or no table is loaded). Degrade to a
			// vacuous pass -- NEVER a fabricated fallback figure (design.md
			// D1).
			return true;
		}

		$compCents = (int)round(((float)($disclosure['totalCompensation'] ?? 0)) * 100);

		return $compCents <= $normJaarCents;
	}//end withinWntNorm()

	/**
	 * The WNT-norm's ANNUAL figure in cents, read from the shared
	 * `30-procent-regeling` `aftoppingsgrens.jaar` leaf via
	 * `TaxTables::dertigProcentRegeling()` -- the single home for the datum,
	 * never re-declared (design.md D1). Returns null when no table is loaded
	 * or the leaf/accessor does not exist yet (the dependency has not landed),
	 * so the caller degrades to a vacuous pass rather than fabricating a
	 * figure.
	 *
	 * @return int|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) TaxTables::load() is a pure value-object factory method -- the same unguarded precedent NlDgaChecks/NlPayrollChecks already use.
	 *
	 * @spec openspec/changes/wnt-disclosure/specs/wnt-disclosure/spec.md#REQ-WNT-003
	 */
	private static function wntNormJaarCents(): ?int {
		$ids = TaxTables::availableIds();
		if ($ids === []) {
			return null;
		}

		try {
			$regeling = TaxTables::load(max($ids))->dertigProcentRegeling();
		} catch (\Throwable $e) {
			// The dertigProcentRegeling leaf (incl. aftoppingsgrens.jaar) does
			// not exist yet -- the 30-procent-regeling dependency has not
			// landed. Norm unknown -> vacuous (design.md D1).
			return null;
		}

		$jaarCents = ($regeling['aftoppingsgrensJaarCents'] ?? null);
		if (is_int($jaarCents) === false || $jaarCents <= 0) {
			return null;
		}

		return $jaarCents;
	}//end wntNormJaarCents()

}//end class
