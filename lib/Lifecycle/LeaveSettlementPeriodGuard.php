<?php

/**
 * Humaniq LeaveSettlementPeriodGuard
 *
 * OpenRegister lifecycle guard for the LeaveTransaction `settle` transition
 * (leave-buy-sell). It enforces the one precondition the declarative
 * `x-openregister-lifecycle` state machine cannot express on its own: a
 * transaction may only settle once it carries a well-formed `settlementPeriod`
 * (`YYYY-MM`) — the wage period `PayrollRunService.generate()` folds it into.
 *
 * Fail-closed (design.md D5): an empty or malformed `settlementPeriod` denies
 * the transition rather than allowing it on a guess — the same discipline as
 * `CompEffectiveDateGuard`. Stateless (no DI — the field it checks lives
 * directly on the payload being transitioned), constructed exactly like
 * `CompEffectiveDateGuard`/`NoSelfApprovalGuard`: injecting an unused
 * `ContainerInterface` here would itself be the orphaned/unused-dependency
 * anti-pattern the fleet's quality gates flag.
 *
 * Read-only per OpenRegister's contract: it never writes
 * `LeaveBalance.bovenwettelijkHours` — that write is the imperative job of
 * `LeaveBuySellSettlementService`, which re-drives this same transition after
 * writing (so the state machine and the write can never disagree).
 *
 * Referenced from the LeaveTransaction schema
 * `x-openregister-lifecycle.transitions.settle.requires`.
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
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Denies the LeaveTransaction `settle` transition unless `settlementPeriod`
 * is present and shaped `YYYY-MM`.
 *
 * Fails closed: an empty or malformed `settlementPeriod` denies the
 * transition rather than allowing it on a guess.
 */
final class LeaveSettlementPeriodGuard implements LifecycleGuardInterface {

	/**
	 * Authorise the `settle` transition by checking the transaction's own
	 * `settlementPeriod`.
	 *
	 * @param array<string, mixed> $object The LeaveTransaction payload at its current state.
	 * @param string $action The transition action ('settle').
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow when `settlementPeriod` is present and shaped
	 *                     `YYYY-MM`; deny otherwise (fail-closed).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only the
	 *  static allow()/deny() factories mandated by OpenRegister's contract.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $action/$userId are part of
	 *  the LifecycleGuardInterface signature; the gate depends only on the
	 *  transaction's own settlementPeriod, not on who is acting.
	 *
	 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-004
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$settlementPeriod = trim((string)($object['settlementPeriod'] ?? ''));
		if ($settlementPeriod === '') {
			return GuardResult::deny(
				'Deze transactie heeft geen verrekenperiode; verrekenen is geweigerd.'
			);
		}

		if (preg_match('/^\d{4}-\d{2}$/', $settlementPeriod) !== 1) {
			return GuardResult::deny(sprintf(
				'De verrekenperiode ("%s") heeft niet de vorm JJJJ-MM; verrekenen is geweigerd.',
				$settlementPeriod
			));
		}

		return GuardResult::allow();
	}//end check()

}//end class
