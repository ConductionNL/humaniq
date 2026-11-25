<?php

/**
 * NL Stagiair / BBL Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Executable check for the single `hr-stagiair` corpus rule
 * (lib/Standards/rules/labour.json, framework `hr-stagiair`),
 * `nl-bpv-overeenkomst-vereist`, registered under BOTH object types it can
 * apply to (stagiair-bbl-admin design.md D4):
 *
 * - `Stagiair`: a stage placement (HBO/WO/MBO-BOL, zonder dienstverband) whose
 *   startDate has passed but whose BPV-/praktijkleerovereenkomst is not signed
 *   (bpvOvereenkomstOndertekend not true) violates -- the exact
 *   "boolean gate that must be true by a date" shape `nl-onboarding-wid-check`
 *   established.
 * - `EmploymentContract`: the SAME predicate, but GUARDED to `type === 'bbl'`
 *   so it is vacuous for every other contract type
 *   (permanent/temporary/agency/minijob) -- a BBL-leerling has a real
 *   leerarbeidsovereenkomst (an EmploymentContract with type: bbl, design.md
 *   D1), and this is the ONLY contract type this rule ever evaluates.
 *
 * Both predicates are object-local -- both fields (startDate,
 * bpvOvereenkomstOndertekend) live on the object being checked, so no
 * cross-object context is needed (the `hr-signals` object-local shape, unlike
 * `nl-onboarding-proeftijd-bewaking`). This provider does NOT implement
 * SeedsObjects: the demonstrating Stagiair/EmploymentContract seeds live
 * declaratively in lib/Settings/register.d/hr-seed.json (ADR-001), the same
 * pattern NlAorChecks/NlSignalChecks document.
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
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): the rule is declared as OpenRegister decision tables
 * via the ProvidesTables capability (one per object type, the contract table
 * guarded to type bbl), so the gates are matched by the shared
 * Dmn\DecisionTableEvaluator and only the derivations (strict signed flag,
 * whether the placement has started) stay here. The closure predicates remain
 * available as legacyChecks() -- the parity oracle, NOT registered in the
 * engine -- until staged retirement (REQ-RULE-009).
 *
 * @spec openspec/specs/stagiair-bbl-admin/spec.md
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use DateTimeImmutable;

/**
 * The single hr-stagiair BPV-overeenkomst obligation, over Stagiair + bbl EmploymentContract.
 */
final class NlStagiairChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * {@inheritDoc}
	 *
	 * Empty: every check this provider owns is table-declared (see tables()).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function checks(): array {
		return [];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * REQ-STAG-005 as derivation + FIRST-hit decision table, once per object
	 * type: a started placement without a signed BPV-overeenkomst violates;
	 * on EmploymentContract only when the contract type is bbl (design.md D4).
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/specs/stagiair-bbl-admin/spec.md
	 */
	public static function tables(): array {
		return [
			'Stagiair' => [
				// A stage placement that has started must have a signed
				// BPV-overeenkomst on file (REQ-STAG-005).
				'nl-bpv-overeenkomst-vereist' => [
					'derive' => self::bpvInputs(...),
					'table' => self::satisfiedTable(
						[['name' => 'bpvSigned', 'type' => 'boolean'], ['name' => 'placementStarted', 'type' => 'boolean']],
						[['id' => 'started-unsigned', 'inputEntries' => ['false', 'true'], 'outputEntries' => [false]]]
					),
				],
			],
			'EmploymentContract' => [
				// The SAME rule, guarded to type: bbl -- vacuous for every
				// other contract type (REQ-STAG-005, design.md D4).
				'nl-bpv-overeenkomst-vereist' => [
					'derive' => self::bblBpvInputs(...),
					'table' => self::satisfiedTable(
						[['name' => 'contractType', 'type' => 'string'], ['name' => 'bpvSigned', 'type' => 'boolean'], ['name' => 'placementStarted', 'type' => 'boolean']],
						[['id' => 'bbl-started-unsigned', 'inputEntries' => ['"bbl"', 'false', 'true'], 'outputEntries' => [false]]]
					),
				],
			],
		];

	}//end tables()

	/**
	 * Inputs of the Stagiair table: whether the BPV-overeenkomst is strictly
	 * signed and whether the placement's start date lies before today (an
	 * unreadable start date counts as not started).
	 *
	 * @param array<string, mixed> $object The Stagiair.
	 *
	 * @return array{bpvSigned: bool, placementStarted: bool}
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function bpvInputs(array $object): array {
		$startDate = strtotime((string)($object['startDate'] ?? ''));

		return [
			'bpvSigned' => (($object['bpvOvereenkomstOndertekend'] ?? false) === true),
			'placementStarted' => ($startDate !== false && $startDate < (new DateTimeImmutable('today'))->getTimestamp()),
		];

	}//end bpvInputs()

	/**
	 * Inputs of the EmploymentContract table: the contract type plus the
	 * Stagiair inputs.
	 *
	 * @param array<string, mixed> $object The EmploymentContract.
	 *
	 * @return array{contractType: string, bpvSigned: bool, placementStarted: bool}
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function bblBpvInputs(array $object): array {
		return ['contractType' => (string)($object['type'] ?? '')] + self::bpvInputs($object);

	}//end bblBpvInputs()

	/**
	 * The pre-conversion closure predicates, kept as the PARITY ORACLE only:
	 * not registered in the engine (checks() is empty), consumed by the
	 * table/legacy parity test, retired with the other converted rules after
	 * the table form is proven on an OpenRegister-backed audit run
	 * (REQ-RULE-009, tasks section 6).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/specs/stagiair-bbl-admin/spec.md
	 */
	public static function legacyChecks(): array {
		return [
			'Stagiair' => [
				// A stage placement that has started must have a signed
				// BPV-overeenkomst on file (REQ-STAG-005).
				'nl-bpv-overeenkomst-vereist' => static fn (array $object): bool => self::bpvSatisfied($object),
			],
			'EmploymentContract' => [
				// The SAME rule, guarded to type: bbl -- vacuous for every
				// other contract type (REQ-STAG-005, design.md D4).
				'nl-bpv-overeenkomst-vereist' => static fn (array $object): bool => self::bblBpvSatisfied($object),
			],
		];

	}//end legacyChecks()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/stagiair-bbl-admin/spec.md
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * True (satisfied) unless a placement has started (startDate strictly in
	 * the past relative to today) AND its BPV-overeenkomst is not signed
	 * (bpvOvereenkomstOndertekend is not the exact boolean true). A
	 * future/absent/unparseable startDate is vacuously satisfied (the
	 * placement has not started yet), and a signed BPV is always satisfied.
	 *
	 * @param array<string, mixed> $object The Stagiair (or bbl EmploymentContract) fields.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/stagiair-bbl-admin/spec.md
	 */
	private static function bpvSatisfied(array $object): bool {
		if (($object['bpvOvereenkomstOndertekend'] ?? false) === true) {
			return true;
		}

		$startDate = strtotime((string)($object['startDate'] ?? ''));
		if ($startDate === false) {
			return true;
		}

		$startDatePassed = $startDate < (new DateTimeImmutable('today'))->getTimestamp();

		return $startDatePassed === false;
	}//end bpvSatisfied()

	/**
	 * The EmploymentContract branch: vacuously satisfied for every contract
	 * type other than `bbl` (the rule never fires for
	 * permanent/temporary/agency/minijob, design.md D4); for a bbl contract it
	 * applies the identical BPV-signed-by-start-date predicate.
	 *
	 * @param array<string, mixed> $object The EmploymentContract fields.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/stagiair-bbl-admin/spec.md
	 */
	private static function bblBpvSatisfied(array $object): bool {
		if ((string)($object['type'] ?? '') !== 'bbl') {
			return true;
		}

		return self::bpvSatisfied($object);
	}//end bblBpvSatisfied()

}//end class
