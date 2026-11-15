<?php

/**
 * HandoffReleaseGuard
 *
 * Four eyes before a payroll handoff leaves: the person who compiled it cannot set it ready (payroll-external-bureau-handoff D3).
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
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Four eyes before a payroll handoff leaves: the person who compiled it cannot set it ready (payroll-external-bureau-handoff D3).
 *
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-003
 */
class HandoffReleaseGuard implements LifecycleGuardInterface {

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
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-003
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$compiledBy = trim((string)($object['compiledBy'] ?? ''));
		if ($userId === '' || $compiledBy === '') {
			return GuardResult::deny('Alleen een aangemelde tweede persoon kan een samengestelde overdracht klaarzetten.');
		}

		if ($compiledBy === $userId) {
			return GuardResult::deny('U heeft deze overdracht zelf samengesteld; een tweede persoon moet hem klaarzetten.');
		}

		return GuardResult::allow();
	}//end check()

}//end class
