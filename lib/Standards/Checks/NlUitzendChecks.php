<?php

/**
 * NL Uitzend (agency-worker) Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Executable checks for the two agency-side rules the `uitzend-flexpool` change
 * added to the labour corpus (lib/Standards/rules/labour.json, framework
 * hr-uitzend), both mapped onto the EmploymentContract object type and both
 * strictly guarded on `type === 'agency'` so a permanent/temporary/minijob
 * contract is never evaluated (uitzend-flexpool D1/D3):
 *
 * - `nl-uitzendbeding-alleen-fase-a` (EmploymentContract): on an agency
 *   contract, when `uitzendbedingVanToepassing` is true the `uitzendFase` must
 *   equal `A` -- the uitzendbeding (BW art. 7:691 lid 2) is only legally sound
 *   during fase A. Vacuous for non-agency contracts and when the beding does
 *   not apply. Asserts the fase/beding relationship only, never a week-count
 *   (uitzend-flexpool D2).
 * - `nl-inlenersbeloning-onderbouwing-vereist` (EmploymentContract): on an
 *   agency contract with a populated `hourlyWage`, `inlenersbeloningReferentie`
 *   must be non-empty (WAADI art. 8, the inlenersbeloning duty). Presence-only
 *   (the WID-check boolean-gate shape) -- the referenced figure's correctness
 *   is never validated. Vacuous for non-agency contracts and when no wage is
 *   set (uitzend-flexpool D3).
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
 * next conversion wave): both rules are declared as OpenRegister decision
 * tables via the ProvidesTables capability, so the contract-type, fase and
 * presence gates are matched by the shared Dmn\DecisionTableEvaluator and only
 * the derivations (strict flag, numeric wage, trimmed reference) stay here.
 * The closure predicates remain available as legacyChecks() -- the parity
 * oracle, NOT registered in the engine -- until staged retirement
 * (REQ-RULE-009).
 *
 * @spec openspec/specs/uitzend-flexpool/spec.md
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Agency-worker (uitzendkracht) executable checks -- both agency-scoped.
 */
final class NlUitzendChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * The EmploymentContract.type value both checks are exclusively scoped to.
	 *
	 * @var string
	 */
	private const AGENCY_TYPE = 'agency';

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
	 * Both agency-scoped EmploymentContract rules as derivation + FIRST-hit
	 * decision table; a non-agency contract falls to the catch-all row and
	 * passes, as before.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/specs/uitzend-flexpool/spec.md
	 */
	public static function tables(): array {
		$agency = '"' . self::AGENCY_TYPE . '"';

		return [
			'EmploymentContract' => [
				// BW art. 7:691 lid 2 -- the uitzendbeding only holds in fase A.
				'nl-uitzendbeding-alleen-fase-a' => [
					'derive' => self::faseInputs(...),
					'table' => self::satisfiedTable(
						[['name' => 'contractType', 'type' => 'string'], ['name' => 'bedingApplies', 'type' => 'boolean'], ['name' => 'inFaseA', 'type' => 'boolean']],
						[
							['id' => 'beding-in-fase-a', 'inputEntries' => [$agency, 'true', 'true'], 'outputEntries' => [true]],
							['id' => 'beding-outside-fase-a', 'inputEntries' => [$agency, 'true', '-'], 'outputEntries' => [false]],
						]
					),
				],
				// WAADI art. 8 -- an agency wage needs an inlenersbeloning reference.
				'nl-inlenersbeloning-onderbouwing-vereist' => [
					'derive' => self::inlenersbeloningInputs(...),
					'table' => self::satisfiedTable(
						[['name' => 'contractType', 'type' => 'string'], ['name' => 'wageSet', 'type' => 'boolean'], ['name' => 'referenceRecorded', 'type' => 'boolean']],
						[
							['id' => 'wage-without-reference', 'inputEntries' => [$agency, 'true', 'false'], 'outputEntries' => [false]],
						]
					),
				],
			],
		];

	}//end tables()

	/**
	 * Inputs of the fase-A rule: the contract type, whether the beding is
	 * strictly on, and whether the contract is in fase A.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 *
	 * @return array{contractType: string, bedingApplies: bool, inFaseA: bool}
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function faseInputs(array $contract): array {
		return [
			'contractType' => (string)($contract['type'] ?? ''),
			'bedingApplies' => (($contract['uitzendbedingVanToepassing'] ?? null) === true),
			'inFaseA' => ((string)($contract['uitzendFase'] ?? '') === 'A'),
		];

	}//end faseInputs()

	/**
	 * Inputs of the inlenersbeloning rule: the contract type, whether a
	 * numeric hourly wage is set, and whether a non-blank reference is recorded.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 *
	 * @return array{contractType: string, wageSet: bool, referenceRecorded: bool}
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function inlenersbeloningInputs(array $contract): array {
		return [
			'contractType' => (string)($contract['type'] ?? ''),
			'wageSet' => is_numeric($contract['hourlyWage'] ?? null),
			'referenceRecorded' => (trim((string)($contract['inlenersbeloningReferentie'] ?? '')) !== ''),
		];

	}//end inlenersbeloningInputs()

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
	 * @spec openspec/specs/uitzend-flexpool/spec.md
	 */
	public static function legacyChecks(): array {
		return [
			'EmploymentContract' => [
				// BW art. 7:691 lid 2 -- the uitzendbeding only holds in fase A.
				'nl-uitzendbeding-alleen-fase-a' => static fn (array $contract): bool => self::uitzendbedingAlleenFaseASatisfied($contract),
				// WAADI art. 8 -- an agency wage needs an inlenersbeloning reference.
				'nl-inlenersbeloning-onderbouwing-vereist' => static fn (array $contract): bool => self::inlenersbeloningOnderbouwingSatisfied($contract),
			],
		];

	}//end legacyChecks()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * The `nl-uitzendbeding-alleen-fase-a` predicate (spec.md REQ-UITZ-002):
	 * vacuous pass for any non-agency contract (the guard is `type === 'agency'`
	 * only) and when `uitzendbedingVanToepassing` is not strictly true.
	 * Otherwise the beding applies, so `uitzendFase` must equal `A`.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/uitzend-flexpool/spec.md
	 */
	private static function uitzendbedingAlleenFaseASatisfied(array $contract): bool {
		if ((string)($contract['type'] ?? '') !== self::AGENCY_TYPE) {
			// Not an agency contract -- out of scope, never evaluated.
			return true;
		}

		if (($contract['uitzendbedingVanToepassing'] ?? null) !== true) {
			// The beding does not apply -- any (or no) fase is fine.
			return true;
		}

		return (string)($contract['uitzendFase'] ?? '') === 'A';
	}//end uitzendbedingAlleenFaseASatisfied()

	/**
	 * The `nl-inlenersbeloning-onderbouwing-vereist` predicate (spec.md
	 * REQ-UITZ-003): vacuous pass for any non-agency contract (the guard is
	 * `type === 'agency'` only) and when `hourlyWage` is absent/non-numeric
	 * (nothing to substantiate). Otherwise `inlenersbeloningReferentie` must be
	 * a non-empty string -- presence only, the figure is never validated.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/uitzend-flexpool/spec.md
	 */
	private static function inlenersbeloningOnderbouwingSatisfied(array $contract): bool {
		if ((string)($contract['type'] ?? '') !== self::AGENCY_TYPE) {
			// Not an agency contract -- out of scope, never evaluated.
			return true;
		}

		if (is_numeric($contract['hourlyWage'] ?? null) === false) {
			// No wage set -- nothing decidable to substantiate.
			return true;
		}

		return trim((string)($contract['inlenersbeloningReferentie'] ?? '')) !== '';
	}//end inlenersbeloningOnderbouwingSatisfied()

}//end class
