<?php

/**
 * LoonaangifteMessageGuard
 *
 * A Dutch wage tax filing is made ready (klaarzetten) only when it carries
 * a message validated against the year's XSD and its last render left no
 * blocking finding (filings-wage-tax-message D1, D3). Filings of other
 * jurisdictions and kinds pass unchanged.
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * A Dutch wage tax filing is made ready only with a validated message and no blocking finding.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
 */
class LoonaangifteMessageGuard implements LifecycleGuardInterface {

	/**
	 * Decide the transition.
	 *
	 * @param array<string, mixed> $object The LoonaangifteFiling at its current state.
	 * @param string               $action The transition.
	 * @param string               $userId The acting account.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only the static allow()/deny() factories.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ((string)($object['jurisdiction'] ?? '') !== 'NL' || in_array((string)($object['filingType'] ?? ''), ['loonaangifte', 'correctie'], true) === false) {
			return GuardResult::allow();
		}

		$blocking = ($object['blockingFindings'] ?? null);
		if (is_numeric($blocking) === false) {
			return GuardResult::deny('Maak eerst het aangiftebericht.');
		}

		if ((int)$blocking > 0) {
			$problems = [];
			foreach ((array)($object['messageFindings'] ?? []) as $finding) {
				if (is_array($finding) === true && ($finding['severity'] ?? '') === 'blocking') {
					$problems[] = (string)($finding['problem'] ?? '');
				}
			}

			return GuardResult::deny('Het aangiftebericht heeft ' . (int)$blocking . ' blokkerende bevinding(en): ' . implode(' ', $problems));
		}

		if (($object['filingType'] ?? '') === 'correctie' && ($object['correctionRoute'] ?? '') === 'volgende-aangifte' && is_array($object['correctionTree'] ?? null) === true && $object['correctionTree'] !== []) {
			return GuardResult::allow();
		}

		if (trim((string)($object['messageXml'] ?? '')) === '') {
			return GuardResult::deny('De aangifte heeft nog geen bericht; maak het bericht opnieuw.');
		}

		return GuardResult::allow();
	}//end check()

}//end class
