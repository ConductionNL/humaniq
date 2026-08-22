<?php

/**
 * Humaniq CompEffectiveDateGuard
 *
 * OpenRegister lifecycle guard for the CompAdjustment `effectuate` transition
 * (comp-cycles). It enforces the one precondition the declarative
 * `x-openregister-lifecycle` state machine cannot express on its own: an
 * approved compensation adjustment may only become effective once its
 * `effectiveDate` has actually arrived — never early, so a future-dated
 * adjustment cannot jump ahead of its intended effective date.
 *
 * Fail-closed (design.md D4): an empty, missing, or malformed `effectiveDate`
 * denies the transition rather than allowing it on a guess — the same
 * discipline as `PayrollRunApprovedGuard`. Unlike `PayrollRunApprovedGuard`,
 * this guard needs no cross-object load (no `ContainerInterface`/`IAppConfig`
 * dependency): the date it decides on lives directly on the payload being
 * transitioned, so it is constructed exactly like the stateless
 * `NoSelfApprovalGuard` — injecting unused collaborators here would itself be
 * the orphaned/unused-dependency anti-pattern the fleet's quality gates flag.
 *
 * Read-only per OpenRegister's contract: it never writes the employee's
 * `grossMonthlySalary` — that effective-dated write is the imperative job of
 * `CompAdjustmentService`, which re-drives this same transition after writing
 * (so the state machine and the write can never disagree).
 *
 * Referenced from the CompAdjustment schema
 * `x-openregister-lifecycle.transitions.effectuate.requires`.
 *
 * @category Lifecycle
 * @package  OCA\Humaniq\Lifecycle
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
 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Denies the CompAdjustment `effectuate` transition unless `effectiveDate` is
 * present and on or before today.
 *
 * Fails closed: an empty or unparsable `effectiveDate` denies the transition
 * rather than allowing it on a guess.
 */
final class CompEffectiveDateGuard implements LifecycleGuardInterface {

	/**
	 * Authorise the `effectuate` transition by checking the adjustment's own
	 * `effectiveDate`.
	 *
	 * @param array<string, mixed> $object The CompAdjustment payload at its current state.
	 * @param string $action The transition action ('effectuate').
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow when `effectiveDate` is present and on or
	 *                     before today; deny otherwise (fail-closed).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only the
	 *  static allow()/deny() factories mandated by OpenRegister's contract.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $action/$userId are part of
	 *  the LifecycleGuardInterface signature; the gate depends only on the
	 *  adjustment's own effectiveDate, not on who is acting or which action fired.
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-005
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$effectiveDate = trim((string)($object['effectiveDate'] ?? ''));
		if ($effectiveDate === '') {
			return GuardResult::deny(
				'Deze aanpassing heeft geen ingangsdatum; effectueren is geweigerd.'
			);
		}

		$effectiveTimestamp = strtotime($effectiveDate);
		if ($effectiveTimestamp === false) {
			return GuardResult::deny(
				'De ingangsdatum van deze aanpassing kon niet worden gelezen; effectueren is geweigerd.'
			);
		}

		$today = strtotime('today');
		if ($effectiveTimestamp > $today) {
			return GuardResult::deny(sprintf(
				'De ingangsdatum (%s) ligt in de toekomst; effectueren kan pas op of na deze datum.',
				$effectiveDate
			));
		}

		return GuardResult::allow();
	}//end check()

}//end class
