<?php

/**
 * HandoffCloseGuard
 *
 * A payroll handoff closes only when the intake check ran and found nothing blocking (payroll-external-bureau-handoff D4).
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
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * A payroll handoff closes only when the intake check ran and found nothing blocking (payroll-external-bureau-handoff D4).
 *
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-004
 */
class HandoffCloseGuard implements LifecycleGuardInterface {

	/**
	 * Decide the transition.
	 *
	 * @param array<string, mixed> $object The PayrollHandoff at its current state.
	 * @param string               $action The transition.
	 * @param string               $userId The acting account.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only the static allow()/deny() factories.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-004
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$blocking = ($object['blockingFindings'] ?? null);
		if (is_numeric($blocking) === false) {
			return GuardResult::deny('Voer eerst de controle van de teruggeleverde loonstroken uit.');
		}

		if ((int)$blocking > 0) {
			return GuardResult::deny('De controle vond ' . (int)$blocking . ' blokkerende bevinding(en); los die eerst op.');
		}

		return GuardResult::allow();
	}//end check()

}//end class
