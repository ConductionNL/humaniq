<?php

/**
 * NL Asset Management Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Executable checks for the two hr-assets-core asset-custody-integrity rules
 * of the labour corpus (lib/Standards/rules/labour.json), mapped onto the
 * AssetAssignment object type (asset-management-mvp; field names renamed by
 * hrmq-asset-fleet-merge): `nl-asset-assignment-consistency` -- an open
 * AssetAssignment (no returnedOn) must reference an existing Asset in status
 * `issued` and an existing Employee, and `issuedOn <= returnedOn` must hold
 * whenever `returnedOn` is present; and `nl-asset-inname-bij-offboarding` --
 * an open AssetAssignment whose employee has a non-cancelled Offboarding case
 * past its planned completion date (lastWorkingDay) is flagged (asset return
 * is part of clean offboarding).
 *
 * Both predicates are cross-object: they read the `context['related']
 * ['Asset']['byId']`, `context['related']['Employee']['byId']` and
 * `context['related']['Offboarding']['plannedCompletionByEmployeeId']`
 * indexes `RuleAuditService::buildRelatedContext()` populates in its
 * pre-pass, rather than re-querying the register. The Offboarding index is
 * built with the same degrade-to-empty `loadAll()` behaviour every other
 * index uses: while the parallel `offboarding-wizard-mvp` change has not
 * landed, the index is empty and `nl-asset-inname-bij-offboarding` passes
 * vacuously -- the two changes land in either order (design.md D3). This
 * provider does NOT implement SeedsObjects: the seed Asset/AssetAssignment
 * data -- including the one deliberately inconsistent open uitgifte that
 * exercises `nl-asset-assignment-consistency` -- lives in
 * lib/Settings/register.d/hr-seed.json (ADR-001), the NlOrgChecks precedent.
 *
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): each of the two asset-issue rules is declared as an OpenRegister decision table via
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
 * @spec openspec/specs/asset-management/spec.md#REQ-AST-005
 * @spec openspec/specs/asset-management/spec.md#REQ-AST-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use DateTimeImmutable;

/**
 * Asset-custody integrity executable checks (assignment consistency +
 * offboarding asset return).
 *
 * @spec openspec/specs/asset-management/spec.md#REQ-AST-005
 */
final class NlAssetChecks implements CheckProvider, ProvidesTables {

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
	 * Both AssetAssignment rules as derivation + FIRST-hit decision tables.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/specs/asset-management/spec.md#REQ-AST-005
	 */
	public static function tables(): array {
		return [
			'AssetAssignment' => [
				'nl-asset-assignment-consistency' => [
					'derive' => static fn (array $o, array $c): array => [
						'returned' => (trim((string)($o['returnedOn'] ?? '')) !== ''),
						'datesInOrder' => self::datesInOrder(from: trim((string)($o['issuedOn'] ?? '')), to: trim((string)($o['returnedOn'] ?? ''))),
						'holdsIssuedAsset' => self::holdsIssuedAsset(o: $o, c: $c),
						'employeeResolves' => self::employeeResolves(o: $o, c: $c),
					],
					'table' => self::satisfiedTable(
						[
							['name' => 'returned', 'type' => 'boolean'],
							['name' => 'datesInOrder', 'type' => 'boolean'],
							['name' => 'holdsIssuedAsset', 'type' => 'boolean'],
							['name' => 'employeeResolves', 'type' => 'boolean'],
						],
						[
							['id' => 'returned-before-issued', 'inputEntries' => ['true', 'false', '-', '-'], 'outputEntries' => [false]],
							['id' => 'open-without-issued-asset', 'inputEntries' => ['false', '-', 'false', '-'], 'outputEntries' => [false]],
							['id' => 'open-without-employee', 'inputEntries' => ['false', '-', '-', 'false'], 'outputEntries' => [false]],
						]
					),
				],
				'nl-asset-inname-bij-offboarding' => [
					'derive' => static fn (array $o, array $c): ?array => self::innameInputs($o, $c),
					'table' => self::satisfiedTable(
						[['name' => 'returned', 'type' => 'boolean'], ['name' => 'completionPassed', 'type' => 'boolean']],
						[['id' => 'held-past-offboarding', 'inputEntries' => ['false', 'true'], 'outputEntries' => [false]]]
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
	 * @spec openspec/specs/asset-management/spec.md#REQ-AST-005
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * True when the assignment's dates are coherent (no returnedOn, or
	 * returnedOn on/after issuedOn) AND, while the assignment is open (no
	 * returnedOn), its `assetId` resolves in the context's Asset index to an
	 * asset with `status: issued` AND its `employeeId` resolves in the
	 * context's Employee index. Fail-closed on a dangling or missing
	 * `assetId`/`employeeId` while open, and on an asset whose status is not
	 * `issued`. A coherently-dated, already-closed assignment is never
	 * checked against the asset's current status -- historical uitgiftes may
	 * reference re-stocked or written-off assets.
	 *
	 * @param array<string, mixed> $o The AssetAssignment.
	 * @param array<string, mixed> $c Evaluation context (carries `related`).
	 *
	 * @return bool
	 */
	private static function assignmentConsistent(array $o, array $c): bool {
		$issuedOn = trim((string)($o['issuedOn'] ?? ''));
		$returnedOn = trim((string)($o['returnedOn'] ?? ''));

		if ($returnedOn !== '') {
			// A closed uitgifte with coherent dates -- historical uitgiftes
			// may reference a re-stocked or written-off asset.
			return self::datesInOrder(from: $issuedOn, to: $returnedOn);
		}

		if (self::holdsIssuedAsset(o: $o, c: $c) === false) {
			return false;
		}

		$employeeId = trim((string)($o['employeeId'] ?? ''));

		return $employeeId !== '' && array_key_exists($employeeId, self::relatedEmployeesById($c)) === true;
	}//end assignmentConsistent()

	/**
	 * Whether two dates are in order; an unreadable date passes.
	 *
	 * @param string $from The first date.
	 * @param string $to The second date.
	 *
	 * @return bool
	 */
	private static function datesInOrder(string $from, string $to): bool {
		$first = strtotime($from);
		$second = strtotime($to);

		return $first === false || $second === false || $second >= $first;
	}//end datesInOrder()

	/**
	 * Whether an open uitgifte names an asset that is marked issued.
	 *
	 * @param array<string, mixed> $o The uitgifte.
	 * @param array<string, mixed> $c The evaluation context.
	 *
	 * @return bool
	 */
	private static function holdsIssuedAsset(array $o, array $c): bool {
		$assetId = trim((string)($o['assetId'] ?? ''));
		if ($assetId === '') {
			return false;
		}

		$asset = (self::relatedAssetsById($c)[$assetId] ?? null);

		return is_array($asset) === true && ($asset['status'] ?? '') === 'issued';
	}//end holdsIssuedAsset()

	/**
	 * True unless the assignment is open (no returnedOn) AND its
	 * `employeeId` resolves in the context's Offboarding
	 * `plannedCompletionByEmployeeId` index to a date strictly before today
	 * (the audit run date). Passes vacuously when the assignment is closed,
	 * the index is empty/absent (the parallel offboarding-wizard-mvp change
	 * not yet landed, or the employee has no non-cancelled Offboarding case),
	 * or the employee's planned completion date is today or in the future.
	 *
	 * @param array<string, mixed> $o The AssetAssignment.
	 * @param array<string, mixed> $c Evaluation context (carries `related`).
	 *
	 * @return bool
	 */
	private static function innameBijOffboardingSatisfied(array $o, array $c): bool {
		$returnedOn = trim((string)($o['returnedOn'] ?? ''));
		if ($returnedOn !== '') {
			// Closed uitgifte — nothing outstanding to chase.
			return true;
		}

		$employeeId = trim((string)($o['employeeId'] ?? ''));
		if ($employeeId === '') {
			return true;
		}

		$plannedCompletion = (self::relatedPlannedCompletionByEmployeeId($c)[$employeeId] ?? null);
		if ($plannedCompletion === null) {
			// No non-cancelled Offboarding case resolves for this employee —
			// vacuous pass (schema absent, or employee not offboarding).
			return true;
		}

		$completion = strtotime((string)$plannedCompletion);
		if ($completion === false) {
			return true;
		}

		$today = (new DateTimeImmutable('today'))->getTimestamp();

		return $completion >= $today;
	}//end innameBijOffboardingSatisfied()

	/**
	 * The `related.Asset.byId` index from the context, or an empty array
	 * when the pre-pass has not populated it (e.g. the schema is not yet
	 * imported).
	 *
	 * @param array<string, mixed> $c Evaluation context.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function relatedAssetsById(array $c): array {
		$byId = ($c['related']['Asset']['byId'] ?? []);
		return is_array($byId) === true ? $byId : [];
	}//end relatedAssetsById()

	/**
	 * The `related.Employee.byId` index from the context, or an empty array
	 * when the pre-pass has not populated it.
	 *
	 * @param array<string, mixed> $c Evaluation context.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function relatedEmployeesById(array $c): array {
		$byId = ($c['related']['Employee']['byId'] ?? []);
		return is_array($byId) === true ? $byId : [];
	}//end relatedEmployeesById()

	/**
	 * The `related.Offboarding.plannedCompletionByEmployeeId` index from the
	 * context, or an empty array when the pre-pass has not populated it
	 * (e.g. the Offboarding schema is not yet imported — vacuous pass).
	 *
	 * @param array<string, mixed> $c Evaluation context.
	 *
	 * @return array<string, string>
	 */
	private static function relatedPlannedCompletionByEmployeeId(array $c): array {
		$byEmployeeId = ($c['related']['Offboarding']['plannedCompletionByEmployeeId'] ?? []);
		return is_array($byEmployeeId) === true ? $byEmployeeId : [];
	}//end relatedPlannedCompletionByEmployeeId()

	/**
	 * Whether the issue names an employee the Employee index knows.
	 *
	 * @param array<string, mixed> $o The AssetAssignment.
	 * @param array<string, mixed> $c Evaluation context; reads the Employee index.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function employeeResolves(array $o, array $c): bool {
		$employeeId = trim((string)($o['employeeId'] ?? ''));

		return $employeeId !== '' && array_key_exists($employeeId, self::relatedEmployeesById($c)) === true;

	}//end employeeResolves()

	/**
	 * The inname table's inputs, or null when the issue names no employee,
	 * no offboarding resolves for them, or its planned completion cannot be
	 * read (vacuous, as before).
	 *
	 * @param array<string, mixed> $o The AssetAssignment.
	 * @param array<string, mixed> $c Evaluation context; reads the Offboarding index.
	 *
	 * @return array{returned: bool, completionPassed: bool}|null
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function innameInputs(array $o, array $c): ?array {
		$employeeId = trim((string)($o['employeeId'] ?? ''));
		$plannedCompletion = (self::relatedPlannedCompletionByEmployeeId($c)[$employeeId] ?? null);
		$completion = ($plannedCompletion === null) ? false : strtotime((string)$plannedCompletion);
		if ($employeeId === '' || $completion === false) {
			return null;
		}

		return [
			'returned' => (trim((string)($o['returnedOn'] ?? '')) !== ''),
			'completionPassed' => ($completion < (new DateTimeImmutable('today'))->getTimestamp()),
		];

	}//end innameInputs()

	/**
	 * The asset-issue closures as they ran before the tables: the reference
	 * NlAssetTableParityTest compares tables() with. Not registered; removed
	 * once the OpenRegister audit run confirms the tables.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/specs/asset-management/spec.md#REQ-AST-005
	 */
	public static function legacyChecks(): array {
		return [
			'AssetAssignment' => [
				// Administration-integrity control (mandatory) — coherent
				// dates + an open uitgifte must resolve to an issued asset
				// and a known employee.
				'nl-asset-assignment-consistency' => static fn (array $o, array $c): bool => self::assignmentConsistent($o, $c),
				// Administration-integrity control (recommended) — an open
				// uitgifte should not outlive the employee's planned
				// offboarding completion.
				'nl-asset-inname-bij-offboarding' => static fn (array $o, array $c): bool => self::innameBijOffboardingSatisfied($o, $c),
			],
		];

	}//end legacyChecks()

}//end class
