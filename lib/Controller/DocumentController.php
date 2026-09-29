<?php

/**
 * Document Controller
 *
 * Backs the `EmploymentContractDetail` manifest page action "Genereer
 * arbeidsovereenkomst" AND, since payslip-pdf-docudesk, the `PayslipDetail`
 * page action "Genereer PDF" (humaniq-docudesk-documents design.md D7,
 * payslip-pdf-docudesk design.md D6): a single POST endpoint that resolves
 * the posted subject (contract OR payslip) through OpenRegister's
 * ObjectService under the caller's RBAC BEFORE any docudesk call (no-admin-idor
 * guard -- an unknown or unauthorized contractId/payslipId never reaches
 * docudesk and never creates a GeneratedDocument), then delegates the actual
 * render/store to `HrDocumentService::generate()` /
 * `HrDocumentService::generateLoonstrook()`. Since
 * payroll-annual-statement-action, `jaaropgaaf` resolves the posted
 * `jaaropgaafId` the same way and `POST /api/documents/jaaropgaven` queues a
 * year's statements for HR or payroll as a background job.
 *
 * @category Controller
 * @package  OCA\Humaniq\Controller
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
 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-008
 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-001
 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\BackgroundJob\JaaropgaafYearJob;
use OCA\Humaniq\Service\HrDocumentService;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Guarded endpoint that triggers a single HR-document generation attempt.
 */
class DocumentController extends Controller {

	/**
	 * @param IRequest $request The request object.
	 * @param ContainerInterface $container DI container for the RBAC-guarded ObjectService resolve.
	 * @param HrDocumentService $hrDocumentService The document-generation service.
	 * @param SettingsService $settingsService The register-slug source.
	 * @param IUserSession $userSession The current user session (acting userId).
	 * @param HumaniqRoles $roles Who may queue a year's statements.
	 * @param IJobList $jobList Queues the year batch.
	 * @param ITimeFactory $timeFactory The clock that decides whether a year is over.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly HrDocumentService $hrDocumentService,
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly HumaniqRoles $roles,
		private readonly IJobList $jobList,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `POST /api/documents/generate` -- dispatches on `documentType`. The
	 * four letter types resolve the posted `contractId` under the caller's
	 * RBAC (unknown/unauthorized -> 404, no docudesk call) then trigger a
	 * single generation attempt for it (humaniq-docudesk-documents design.md
	 * D8). `loonstrook` resolves the posted `payslipId` the identical way via
	 * `authorizePayslip()` (payslip-pdf-docudesk design.md D6) -- the
	 * employeeId is taken from the resolved payslip, `contractId` stays
	 * null. A missing subject param for the requested type -> 400.
	 * `jaaropgaaf` resolves the posted `jaaropgaafId` the identical way via
	 * `authorizeJaaropgaaf()` and renders that statement's employee and year
	 * (payroll-annual-statement-action D1).
	 *
	 * @param string|null $contractId The EmploymentContract id (row-scoped, `@objectId` from the manifest action) -- required for the letter types.
	 * @param string $documentType The document type (defaults to arbeidsovereenkomst).
	 * @param string|null $payslipId The Payslip id (row-scoped, `@objectId` from the PayslipDetail action) -- required for `loonstrook`.
	 * @param string|null $jaaropgaafId The Jaaropgaaf id (`@objectId` from the JaaropgaafDetail action) -- required for `jaaropgaaf`.
	 *
	 * @return JSONResponse The generation outcome, 400 on a missing subject param, or 404 when the subject does not resolve.
	 *
	 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-008
	 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-001
	 */
	#[NoAdminRequired]
	public function generate(
		?string $contractId = null,
		string $documentType = 'arbeidsovereenkomst',
		?string $payslipId = null,
		?string $jaaropgaafId = null,
	): JSONResponse {
		$documentType = trim($documentType);
		$userId = $this->userSession->getUser()?->getUID();

		if ($documentType === 'jaaropgaaf') {
			return $this->generateStatement(jaaropgaafId: trim((string)$jaaropgaafId), userId: $userId);
		}

		if ($documentType === 'loonstrook') {
			$payslipId = trim((string)$payslipId);
			if ($payslipId === '') {
				return new JSONResponse(['error' => 'payslipId is verplicht.'], Http::STATUS_BAD_REQUEST);
			}

			// No-admin-idor guard (ADR-005 Rule 3): the payslip must resolve
			// through OpenRegister's ObjectService under the caller's RBAC
			// before any docudesk call -- an unresolvable/unauthorized id
			// never reaches it.
			$payslipArray = $this->authorizePayslip($payslipId);
			if ($payslipArray === null) {
				return new JSONResponse(['error' => 'Loonstrook niet gevonden.'], Http::STATUS_NOT_FOUND);
			}

			$result = $this->hrDocumentService->generateLoonstrook($payslipId, $userId);
			return new JSONResponse($result);
		}

		$contractId = trim((string)$contractId);
		if ($contractId === '') {
			return new JSONResponse(['error' => 'contractId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		// No-admin-idor guard (ADR-005 Rule 3): the contract must resolve
		// through OpenRegister's ObjectService under the caller's RBAC before
		// any docudesk call -- an unresolvable/unauthorized id never reaches it.
		$contractArray = $this->authorizeContract($contractId);
		if ($contractArray === null) {
			return new JSONResponse(['error' => 'Contract niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$employeeId = trim((string)($contractArray['employeeId'] ?? ''));
		if ($employeeId === '') {
			return new JSONResponse(['error' => 'Contract heeft geen gekoppelde medewerker.'], Http::STATUS_NOT_FOUND);
		}

		$result = $this->hrDocumentService->generate($employeeId, $contractId, $documentType, $userId);

		return new JSONResponse($result);
	}//end generate()

	/**
	 * `POST /api/documents/jaaropgaven` -- queue the annual statements of a
	 * finished year for every employee with payslips in it
	 * (payroll-annual-statement-action D2). HR, payroll or an administrator
	 * only; the year defaults to the previous calendar year and must be over.
	 * Answers 202 with the number of employees the job will cover.
	 *
	 * @param int|null $year The year, or null for the previous calendar year.
	 *
	 * @return JSONResponse 202 {year, queued}, 400 for a year that is not over, 403 outside HR and payroll.
	 *
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
	 */
	#[NoAdminRequired]
	public function queueJaaropgaven(?int $year = null): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($this->roles->isHr($userId) === false && $this->roles->isPayroll($userId) === false) {
			return new JSONResponse(['error' => 'Only HR or payroll can generate a year of annual statements.'], Http::STATUS_FORBIDDEN);
		}

		$currentYear = (int)$this->timeFactory->now()->format('Y');
		$year = $year ?? ($currentYear - 1);
		if ($year >= $currentYear || $year < 1900) {
			return new JSONResponse(['error' => sprintf('The year %d is not over yet.', $year)], Http::STATUS_BAD_REQUEST);
		}

		$queued = count($this->hrDocumentService->jaaropgaafEmployeeIds($year));
		$this->jobList->add(JaaropgaafYearJob::class, ['year' => $year, 'userId' => $userId]);

		return new JSONResponse(['year' => $year, 'queued' => $queued], Http::STATUS_ACCEPTED);
	}//end queueJaaropgaven()

	/**
	 * Resolve one Jaaropgaaf under the caller's RBAC, then render it for its
	 * own employee and year. A blank id is 400; an unreadable one is 404 and
	 * nothing is rendered.
	 *
	 * @param string      $jaaropgaafId The Jaaropgaaf id.
	 * @param string|null $userId       The acting user.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-001
	 */
	private function generateStatement(string $jaaropgaafId, ?string $userId): JSONResponse {
		if ($jaaropgaafId === '') {
			return new JSONResponse(['error' => 'jaaropgaafId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		$statement = $this->authorizeSubject(id: $jaaropgaafId, schema: 'Jaaropgaaf');
		$employeeId = trim((string)($statement['employeeId'] ?? ''));
		$year = (int)($statement['year'] ?? 0);
		if ($statement === null || $employeeId === '' || $year === 0) {
			return new JSONResponse(['error' => 'Jaaropgaaf niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->hrDocumentService->generateJaaropgaaf($employeeId, $year, $userId));
	}//end generateStatement()

	/**
	 * Resolve an object of the given schema under the caller's ambient RBAC;
	 * null when it does not exist or the caller may not read it.
	 *
	 * @param string $id     The object id.
	 * @param string $schema The schema.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-001
	 */
	private function authorizeSubject(string $id, string $schema): ?array {
		try {
			$row = $this->objectService()->find(
				id: $id,
				register: $this->settingsService->getRegisterSlug(),
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->info('DocumentController: ' . $schema . ' ' . $id . ' kon niet worden opgehaald: ' . $e->getMessage());
			return null;
		}

		if ($row === null) {
			return null;
		}

		return $this->toArray($row);
	}//end authorizeSubject()

	/**
	 * Resolve the posted contractId through OpenRegister's ObjectService
	 * under the caller's ambient RBAC (default $_rbac=true) -- the
	 * no-admin-idor guard for this endpoint. Returns null when the contract
	 * does not exist OR the caller's RBAC denies it (both collapse to the
	 * same 404 so existence is never leaked to an unauthorized caller).
	 *
	 * @param string $contractId The EmploymentContract id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-008
	 */
	private function authorizeContract(string $contractId): ?array {
		try {
			$contract = $this->objectService()->find(
				id: $contractId,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'EmploymentContract'
			);
		} catch (\Throwable $e) {
			$this->logger->info('DocumentController: contract ' . $contractId . ' kon niet worden opgehaald: ' . $e->getMessage());
			return null;
		}

		if ($contract === null) {
			return null;
		}

		return $this->toArray($contract);
	}//end authorizeContract()

	/**
	 * Resolve the posted payslipId through OpenRegister's ObjectService
	 * under the caller's ambient RBAC (default $_rbac=true) -- the
	 * no-admin-idor guard mirroring `authorizeContract()` for the loonstrook
	 * variant (payslip-pdf-docudesk design.md D6). Returns null when the
	 * payslip does not exist OR the caller's RBAC denies it (both collapse
	 * to the same 404 so existence is never leaked to an unauthorized caller).
	 *
	 * @param string $payslipId The Payslip id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
	 */
	private function authorizePayslip(string $payslipId): ?array {
		try {
			$payslip = $this->objectService()->find(
				id: $payslipId,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'Payslip'
			);
		} catch (\Throwable $e) {
			$this->logger->info('DocumentController: payslip ' . $payslipId . ' kon niet worden opgehaald: ' . $e->getMessage());
			return null;
		}

		if ($payslip === null) {
			return null;
		}

		return $this->toArray($payslip);
	}//end authorizePayslip()

	/**
	 * @return mixed The OpenRegister ObjectService, resolved with the caller's ambient RBAC (default $_rbac=true).
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. Unguarded, an
		// instance without OpenRegister gets a container exception naming a
		// class the admin has never heard of; guarded, it is told which app to
		// install — which is rule 3's promise that the app still explains
		// itself.
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * Normalise an ObjectService row (entity or array) to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()

}//end class
