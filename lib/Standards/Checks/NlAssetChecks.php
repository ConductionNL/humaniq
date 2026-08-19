<?php

/**
 * NL Asset Management Check Provider
 *
 * Executable checks for the two hr-assets-core asset-custody-integrity rules
 * of the labour corpus (lib/Standards/rules/labour.json), mapped onto the
 * AssetAssignment object type (asset-management-mvp): `nl-asset-assignment-
 * consistency` -- an open AssetAssignment (no innameDatum) must reference an
 * existing Asset in status `uitgegeven` and an existing Employee, and
 * `uitgifteDatum <= innameDatum` must hold whenever `innameDatum` is present;
 * and `nl-asset-inname-bij-offboarding` -- an open AssetAssignment whose
 * employee has a non-cancelled Offboarding case past its planned completion
 * date (lastWorkingDay) is flagged (asset return is part of clean
 * offboarding).
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
 * @category Standards
 * @package  OCA\Hrmq\Standards\Checks
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
 * @spec openspec/changes/asset-management-mvp/specs/asset-management/spec.md#REQ-AST-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

use DateTimeImmutable;

/**
 * Asset-custody integrity executable checks (assignment consistency +
 * offboarding asset return).
 */
final class NlAssetChecks implements CheckProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
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

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * True when the assignment's dates are coherent (no innameDatum, or
	 * innameDatum on/after uitgifteDatum) AND, while the assignment is open
	 * (no innameDatum), its `assetId` resolves in the context's Asset index
	 * to an asset with `status: uitgegeven` AND its `employeeId` resolves in
	 * the context's Employee index. Fail-closed on a dangling or missing
	 * `assetId`/`employeeId` while open, and on an asset whose status is not
	 * `uitgegeven`. A coherently-dated, already-closed assignment is never
	 * checked against the asset's current status -- historical uitgiftes may
	 * reference re-stocked or written-off assets.
	 *
	 * @param array<string, mixed> $o The AssetAssignment.
	 * @param array<string, mixed> $c Evaluation context (carries `related`).
	 *
	 * @return bool
	 */
	private static function assignmentConsistent(array $o, array $c): bool {
		$uitgifteDatum = trim((string)($o['uitgifteDatum'] ?? ''));
		$innameDatum = trim((string)($o['innameDatum'] ?? ''));

		if ($innameDatum !== '') {
			$uitgifte = strtotime($uitgifteDatum);
			$inname = strtotime($innameDatum);
			if ($uitgifte !== false && $inname !== false && $inname < $uitgifte) {
				return false;
			}

			// A closed uitgifte with coherent dates -- historical uitgiftes
			// may reference a re-stocked or written-off asset.
			return true;
		}

		$assetId = trim((string)($o['assetId'] ?? ''));
		if ($assetId === '') {
			return false;
		}

		$asset = (self::relatedAssetsById($c)[$assetId] ?? null);
		if (is_array($asset) === false || ($asset['status'] ?? '') !== 'uitgegeven') {
			return false;
		}

		$employeeId = trim((string)($o['employeeId'] ?? ''));
		if ($employeeId === '') {
			return false;
		}

		return array_key_exists($employeeId, self::relatedEmployeesById($c)) === true;
	}//end assignmentConsistent()

	/**
	 * True unless the assignment is open (no innameDatum) AND its
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
		$innameDatum = trim((string)($o['innameDatum'] ?? ''));
		if ($innameDatum !== '') {
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

}//end class
