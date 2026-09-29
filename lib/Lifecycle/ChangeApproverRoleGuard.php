<?php

/**
 * Humaniq ChangeApproverRoleGuard
 *
 * OpenRegister lifecycle guard on the `goedkeuren` and `afwijzen`
 * transitions of `EmployeeChangeRequest` (people-record-change-approval
 * D2). A request may be decided only by a user holding its `approverRole`
 * in the request's administration (`AdministrationAccess.role`), or for the
 * `manager` role by the employee's manager (`managerUserId`). A Nextcloud
 * administrator keeps every right. Never the employee the request is about,
 * and never the person who asked: a change is always seen by a second person.
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
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\Humaniq\Service\AdministrationService;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;

/**
 * Lets only the approver role of a change request decide on it.
 *
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
 */
class ChangeApproverRoleGuard implements LifecycleGuardInterface {

	/**
	 * Constructor.
	 *
	 * @param AdministrationService $administrations The caller's role per administration.
	 * @param IGroupManager         $groupManager    Whether the caller is an administrator.
	 */
	public function __construct(
		private readonly AdministrationService $administrations,
		private readonly IGroupManager $groupManager,
	) {

	}//end __construct()

	/**
	 * Allow the decision only for the request's approver.
	 *
	 * @param array<string, mixed> $object The request.
	 * @param string               $action The transition.
	 * @param string               $userId The acting user.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only the
	 *  static allow()/deny() factories mandated by OpenRegister's contract.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $action is part of the
	 *  LifecycleGuardInterface signature; the same rule applies to both
	 *  goedkeuren and afwijzen.
	 *
	 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$refusal = $this->secondPersonRefusal($object, $userId);
		if ($refusal !== null) {
			return GuardResult::deny($refusal);
		}

		if ($this->mayDecide($object, $userId) === true) {
			return GuardResult::allow();
		}

		$role = (string)($object['approverRole'] ?? '');
		if ($role === 'manager') {
			return GuardResult::deny('Alleen de leidinggevende van deze medewerker beslist over deze wijziging.');
		}

		return GuardResult::deny(sprintf('Deze wijziging moet worden beoordeeld door iemand met de rol %s in deze administratie.', $role === '' ? 'onbekend' : $role));
	}//end check()

	/**
	 * Why the user may not decide as the wrong person, or null.
	 *
	 * @param array<string, mixed> $object The request.
	 * @param string               $userId The acting user.
	 *
	 * @return string|null
	 */
	private function secondPersonRefusal(array $object, string $userId): ?string {
		if ($userId === '') {
			return 'U moet ingelogd zijn om over een wijzigingsverzoek te beslissen.';
		}

		if (trim((string)($object['userId'] ?? '')) === $userId) {
			return 'U mag niet beslissen over een wijziging van uw eigen gegevens. Een tweede persoon moet dit doen.';
		}

		if (trim((string)($object['requestedBy'] ?? '')) === $userId) {
			return 'U heeft deze wijziging zelf aangevraagd en mag er niet over beslissen. Een tweede persoon moet dit doen.';
		}

		return null;
	}//end secondPersonRefusal()

	/**
	 * Whether the user holds the request's approver role.
	 *
	 * @param array<string, mixed> $object The request.
	 * @param string               $userId The acting user.
	 *
	 * @return bool
	 */
	private function mayDecide(array $object, string $userId): bool {
		$role = (string)($object['approverRole'] ?? '');
		if ($this->groupManager->isAdmin($userId) === true || $role === 'none') {
			return true;
		}

		if ($role === 'manager') {
			return trim((string)($object['managerUserId'] ?? '')) === $userId;
		}

		return $this->holdsRole(userId: $userId, role: $role, administrationId: (string)($object['administrationId'] ?? ''));
	}//end mayDecide()

	/**
	 * Whether the user holds the role in the administration.
	 *
	 * @param string $userId           The user.
	 * @param string $role             The role.
	 * @param string $administrationId The administration.
	 *
	 * @return bool
	 */
	private function holdsRole(string $userId, string $role, string $administrationId): bool {
		if ($role === '' || $administrationId === '') {
			return false;
		}

		foreach ($this->administrations->accessibleAdministrations($userId) as $access) {
			if ($access['administrationId'] === $administrationId && $access['role'] === $role) {
				return true;
			}
		}

		return false;
	}//end holdsRole()

}//end class
