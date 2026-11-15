<?php

/**
 * Humaniq OrgLookupInterface
 *
 * Looks up org facts: an employee's manager and cost centre, the employee
 * behind a Nextcloud account, and a schema's slug.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Org-chain and identity lookups against the humaniq register.
 *
 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */
interface OrgLookupInterface {
	/**
	 * The employee's unique resolved manager Nextcloud user id on a date, or
	 * null when the chain is unresolved or ambiguous.
	 *
	 * @param string $employeeId The employee id.
	 * @param string $onDate     The reference date, `YYYY-MM-DD`.
	 *
	 * @return string|null The manager's Nextcloud user id, or null.
	 *
	 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	public function uniqueManagerUserIdFor(string $employeeId, string $onDate): ?string;

	/**
	 * The employee's unique resolved cost centre on a date, or null when the
	 * chain is unresolved or ambiguous.
	 *
	 * @param string $employeeId The employee id.
	 * @param string $onDate     The reference date, `YYYY-MM-DD`.
	 *
	 * @return string|null The cost centre, or null.
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md#Requirement:-Cost-allocation-references-live-on-the-time-entry-and-are-never-employee-typed
	 */
	public function uniqueCostCenterFor(string $employeeId, string $onDate): ?string;

	/**
	 * The Employee whose `nextcloudUserId` equals the given uid, or null.
	 *
	 * @param string $uid The Nextcloud user id.
	 *
	 * @return array<string, mixed>|null The Employee payload, or null.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
	 */
	public function findEmployeeByUserId(string $uid): ?array;

	/**
	 * Resolve a schema id to its slug, or '' when unresolvable.
	 *
	 * @param string $schemaId The schema id/uuid carried by an object.
	 *
	 * @return string The slug, or ''.
	 *
	 * @spec exclude cross-cutting OpenRegister plumbing (schema-slug resolution) shared by every hours-process listener; no single requirement owns it
	 */
	public function resolveSchemaSlug(string $schemaId): string;
}//end interface
