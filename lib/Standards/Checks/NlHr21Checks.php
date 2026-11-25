<?php

/**
 * NL HR21 (functiehuis) Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Executable check for the functiehuis-hr21 corpus rule
 * (lib/Standards/rules/payroll.json): `nl-hr21-schaal-consistentie` — a
 * contract's own `caoSchaal` must agree with the Cao Gemeenten schaal its
 * assigned `Normfunctie` (HR21 standard job function) maps to, but ONLY when
 * that mapping is itself confirmed (`caoSchaalVerified: true`). Vacuous when
 * `normfunctieId` is null, unresolvable, or the resolved Normfunctie's mapping
 * is unverified/placeholder — the `NlCaoChecks::minimumloonSchaalSatisfied()`
 * placeholder-is-advisory precedent (design.md D4), applied to a
 * classification mapping instead of a pay-scale rate. The Normfunctie is
 * resolved via the `hr21.normfunctiesById` audit context
 * (RuleAuditService::buildHr21Context(), the `comp.salaryBandsById` /
 * `cao.employeesById` enrichment precedent) rather than per-object IO, so the
 * predicate stays a pure `fn(array $object, array $context): bool`.
 *
 * Also implements `SeedsObjects`: a small illustrative subset of `Normfunctie`
 * reference rows (functiehuis-hr21 proposal.md "Honesty about verification" —
 * NOT a claimed-complete ~150-function library; HR21's exact catalog was not
 * independently verified from a primary VNG source in this pass). Every
 * seeded row carries `caoSchaalVerified: false` except one, which is flipped
 * to `true` purely so the consistency check has a resolvable, non-vacuous
 * mapping to demonstrate the clean-pass and violation scenarios against
 * (tasks.md #11) — its `caoSchaalSource` says so explicitly, so this single
 * exception is never mistaken for an actual VNG/HR21 confirmation.
 *
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): each of the two HR21 contract rules is declared as an OpenRegister decision table via
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
 * @spec openspec/specs/functiehuis-hr21/spec.md#REQ-HR21-001
 * @spec openspec/specs/functiehuis-hr21/spec.md#REQ-HR21-003
 * @spec openspec/specs/functiehuis-hr21/spec.md#REQ-HR21-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * HR21 normfunctie-to-schaal consistency check, plus the illustrative
 * Normfunctie reference-row seed.
 *
 * @spec openspec/specs/functiehuis-hr21/spec.md#REQ-HR21-003
 */
final class NlHr21Checks implements CheckProvider, SeedsObjects, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * {@inheritDoc}
	 *
	 * Empty: the check this provider owns is table-declared (see tables()).
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
	 * Both EmploymentContract rules as derivation + FIRST-hit decision
	 * tables. A contract without a normfunctie, or whose normfunctie does not
	 * resolve, derives null for the scale rule and passes, as before.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/specs/functiehuis-hr21/spec.md#REQ-HR21-003
	 */
	public static function tables(): array {
		return [
			'EmploymentContract' => [
				'nl-hr21-schaal-consistentie' => [
					'derive' => static fn (array $contract, array $context): ?array => self::schaalInputs($contract, $context),
					'table' => self::satisfiedTable(
						[['name' => 'verifiedMapping', 'type' => 'boolean'], ['name' => 'schaalMatches', 'type' => 'boolean']],
						[['id' => 'scale-differs-from-verified-mapping', 'inputEntries' => ['true', 'false'], 'outputEntries' => [false]]]
					),
				],
				// people-job-framework-maintenance D4: a live contract on a retired function.
				'nl-hr21-vervallen-functie' => [
					'derive' => static fn (array $contract, array $context): array => self::vervallenInputs($contract, $context),
					'table' => self::satisfiedTable(
						[['name' => 'functionRetired', 'type' => 'boolean'], ['name' => 'contractLive', 'type' => 'boolean']],
						[['id' => 'live-contract-on-retired-function', 'inputEntries' => ['true', 'true'], 'outputEntries' => [false]]]
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
	 * @spec openspec/specs/functiehuis-hr21/spec.md#REQ-HR21-003
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * {@inheritDoc}
	 *
	 * A small illustrative `Normfunctie` subset spanning three HR21
	 * hoofdprocessen (design.md Seed Data) — NOT a claimed-complete library.
	 * Every row is `caoSchaalVerified: false` except `HR21-002`, which is
	 * flipped to `true` (with its `caoSchaalSource` saying so) purely to give
	 * the seeded `EmploymentContract` proof cases (REQ-HR21-005) a resolvable,
	 * non-vacuous mapping to check against.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 *
	 * @spec openspec/specs/functiehuis-hr21/spec.md#REQ-HR21-001
	 */
	public static function seedObjects(): array {
		return [
			'Normfunctie' => [
				self::normfunctie(['HR21-001', 'Medewerker Beheer', 'Beheer', '6']),
				array_merge(
					self::normfunctie(['HR21-002', 'Senior Beleidsmedewerker', 'Beleid', '10']),
					[
						'caoSchaalVerified' => true,
						'caoSchaalSource' => 'Illustrative proof-case only — flipped to verified:true so the nl-hr21-schaal-consistentie check has one resolvable mapping to demonstrate against; NOT an actual VNG/HR21 confirmation.',
					]
				),
				self::normfunctie(['HR21-003', 'Beleidsmedewerker', 'Beleid', '9']),
				self::normfunctie(['HR21-004', 'Teammanager', 'Management', '11']),
				self::normfunctie(['HR21-005', 'Afdelingsmanager', 'Management', '13']),
			],
		];
	}//end seedObjects()

	/**
	 * One active HR21 normfunctie whose CAO scale mapping is not yet confirmed.
	 *
	 * @param array{0: string, 1: string, 2: string, 3: string} $row Function code, name, function group and CAO scale.
	 *
	 * @return array<string, mixed>
	 */
	private static function normfunctie(array $row): array {
		return [
			'functiecode' => $row[0],
			'naam' => $row[1],
			'functiegroep' => $row[2],
			'caoSchaal' => $row[3],
			'caoSchaalVerified' => false,
			'caoSchaalSource' => 'HR21/VNG functieboek — mapping not yet independently confirmed against a primary source.',
			'bron' => 'hr21',
			'status' => 'actief',
		];
	}//end normfunctie()

	/**
	 * The `nl-hr21-vervallen-functie` predicate (REQ-JFM-002): false only for a
	 * contract that runs today (started, and no end date or one not yet
	 * passed) whose function resolves in `hr21.normfunctiesById` with status
	 * `vervallen`.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 * @param array<string, mixed> $context  Evaluation context; reads `hr21.normfunctiesById`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/functiehuis-hr21/spec.md#REQ-JFM-002
	 */
	private static function vervallenFunctieSatisfied(array $contract, array $context): bool {
		$normfunctieId = trim((string)($contract['normfunctieId'] ?? ''));
		$status = ($context['hr21']['normfunctiesById'][$normfunctieId]['status'] ?? null);
		if ($normfunctieId === '' || $status !== 'vervallen') {
			return true;
		}

		$today = date('Y-m-d');
		$start = substr((string)($contract['startDate'] ?? ''), 0, 10);
		$end = substr((string)($contract['endDate'] ?? ''), 0, 10);

		return ($start !== '' && $start > $today) || ($end !== '' && $end < $today);
	}//end vervallenFunctieSatisfied()

	/**
	 * The `nl-hr21-schaal-consistentie` predicate (spec.md REQ-HR21-003):
	 * vacuous when `normfunctieId` is absent, does not resolve to a
	 * `Normfunctie` (via the `hr21.normfunctiesById` audit context), or the
	 * resolved Normfunctie's `caoSchaalVerified` is not `true`. Otherwise
	 * requires the contract's own `caoSchaal` to equal the resolved
	 * Normfunctie's `caoSchaal`.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 * @param array<string, mixed> $context Evaluation context; reads `hr21.normfunctiesById`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/functiehuis-hr21/spec.md#REQ-HR21-003
	 */
	private static function schaalConsistentieSatisfied(array $contract, array $context): bool {
		$normfunctieId = trim((string)($contract['normfunctieId'] ?? ''));
		if ($normfunctieId === '') {
			// No normfunctie assigned -- out of scope.
			return true;
		}

		$normfunctie = ($context['hr21']['normfunctiesById'][$normfunctieId] ?? null);
		if (is_array($normfunctie) === false) {
			// Unresolvable normfunctie -- nothing decidable from this object alone.
			return true;
		}

		if (($normfunctie['caoSchaalVerified'] ?? false) !== true) {
			// Unverified/placeholder mapping -- advisory, never a false mandatory violation.
			return true;
		}

		$mappedSchaal = trim((string)($normfunctie['caoSchaal'] ?? ''));
		if ($mappedSchaal === '') {
			return true;
		}

		$contractSchaal = trim((string)($contract['caoSchaal'] ?? ''));
		return $contractSchaal === $mappedSchaal;
	}//end schaalConsistentieSatisfied()

	/**
	 * The scale table's inputs: whether the normfunctie carries a verified,
	 * non-empty scale, and whether the contract's scale equals it. Null when
	 * the contract names no normfunctie or it does not resolve.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 * @param array<string, mixed> $context  Evaluation context; reads `hr21.normfunctiesById`.
	 *
	 * @return array{verifiedMapping: bool, schaalMatches: bool}|null
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function schaalInputs(array $contract, array $context): ?array {
		$normfunctieId = trim((string)($contract['normfunctieId'] ?? ''));
		$normfunctie = ($normfunctieId === '') ? null : ($context['hr21']['normfunctiesById'][$normfunctieId] ?? null);
		if (is_array($normfunctie) === false) {
			return null;
		}

		$mappedSchaal = trim((string)($normfunctie['caoSchaal'] ?? ''));

		return [
			'verifiedMapping' => (($normfunctie['caoSchaalVerified'] ?? false) === true && $mappedSchaal !== ''),
			'schaalMatches' => (trim((string)($contract['caoSchaal'] ?? '')) === $mappedSchaal),
		];

	}//end schaalInputs()

	/**
	 * The retired-function table's inputs: whether the contract's normfunctie
	 * is retired (`vervallen`), and whether the contract runs today (started,
	 * not ended).
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 * @param array<string, mixed> $context  Evaluation context; reads `hr21.normfunctiesById`.
	 *
	 * @return array{functionRetired: bool, contractLive: bool}
	 *
	 * @spec openspec/specs/functiehuis-hr21/spec.md#REQ-JFM-002
	 */
	private static function vervallenInputs(array $contract, array $context): array {
		$normfunctieId = trim((string)($contract['normfunctieId'] ?? ''));
		$today = date('Y-m-d');
		$start = substr((string)($contract['startDate'] ?? ''), 0, 10);
		$end = substr((string)($contract['endDate'] ?? ''), 0, 10);

		return [
			'functionRetired' => ($normfunctieId !== '' && ($context['hr21']['normfunctiesById'][$normfunctieId]['status'] ?? null) === 'vervallen'),
			'contractLive' => (($start === '' || $start <= $today) && ($end === '' || $end >= $today)),
		];

	}//end vervallenInputs()

	/**
	 * The two HR21 closures from before the tables: the oracle that
	 * NlHr21TableParityTest compares tables() with. Not registered; dropped
	 * after the OpenRegister audit run confirms the tables.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/specs/functiehuis-hr21/spec.md#REQ-HR21-003
	 */
	public static function legacyChecks(): array {
		return [
			'EmploymentContract' => [
				'nl-hr21-schaal-consistentie' => static fn (array $contract, array $context): bool => self::schaalConsistentieSatisfied($contract, $context),
				// people-job-framework-maintenance D4: a live contract on a retired function.
				'nl-hr21-vervallen-functie' => static fn (array $contract, array $context): bool => self::vervallenFunctieSatisfied($contract, $context),
			],
		];

	}//end legacyChecks()

}//end class
