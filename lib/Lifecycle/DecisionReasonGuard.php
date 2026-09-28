<?php

/**
 * Humaniq DecisionReasonGuard
 *
 * OpenRegister lifecycle guard on the CompAdjustment `refuse` transition
 * (comp-collective-raise-and-step-increase design.md D6). A refused pay
 * change is an outcome the employee receives, so it cannot be refused
 * without a reason; and whoever proposed it cannot refuse it, which is the
 * separation-of-duties rule `NoSelfApprovalGuard` already holds for approve
 * and reject. The transition's declared `inputs` make the reason required on
 * the transition endpoint too; this guard also covers a plain object write
 * that moves the status to `refused`, which never passes through `inputs`.
 *
 * Read-only, as OpenRegister's guard contract requires.
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
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Denies a refusal without a reason, or by its proposer.
 *
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-005
 */
class DecisionReasonGuard implements LifecycleGuardInterface {

	/**
	 * @param NoSelfApprovalGuard $noSelfApproval The proposer and subject rule, chained after the reason check.
	 */
	public function __construct(
		private readonly NoSelfApprovalGuard $noSelfApproval,
	) {

	}//end __construct()

	/**
	 * Allow the refusal only with a non-empty reason and a decider who is
	 * neither the proposer nor the employee.
	 *
	 * @param array<string, mixed> $object The CompAdjustment as it would be saved.
	 * @param string $action The transition action ('refuse').
	 * @param string $userId The uid of the person refusing.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) GuardResult exposes only the static
	 *  allow()/deny() factories mandated by OpenRegister's contract.
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-005
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$reason = trim((string)($object['decisionReason'] ?? ''));
		if ($reason === '') {
			return GuardResult::deny('Geef een reden op: de medewerker krijgt die te lezen.');
		}

		return $this->noSelfApproval->check($object, $action, $userId);
	}//end check()

}//end class
