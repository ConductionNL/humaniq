<?php

/**
 * Humaniq HrReopenGuard
 *
 * OpenRegister lifecycle guard on the Expense `reopen` transition (approved to
 * submitted). Only a member of the HR group may send an approved claim back to
 * approval, and not when they are the claimant or the person who approved it.
 * A claim a payroll run holds is never reopened. OpenRegister hands the guard
 * the INCOMING payload, so the guard reads the claim as stored, by its id, and
 * judges that. Fails closed when the acting account is unknown or the stored
 * claim cannot be read.
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
use OCA\Humaniq\Service\RecordStoreInterface;
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
	 * @param RecordStoreInterface $store Reads the stored claim.
	 */
	public function __construct(
		private readonly HumaniqRoles $roles,
		private readonly RecordStoreInterface $store,
	) {

	}//end __construct()

	/**
	 * Authorise a reopen.
	 *
	 * @param array<string, mixed> $object The incoming payload; only its id is used.
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

		$stored = $this->storedClaim(payload: $object);
		if ($stored === null) {
			return GuardResult::deny('De goedgekeurde declaratie kon niet worden gelezen; heropenen is geweigerd.');
		}

		if (in_array($userId, [trim((string)($stored['userId'] ?? '')), trim((string)($stored['approvedBy'] ?? ''))], true) === true) {
			return GuardResult::deny('U mag een declaratie die u zelf indiende of goedkeurde niet heropenen.');
		}

		if (trim((string)($stored['payrollRunId'] ?? '')) !== '') {
			return GuardResult::deny('Deze declaratie zit in een salarisrun en kan niet meer worden heropend.');
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * The claim as stored, by the payload's id, when it is approved; else null.
	 *
	 * @param array<string, mixed> $payload The incoming payload.
	 *
	 * @return array<string, mixed>|null
	 */
	private function storedClaim(array $payload): ?array {
		$id = trim((string)($payload['id'] ?? ($payload['@self']['id'] ?? ($payload['uuid'] ?? ''))));
		if ($id === '') {
			return null;
		}

		$stored = $this->store->findObjectData($id, 'Expense');
		if ($stored === null || trim((string)($stored['status'] ?? '')) !== 'approved') {
			return null;
		}

		return $stored;
	}//end storedClaim()

}//end class
