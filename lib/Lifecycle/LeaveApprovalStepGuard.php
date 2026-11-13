<?php

/**
 * Humaniq Leave Approval Step Guard
 *
 * The lifecycle guard on `approve-manager`, `approve` and `reject` of a leave
 * request. It first asks NoSelfApprovalGuard (nobody decides on their own
 * request), then decides which step applies:
 *
 * - a leave type that needs HR (`requiresHrApproval`, or more hours than its
 *   `hrApprovalAboveHours`) goes `submitted -> manager-approved -> approved`,
 *   so a direct `approve` from `submitted` is refused;
 * - a type that needs no HR goes straight to `approved`, so
 *   `approve-manager` is refused;
 * - from `manager-approved`, `approve` and `reject` need HR rights and a
 *   different person than the manager who approved.
 *
 * Fails closed: when the leave types cannot be read, nothing is approved.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leave-approval-steps-and-history/specs/leave-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\Humaniq\Service\LeaveTypeResolver;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;

/**
 * Which approval step a leave request is at, and who may take it.
 *
 * @spec openspec/changes/leave-approval-steps-and-history/specs/leave-management/spec.md
 */
final class LeaveApprovalStepGuard implements LifecycleGuardInterface {

	public const ACTION_APPROVE_MANAGER = 'approve-manager';
	public const STATUS_MANAGER_APPROVED = 'manager-approved';

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface $gateway      Reads the leave types.
	 * @param IGroupManager        $groupManager The HR check (admin until distinct HR roles land).
	 * @param LeaveTypeResolver    $resolver     Matches a request to its leave type.
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
		private readonly IGroupManager $groupManager,
		private readonly LeaveTypeResolver $resolver = new LeaveTypeResolver(),
	) {
	}//end __construct()

	/**
	 * Allow or refuse one step on a leave request.
	 *
	 * @param array<string, mixed> $object The leave request as stored, at its current status.
	 * @param string               $action approve-manager, approve or reject.
	 * @param string               $userId The acting user.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/leave-approval-steps-and-history/specs/leave-management/spec.md
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$selfApproval = (new NoSelfApprovalGuard())->check($object, $action, $userId);
		if ($selfApproval->isAllowed() === false) {
			return $selfApproval;
		}

		if ((string)($object['status'] ?? '') === self::STATUS_MANAGER_APPROVED) {
			return $this->hrStep(object: $object, action: $action, userId: $userId);
		}

		return $this->managerStep(object: $object, action: $action);
	}//end check()

	/**
	 * The manager's step, from `submitted`.
	 *
	 * @param array<string, mixed> $object The leave request.
	 * @param string               $action The action.
	 *
	 * @return GuardResult
	 */
	private function managerStep(array $object, string $action): GuardResult {
		if ($action === 'reject') {
			return GuardResult::allow();
		}

		$needsHr = $this->needsHr(object: $object);
		if ($needsHr === null) {
			return GuardResult::deny('De verlofsoorten konden niet worden gelezen, dus de goedkeuringsstap is niet bepaald. Probeer het later opnieuw.');
		}

		if ($action === self::ACTION_APPROVE_MANAGER && $needsHr === false) {
			return GuardResult::deny('Deze verlofsoort heeft geen goedkeuring door HR nodig; keur de aanvraag direct goed.');
		}

		if ($action === 'approve' && $needsHr === true) {
			return GuardResult::deny('Deze aanvraag moet na de manager ook door HR worden goedgekeurd; gebruik "Goedkeuren als manager".');
		}

		return GuardResult::allow();
	}//end managerStep()

	/**
	 * HR's step, from `manager-approved`: HR rights, and not the manager who approved.
	 *
	 * @param array<string, mixed> $object The leave request.
	 * @param string               $action The action.
	 * @param string               $userId The acting user.
	 *
	 * @return GuardResult
	 */
	private function hrStep(array $object, string $action, string $userId): GuardResult {
		if ($action === self::ACTION_APPROVE_MANAGER) {
			return GuardResult::deny('De manager heeft deze aanvraag al goedgekeurd; HR beslist nu.');
		}

		if ($this->groupManager->isAdmin($userId) === false) {
			return GuardResult::deny('Alleen HR mag deze stap nemen.');
		}

		if (in_array($userId, self::managerApprovers(object: $object), true) === true) {
			return GuardResult::deny('U heeft deze aanvraag al als manager goedgekeurd; iemand anders van HR moet beslissen.');
		}

		return GuardResult::allow();
	}//end hrStep()

	/**
	 * Whether the request's leave type needs HR after the manager, or null when
	 * the types cannot be read.
	 *
	 * @param array<string, mixed> $object The leave request.
	 *
	 * @return bool|null
	 */
	private function needsHr(array $object): ?bool {
		try {
			$types = $this->gateway->loadAll('LeaveType');
		} catch (\Throwable $e) {
			return null;
		}

		$type = $this->resolver->resolve(request: $object, types: $types);
		if ($type === null) {
			return false;
		}

		if (($type['requiresHrApproval'] ?? false) === true) {
			return true;
		}

		$above = ($type['hrApprovalAboveHours'] ?? null);
		$hours = ($object['hours'] ?? null);

		return is_numeric($above) === true && is_numeric($hours) === true && (float)$hours > (float)$above;
	}//end needsHr()

	/**
	 * Who approved this request at the manager step.
	 *
	 * @param array<string, mixed> $object The leave request.
	 *
	 * @return list<string>
	 */
	private static function managerApprovers(array $object): array {
		$approvers = [];
		$decisions = ($object['decisions'] ?? []);
		if (is_array($decisions) === false) {
			return $approvers;
		}

		foreach ($decisions as $decision) {
			if (is_array($decision) === true && ($decision['step'] ?? '') === 'manager' && ($decision['decision'] ?? '') === 'approved') {
				$approvers[] = (string)($decision['by'] ?? '');
			}
		}

		return $approvers;
	}//end managerApprovers()
}//end class
