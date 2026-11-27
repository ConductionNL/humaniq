<?php

/**
 * PensionMessageGuard
 *
 * The guard on a PensionFiling's `controleren`: first the referenced payroll
 * run must be approved (PayrollRunApprovedGuard), then, for a fund with a
 * PensionScheme in the period, every employee in the scheme must be
 * reportable. A refusal names each employee and what is missing, such as an
 * employment contract or its hours per week (filings-pension-upa-message D3).
 *
 * A fund without a scheme in the period passes as it did before: its
 * delivery is still made outside humaniq. The guard reads only; it does not
 * render or store the message (OpenRegister guards are read-only).
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
 * @spec openspec/changes/filings-pension-upa-message/specs/pension-upa-message/spec.md#REQ-PUM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\Humaniq\Service\PensionMessageService;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Refuses checking a pension filing whose employees cannot all be reported.
 *
 * @spec openspec/changes/filings-pension-upa-message/specs/pension-upa-message/spec.md#REQ-PUM-001
 */
class PensionMessageGuard implements LifecycleGuardInterface {

	/**
	 * The guard.
	 *
	 * @param LifecycleGuardInterface $runApproved The approved-run rule, checked first (PayrollRunApprovedGuard).
	 * @param PensionMessageService   $messages    The filing's scheme, lines and findings.
	 */
	public function __construct(
		private readonly LifecycleGuardInterface $runApproved,
		private readonly PensionMessageService $messages,
	) {

	}//end __construct()

	/**
	 * Allow the check when the run is approved and, under a scheme, no
	 * employee in it is left unreportable.
	 *
	 * @param array<string, mixed> $object The PensionFiling at its current state.
	 * @param string               $action The transition ('controleren').
	 * @param string               $userId The acting account.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) GuardResult exposes only the static allow()/deny() factories.
	 *
	 * @spec openspec/changes/filings-pension-upa-message/specs/pension-upa-message/spec.md#REQ-PUM-001
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$approved = $this->runApproved->check($object, $action, $userId);
		if ($approved->isAllowed() === false) {
			return $approved;
		}

		try {
			$outcome = $this->messages->lines($object);
		} catch (\Throwable $e) {
			return GuardResult::deny('De pensioenaangifte kon niet worden doorgerekend; controleren is geweigerd.');
		}

		if ($outcome['scheme'] === null || $outcome['findings'] === []) {
			return GuardResult::allow();
		}

		$problems = array_map(static fn (array $finding): string => $finding['problem'], $outcome['findings']);

		return GuardResult::deny('De pensioenaangifte kan niet worden aangeleverd: ' . implode(' ', $problems));
	}//end check()

}//end class
