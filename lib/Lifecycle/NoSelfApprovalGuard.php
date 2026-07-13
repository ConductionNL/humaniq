<?php

/**
 * Hrmq NoSelfApprovalGuard
 *
 * OpenRegister lifecycle guard shared by the Timesheet and Expense `approve`
 * and `reject` transitions, and by the PerformanceReview `vaststellen`
 * transition (performance-reviews-mvp). It enforces the one approval rule
 * that the declarative `x-openregister-lifecycle` state machine cannot
 * express on its own: separation of duties — the user approving, rejecting
 * or vaststellen a submitted timesheet / expense claim / beoordeling MUST
 * NOT be the employee who claimed it or is under review.
 *
 * The state machine (submitted → approved/rejected; besproken → vastgesteld)
 * and the field defaults are fully declarative in the register fragments;
 * this guard adds only the cross-actor check
 * (`$userId !== $object['employeeId']`). Guards are read-only per
 * OpenRegister's contract — the approver/timestamp stamping happens through
 * the ordinary object write that carries the transition, not here.
 *
 * Referenced from the Timesheet / Expense schema
 * `x-openregister-lifecycle.transitions.{approve,reject}.requires` and from
 * the PerformanceReview schema
 * `x-openregister-lifecycle.transitions.vaststellen.requires`.
 *
 * @category Lifecycle
 * @package  OCA\Hrmq\Lifecycle
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
 * @spec openspec/changes/hrmq-timesheet-approval/specs/hrmq-timesheet-approval/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Denies self-approval / self-rejection of a timesheet or expense claim.
 *
 * Fails closed: when the acting user cannot be identified, or the claiming
 * employee is unknown, the transition is denied rather than allowed on a guess.
 */
class NoSelfApprovalGuard implements LifecycleGuardInterface
{


    /**
     * Authorise an approve/reject transition by enforcing separation of duties.
     *
     * @param array<string, mixed> $object The Timesheet/Expense payload at its current state.
     * @param string               $action The transition action ('approve' or 'reject').
     * @param string               $userId The uid of the manager performing the transition.
     *
     * @return GuardResult Allow when the acting user differs from the claiming
     *                     employee; deny otherwise.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only the
     *  static allow()/deny() factories mandated by OpenRegister's contract.
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $action is part of the
     *  LifecycleGuardInterface signature; the same separation-of-duties rule
     *  applies to both approve and reject.
     *
     * @spec openspec/changes/hrmq-timesheet-approval/specs/hrmq-timesheet-approval/spec.md
     */
    public function check(array $object, string $action, string $userId): GuardResult
    {
        if ($userId === '') {
            return GuardResult::deny('U moet ingelogd zijn om goed te keuren of af te keuren.');
        }

        $employeeId = (string) ($object['employeeId'] ?? '');
        if ($employeeId === '') {
            return GuardResult::deny('De indiener van deze declaratie/urenstaat is onbekend; goedkeuring is geweigerd.');
        }

        if ($employeeId === $userId) {
            return GuardResult::deny(
                'U mag uw eigen urenstaat of declaratie niet goedkeuren of afkeuren. '
                .'Een andere medewerker (manager) moet dit doen.'
            );
        }

        return GuardResult::allow();

    }//end check()


}//end class
