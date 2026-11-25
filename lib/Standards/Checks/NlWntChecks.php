<?php

/**
 * NL WNT Norm-overschrijding Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): the WNT-norm rule is declared as an OpenRegister decision table via
 * the ProvidesTables capability. The derivations stay here; the shared
 * Dmn\DecisionTableEvaluator decides. The closure predicate remains
 * available as legacyChecks() -- the parity oracle, NOT registered in the
 * engine -- until staged retirement (REQ-RULE-009).
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/wnt-disclosure/spec.md#REQ-WNT-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use OCA\Humaniq\Payroll\TaxTables;

/**
 * The WNT norm-overschrijding self-check: a topfunctionaris's annual
 * bezoldiging vs the sourced WNT-norm, cleared by a recorded exemption.
 *
 * @spec openspec/specs/wnt-disclosure/spec.md#REQ-WNT-003
 */
final class NlWntChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * {@inheritDoc}
	 *
	 * Empty: the check this provider owns is table-declared (see tables()).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function checks(): array {
		return [];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * `nl-wnt-norm-overschrijding` as derivation + FIRST-hit decision table:
	 * a topfunctionaris without a recorded exemption whose compensation is
	 * over the WNT norm fails. A dangling employee or an unreadable norm
	 * derives null and passes, as before.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/specs/wnt-disclosure/spec.md#REQ-WNT-003
	 */
	public static function tables(): array {
		return [
			'WntDisclosure' => [
				'nl-wnt-norm-overschrijding' => [
					'derive' => static fn (array $disclosure, array $context): ?array => self::normInputs($disclosure, $context),
					'table' => self::satisfiedTable(
						[
							['name' => 'topfunctionaris', 'type' => 'boolean'],
							['name' => 'exempt', 'type' => 'boolean'],
							['name' => 'overNorm', 'type' => 'boolean'],
						],
						[['id' => 'topfunctionaris-over-norm', 'inputEntries' => ['true', 'false', 'true'], 'outputEntries' => [false]]]
					),
				],
			],
		];

	}//end tables()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/wnt-disclosure/spec.md#REQ-WNT-003
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
	 * @spec openspec/specs/wnt-disclosure/spec.md#REQ-WNT-003
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
	 * @spec openspec/specs/wnt-disclosure/spec.md#REQ-WNT-003
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

	/**
	 * The WNT table's inputs, or null when the employee does not resolve or
	 * the norm cannot be read (never a fabricated violation).
	 *
	 * @param array<string, mixed> $disclosure The WntDisclosure.
	 * @param array<string, mixed> $context    Evaluation context; reads `related.Employee.byId`.
	 *
	 * @return array{topfunctionaris: bool, exempt: bool, overNorm: bool}|null
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function normInputs(array $disclosure, array $context): ?array {
		$employee = ($context['related']['Employee']['byId'][trim((string)($disclosure['employeeId'] ?? ''))] ?? null);
		$normJaarCents = self::wntNormJaarCents();
		if (is_array($employee) === false || $normJaarCents === null) {
			return null;
		}

		return [
			'topfunctionaris' => (($employee['wntTopfunctionaris'] ?? false) === true),
			'exempt' => (($employee['wntUitzonderingReden'] ?? null) !== null),
			'overNorm' => ((int)round(((float)($disclosure['totalCompensation'] ?? 0)) * 100) > $normJaarCents),
		];

	}//end normInputs()

	/**
	 * The WNT-norm closure as it stood before the table, kept so
	 * NlWntTableParityTest can hold the two side by side; unregistered, and
	 * removed after the OpenRegister audit run proves the table.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/specs/wnt-disclosure/spec.md#REQ-WNT-003
	 */
	public static function legacyChecks(): array {
		return [
			'WntDisclosure' => [
				'nl-wnt-norm-overschrijding' => static fn (array $disclosure, array $context): bool => self::withinWntNorm($disclosure, $context),
			],
		];

	}//end legacyChecks()

}//end class
