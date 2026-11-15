<?php

/**
 * UbdReportReadyGuard
 *
 * A third-party payments report is made ready only when it carries a
 * validated message and no blocking finding (filings-ib47 D2).
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
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * A third-party payments report is made ready only with a validated message and no blocking finding.
 *
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */
class UbdReportReadyGuard implements LifecycleGuardInterface {

	/**
	 * Decide the transition.
	 *
	 * @param array<string, mixed> $object The ThirdPartyReport at its current state.
	 * @param string               $action The transition.
	 * @param string               $userId The acting account.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only the static allow()/deny() factories.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 *
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$blocking = ($object['blockingFindings'] ?? null);
		if (is_numeric($blocking) === false) {
			return GuardResult::deny('Stel het overzicht eerst samen.');
		}

		if ((int)$blocking > 0) {
			$messages = [];
			foreach ((array)($object['findings'] ?? []) as $finding) {
				if (is_array($finding) === true && ($finding['severity'] ?? '') === 'blocking') {
					$messages[] = (string)($finding['message'] ?? '');
				}
			}

			return GuardResult::deny('Het overzicht heeft ' . (int)$blocking . ' blokkerende bevinding(en): ' . implode(' ', $messages));
		}

		if (trim((string)($object['messageXml'] ?? '')) === '') {
			return GuardResult::deny('Het overzicht heeft nog geen bericht; stel het opnieuw samen.');
		}

		return GuardResult::allow();
	}//end check()

}//end class
