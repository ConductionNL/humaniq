<?php

/**
 * Leave Type Condition Guard
 *
 * Denies the `LeaveRequest` `submit` transition when the request's leave type
 * asks for something the request does not carry: a reason, a document, or a
 * start no further ahead than the type allows.
 *
 * WHY A GUARD AND NOT A CHECK IN A CONTROLLER
 * -------------------------------------------
 * humaniq's leave pages are declarative: a request is submitted straight
 * through OpenRegister's transition, from the back office, from the portal and
 * from the department schedule this change adds. A condition enforced in one
 * controller is a condition the other two surfaces never see, and the third
 * surface is the one this change is building.
 *
 * FAIL OPEN ON AN UNADMINISTERED TYPE, CLOSED ON AN UNREADABLE ONE
 * ----------------------------------------------------------------
 * A request whose type nobody administered carries no conditions, so it
 * submits: refusing it would break every instance that has not written its
 * types yet, including every instance upgrading into this change.
 *
 * A type list that cannot be READ is the other case. There the conditions may
 * exist and could not be checked, so the submit is denied and says so, because
 * an unchecked condition that silently passes is indistinguishable from one
 * that was satisfied.
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
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-T02
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use DateTimeImmutable;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\LeaveSubmissionConditionService;
use OCA\Humaniq\Service\LeaveTypeResolver;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Denies a leave submit whose type's own conditions are not met.
 *
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-T02
 */
final class LeaveTypeConditionGuard implements LifecycleGuardInterface {

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The shared register plumbing (the LeaveType lookup).
	 * @param LeaveTypeResolver $resolver Resolves a request's stored code to its type.
	 * @param LeaveSubmissionConditionService $conditions The conditions themselves.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly LeaveTypeResolver $resolver = new LeaveTypeResolver(),
		private readonly LeaveSubmissionConditionService $conditions = new LeaveSubmissionConditionService(),
	) {

	}//end __construct()

	/**
	 * Authorise the `submit` transition against the type's conditions.
	 *
	 * @param array<string, mixed> $object The LeaveRequest payload at its current state.
	 * @param string $action The transition action ('submit').
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow when the type's conditions are met; deny naming the condition otherwise.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only the
	 *  static allow()/deny() factories mandated by OpenRegister's contract.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $action/$userId are part of
	 *  the LifecycleGuardInterface signature; a type's conditions depend on the
	 *  request and its type, not on who submits it.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-T02
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		try {
			$types = $this->gateway->loadAll('LeaveType');
		} catch (\Throwable $e) {
			return GuardResult::deny(
				'De verlofsoorten konden niet worden gelezen, dus de voorwaarden voor deze aanvraag zijn niet '
				. 'gecontroleerd. Probeer het later opnieuw.'
			);
		}

		$type = $this->resolver->resolve(request: $object, types: $types);
		$refusal = $this->conditions->refusal(
			request: $object,
			type: $type,
			today: new DateTimeImmutable('today')
		);

		if ($refusal !== null) {
			return GuardResult::deny($refusal);
		}

		return GuardResult::allow();
	}//end check()
}//end class
