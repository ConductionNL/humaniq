<?php

/**
 * Humaniq HrReopenGuard
 *
 * OpenRegister lifecycle guard on the Expense `reopen` transition (approved to
 * submitted). Only a member of the HR group may send an approved claim back to
 * approval, and not when they are the claimant or the person who approved it.
 * A claim a payroll run holds is never reopened. Fails closed when the acting
 * account is unknown.
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
 * @spec openspec/changes/hr-reopens-an-approved-claim/specs/humaniq-expenses/spec.md#Requirement:-HR-SHALL-be-able-to-reopen-an-approved-expense-claim-that-no-payroll-run-holds
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\Humaniq\Service\HumaniqRoles;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Allows HR, and only HR outside the claim, to reopen an approved claim.
 *
 * @spec openspec/changes/hr-reopens-an-approved-claim/specs/humaniq-expenses/spec.md#Requirement:-HR-SHALL-be-able-to-reopen-an-approved-expense-claim-that-no-payroll-run-holds
 */
class HrReopenGuard implements LifecycleGuardInterface {

	/**
	 * Constructor.
	 *
	 * @param HumaniqRoles $roles Who counts as HR.
	 */
	public function __construct(
		private readonly HumaniqRoles $roles,
	) {

	}//end __construct()

	/**
	 * Authorise a reopen.
	 *
	 * @param array<string, mixed> $object The claim at its current state.
	 * @param string $action The transition action.
	 * @param string $userId The acting account.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only static factories.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $action is part of the guard contract.
	 *
	 * @spec openspec/changes/hr-reopens-an-approved-claim/specs/humaniq-expenses/spec.md#Requirement:-HR-SHALL-be-able-to-reopen-an-approved-expense-claim-that-no-payroll-run-holds
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->roles->isHr($userId) === false) {
			return GuardResult::deny('Alleen HR kan een goedgekeurde declaratie heropenen.');
		}

		if (in_array($userId, [trim((string)($object['userId'] ?? '')), trim((string)($object['approvedBy'] ?? ''))], true) === true) {
			return GuardResult::deny('U mag een declaratie die u zelf indiende of goedkeurde niet heropenen.');
		}

		if (trim((string)($object['payrollRunId'] ?? '')) !== '') {
			return GuardResult::deny('Deze declaratie zit in een salarisrun en kan niet meer worden heropend.');
		}

		return GuardResult::allow();
	}//end check()

}//end class
