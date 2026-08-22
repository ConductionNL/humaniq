<?php

/**
 * Humaniq TimesheetNotEmptyGuard
 *
 * OpenRegister lifecycle guard on the Timesheet `submit` transition
 * (hours-process-redesign): an empty timesheet — no bookings, or bookings
 * summing to zero hours — cannot be submitted for approval. Referenced from
 * the Timesheet schema `x-openregister-lifecycle.transitions.submit.requires`
 * in `lib/Settings/register.d/hr-timesheet.json`.
 *
 * Guards are read-only per OpenRegister's contract, so this guard reads only
 * the `entryCount` / `hours` aggregates on the payload it is handed — both
 * are server-maintained by TimesheetAggregationService (REQ-TEC-004), which
 * is exactly why `entryCount` exists as a stored property. Fails closed:
 * missing or malformed aggregates deny the transition rather than allowing a
 * submit on a guess.
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
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Denies submitting a timesheet without bookings or without hours.
 *
 * Fails closed: absent aggregates deny rather than allow on a guess.
 *
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
 */
class TimesheetNotEmptyGuard implements LifecycleGuardInterface {

	/**
	 * Authorise the `submit` transition by requiring a non-empty timesheet.
	 *
	 * @param array<string, mixed> $object The Timesheet payload at its current state.
	 * @param string $action The transition action ('submit').
	 * @param string $userId The uid of the user performing the transition.
	 *
	 * @return GuardResult Allow when the timesheet has at least one booking
	 *                     and more than zero hours; deny otherwise.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only the
	 *  static allow()/deny() factories mandated by OpenRegister's contract.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $action and $userId are part
	 *  of the LifecycleGuardInterface signature; emptiness does not depend on
	 *  who submits.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$entryCount = $object['entryCount'] ?? null;
		if (is_numeric($entryCount) === false || (int)$entryCount < 1) {
			return GuardResult::deny(
				'Deze urenstaat bevat geen urenboekingen en kan niet worden ingediend. '
				. 'Boek eerst uren voor deze periode.'
			);
		}

		$hours = $object['hours'] ?? null;
		if (is_numeric($hours) === false || (float)$hours <= 0.0) {
			return GuardResult::deny(
				'Deze urenstaat telt op tot nul uren en kan niet worden ingediend. '
				. 'Controleer de urenboekingen voor deze periode.'
			);
		}

		return GuardResult::allow();
	}//end check()

}//end class
