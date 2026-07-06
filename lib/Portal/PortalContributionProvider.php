<?php

/**
 * Hrmq Portal Contribution Provider
 *
 * Hrmq's contribution to the shared Portaliq external portal (hydra ADR-046 +
 * 2026-07-06 amendment, contribution contract v2). Portaliq — the one shared
 * external portal for people WITHOUT Nextcloud accounts — discovers this class
 * by convention FQCN (`OCA\{App}\Portal\PortalContributionProvider`) and
 * duck-types it via method_exists(), never instanceof. Therefore this class is
 * deliberately PLAIN: no portaliq imports, no `implements` clause, no info.xml
 * dependency, no constructor dependencies. Without portaliq installed it is
 * inert and hrmq behaves exactly as before (amendment A1).
 *
 * It declares two audiences: `external-employee` (payroll externals without an
 * NC account — payslips, contracts, own employee record, timesheets, expenses,
 * leave requests; create timesheet/expense/leave request) and `client` (the
 * client who reviews billable hours — read-only timesheets scoped by
 * clientRef). All scoping uses UUID domain-object references resolved from the
 * subject's server-managed claim map (`claims.hrmq.employeeId` /
 * `claims.hrmq.clientId`, amendment A4) — never Nextcloud user ids.
 *
 * @category Portal
 * @package  OCA\Hrmq\Portal
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
 * @spec openspec/changes/portal-contribution/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Hrmq\Portal;

/**
 * Declares what external employees and clients may see and do in hrmq's
 * section of the shared external portal.
 *
 * The contribution is a declarative manifest (pure data — no I/O, no
 * callbacks). All subject identity (subjectRef, audience, organisation, trust,
 * claims) is derived server-side by portaliq's auth edge and MUST never be
 * trusted from the client (ADR-005). Portaliq stamps the collection scope
 * field server-side on every create, so no scoping property appears in any
 * create-action field whitelist.
 *
 * @spec openspec/changes/portal-contribution/tasks.md#task-1
 */
class PortalContributionProvider
{
    /**
     * The audiences this provider contributes to (contract v2, preferred).
     *
     * The registry probes for this method first; the audience vocabulary is an
     * open string set (amendment A2). hrmq serves external employees (the HR
     * self-service story) and clients (billable-hours review).
     *
     * @return array<int, string> The audience identifiers.
     *
     * @spec openspec/changes/portal-contribution/tasks.md#task-2
     */
    public function getAudiences(): array
    {
        return [
            'external-employee',
            'client',
        ];

    }//end getAudiences()

    /**
     * The primary audience this provider contributes to (contract v1 fallback).
     *
     * Kept alongside getAudiences() so the provider also works against a v1
     * registry that predates multi-audience support. A v1 registry only sees
     * the external-employee contribution — the client view requires v2.
     *
     * @return string The primary audience identifier.
     *
     * @spec openspec/changes/portal-contribution/tasks.md#task-2
     */
    public function getAudience(): string
    {
        return 'external-employee';

    }//end getAudience()

    /**
     * Build the declarative portal manifest for one resolved subject.
     *
     * The subject array is server-derived by portaliq (subjectRef UUID,
     * audience, organisation, trust level low|substantial|high, claim map).
     * Returns null when hrmq has nothing for the subject — any audience other
     * than external-employee or client (fail-closed; the registry already
     * filters by audience, but a provider must not rely on that).
     *
     * Manifest vocabulary (amendment A2–A6): `collections` are read surfaces
     * portaliq serves from OpenRegister, scoped by `scopeField` == the claim
     * selected by `scopeClaim` (bare names resolve under `claims.hrmq.*`);
     * `actions` of type `create` expose strict field whitelists — status and
     * approval-stamp fields are excluded because the declarative
     * x-openregister-lifecycle owns every transition, and the scoping
     * `employeeId` is excluded because portaliq stamps it server-side.
     * `minTrust` is `low` everywhere in Wave 1 (employer-issued password
     * accounts); the raise plan is documented in this change's design.md.
     *
     * @param array<string, mixed> $subject The resolved portal subject.
     *
     * @return array<string, mixed>|null The manifest, or null when not contributing.
     *
     * @spec openspec/changes/portal-contribution/tasks.md#task-3
     */
    public function getContribution(array $subject): ?array
    {
        $audience = ($subject['audience'] ?? '');
        if ($audience === 'external-employee') {
            return $this->externalEmployeeManifest();
        }

        if ($audience === 'client') {
            return $this->clientManifest();
        }

        return null;

    }//end getContribution()

    /**
     * The external-employee manifest: HR self-service over the subject's own
     * records, scoped by the `employeeId` claim (the UUID of their Employee
     * domain object — the Employee schema has no Nextcloud-user link by
     * design, amendment A4).
     *
     * @return array<string, mixed> The manifest.
     *
     * @spec openspec/changes/portal-contribution/tasks.md#task-3
     */
    private function externalEmployeeManifest(): array
    {
        return [
            'label'         => 'HRMQ',
            'collections'   => [
                [
                    'id'         => 'myEmployeeRecord',
                    'register'   => 'hrmq',
                    'schema'     => 'Employee',
                    'scopeField' => 'id',
                    'scopeClaim' => 'employeeId',
                    'minTrust'   => 'low',
                    'label'      => 'My employee record',
                    'listable'   => false,
                ],
                [
                    'id'         => 'payslips',
                    'register'   => 'hrmq',
                    'schema'     => 'Payslip',
                    'scopeField' => 'employeeId',
                    'scopeClaim' => 'employeeId',
                    'minTrust'   => 'low',
                    'label'      => 'My payslips',
                    'listable'   => true,
                ],
                [
                    'id'         => 'employmentContracts',
                    'register'   => 'hrmq',
                    'schema'     => 'EmploymentContract',
                    'scopeField' => 'employeeId',
                    'scopeClaim' => 'employeeId',
                    'minTrust'   => 'low',
                    'label'      => 'My employment contracts',
                    'listable'   => true,
                ],
                [
                    'id'         => 'timesheets',
                    'register'   => 'hrmq',
                    'schema'     => 'Timesheet',
                    'scopeField' => 'employeeId',
                    'scopeClaim' => 'employeeId',
                    'minTrust'   => 'low',
                    'label'      => 'My timesheets',
                    'listable'   => true,
                ],
                [
                    'id'         => 'expenses',
                    'register'   => 'hrmq',
                    'schema'     => 'Expense',
                    'scopeField' => 'employeeId',
                    'scopeClaim' => 'employeeId',
                    'minTrust'   => 'low',
                    'label'      => 'My expenses',
                    'listable'   => true,
                ],
                [
                    'id'         => 'leaveRequests',
                    'register'   => 'hrmq',
                    'schema'     => 'LeaveRequest',
                    'scopeField' => 'employeeId',
                    'scopeClaim' => 'employeeId',
                    'minTrust'   => 'low',
                    'label'      => 'My leave requests',
                    'listable'   => true,
                ],
            ],
            'actions'       => [
                [
                    'id'       => 'createTimesheet',
                    'type'     => 'create',
                    'label'    => 'Log hours',
                    'register' => 'hrmq',
                    'schema'   => 'Timesheet',
                    'fields'   => [
                        'period',
                        'hours',
                        'description',
                        'projectId',
                        'costCenter',
                        'billable',
                        'clientRef',
                    ],
                ],
                [
                    'id'       => 'createExpense',
                    'type'     => 'create',
                    'label'    => 'Submit an expense',
                    'register' => 'hrmq',
                    'schema'   => 'Expense',
                    'fields'   => [
                        'title',
                        'description',
                        'amount',
                        'currency',
                        'category',
                        'expenseDate',
                    ],
                ],
                [
                    'id'       => 'createLeaveRequest',
                    'type'     => 'create',
                    'label'    => 'Request leave',
                    'register' => 'hrmq',
                    'schema'   => 'LeaveRequest',
                    'fields'   => [
                        'leaveType',
                        'startDate',
                        'endDate',
                        'hours',
                        'reason',
                    ],
                ],
            ],
            'notifications' => [],
        ];

    }//end externalEmployeeManifest()

    /**
     * The client manifest: a read-only view over the timesheets whose billable
     * hours the client reviews, scoped by `Timesheet.clientRef` == the
     * `clientId` claim (the UUID of the client contact/organisation domain
     * object). The approve/reject action is deliberately absent — lifecycle
     * transitions by externals require the bearer-forwarded endpoint action
     * type (amendment A6), whose receiver-side verification hrmq does not
     * implement in Wave 1.
     *
     * @return array<string, mixed> The manifest.
     *
     * @spec openspec/changes/portal-contribution/tasks.md#task-3
     */
    private function clientManifest(): array
    {
        return [
            'label'         => 'HRMQ',
            'collections'   => [
                [
                    'id'         => 'clientTimesheets',
                    'register'   => 'hrmq',
                    'schema'     => 'Timesheet',
                    'scopeField' => 'clientRef',
                    'scopeClaim' => 'clientId',
                    'minTrust'   => 'low',
                    'label'      => 'Timesheets to review',
                    'listable'   => true,
                ],
            ],
            'actions'       => [],
            'notifications' => [],
        ];

    }//end clientManifest()
}//end class
