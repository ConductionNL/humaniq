<?php

/**
 * Contract of HrDocumentService
 *
 * The public surface of HrDocumentService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
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
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Kernel\Contracts;

/**
 * Contract of HrDocumentService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface HrDocumentServiceInterface {
	/**
	 * The occ backlog trigger (design.md D7, widened by payslip-pdf-docudesk
	 * design.md D5): with no options, every permanent written
	 * EmploymentContract is processed for `arbeidsovereenkomst`
	 * (already-documented contracts simply no-op via the idempotency
	 * pre-check). A non-default letter documentType has no backlog semantics
	 * and requires `$employeeId`. `loonstrook` has real backlog semantics --
	 * every Payslip lacking an active loonstrook document, optionally
	 * narrowed by `$period` and/or `$employeeId`. `jaaropgaaf` REQUIRES
	 * `$year` and processes every employee with at least one payslip in that
	 * year (or just `$employeeId`), aggregating then rendering per employee.
	 * Option misuse -- `$period` with any type but loonstrook, `$year` with
	 * any type but jaaropgaaf, `jaaropgaaf` without `$year`, or a non-backlog
	 * letter type without `$employeeId` -- returns a single `usage-error`
	 * outcome and generates nothing.
	 *
	 * @param string|null $documentType Restrict/switch the documentType, or null for the default backlog.
	 * @param string|null $employeeId Restrict to one Employee id.
	 * @param string|null $period Restrict the loonstrook backlog to one YYYY-MM period.
	 * @param string|null $year The year to aggregate/backlog for the jaaropgaaf documentType.
	 *
	 * @return array<int, array<string, mixed>> One outcome array per attempt.
	 *
	 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-007
	 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003
	 */
	public function generateBacklog(
		?string $documentType = null,
		?string $employeeId = null,
		?string $period = null,
		?string $year = null,
	): array;

	/**
	 * Generate one document: idempotency pre-check, duck-typed availability
	 * probe, template selection (config-first/discovery-second/fail-closed),
	 * the docudesk render call, and storing the returned binary via
	 * OpenRegister's FileService -- recording the outcome on a
	 * `GeneratedDocument` at every step (design.md D2-D6).
	 *
	 * @param string $employeeId The Employee this document is for.
	 * @param string|null $contractId The EmploymentContract it evidences, or null for employee-level types.
	 * @param string $documentType One of arbeidsovereenkomst/aanbiedingsbrief/werkgeversverklaring/getuigschrift.
	 * @param string|null $userId The acting Nextcloud user id, or null for 'system' (occ context).
	 *
	 * @return array<string, mixed> Outcome: {employeeId, contractId, documentType, status, message, generatedDocumentId}.
	 *
	 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-002
	 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-003
	 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-004
	 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-005
	 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-006
	 */
	public function generate(string $employeeId, ?string $contractId, string $documentType, ?string $userId = null): array;

	/**
	 * Generate a `documentType: loonstrook` document for one Payslip
	 * (payslip-pdf-docudesk design.md D3/D6): resolves the Payslip, takes its
	 * `employeeId`, and renders with `dataRefs = [Employee, Payslip]`
	 * (`contractId` stays null); idempotency keys on (payslipId,
	 * documentType).
	 *
	 * @param string $payslipId The Payslip id.
	 * @param string|null $userId The acting Nextcloud user id, or null for 'system'.
	 *
	 * @return array<string, mixed> Outcome: {employeeId, contractId, documentType, status, message, generatedDocumentId}.
	 *
	 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
	 */
	public function generateLoonstrook(string $payslipId, ?string $userId = null): array;

	/**
	 * The employees with at least one payslip in the year: the ones a year's
	 * annual statements cover (payroll-annual-statement-action D2).
	 *
	 * @param int $year The year.
	 *
	 * @return array<int, string> Employee ids, each once.
	 *
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
	 */
	public function jaaropgaafEmployeeIds(int $year): array;

	/**
	 * Generate a `documentType: jaaropgaaf` document for one employee-year
	 * (payslip-pdf-docudesk design.md D3/D4/D6): first upserts the employee's
	 * `Jaaropgaaf` aggregate for `$year` (idempotent per employee+year), then
	 * renders with `dataRefs = [Employee, Jaaropgaaf]`; idempotency keys on
	 * (jaaropgaafId, documentType). Zero payslips in the year yields a
	 * `failed` outcome with no aggregate write.
	 *
	 * @param string $employeeId The Employee id.
	 * @param int $year The kalenderjaar to aggregate/render.
	 * @param string|null $userId The acting Nextcloud user id, or null for 'system'.
	 *
	 * @return array<string, mixed> Outcome: {employeeId, contractId, documentType, status, message, generatedDocumentId}.
	 *
	 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003
	 */
	public function generateJaaropgaaf(string $employeeId, int $year, ?string $userId = null): array;

	/**
	 * Generate the 42-week notification to UWV for one sickness case
	 * (absence-deadlines-and-signals design.md D3): the case, the employee,
	 * the contract covering the first sick day and the administration are
	 * reduced to the notification's fields by UwvNotificationData and handed
	 * to the renderer as ad-hoc data; the document is filed as an
	 * HrGeneratedDocument linked to the case. A recovered case, or one whose
	 * notification is already done, is refused and nothing is created.
	 * Nothing is transmitted.
	 *
	 * @param string $caseId The SickLeaveCase id; the caller has resolved it under its own RBAC.
	 * @param string|null $userId The acting Nextcloud user id.
	 *
	 * @return array<string, mixed> The outcome.
	 *
	 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-002
	 */
	public function generateUwvNotification(string $caseId, ?string $userId = null): array;
}//end interface
