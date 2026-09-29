<?php

/**
 * Annual statement batch controller
 *
 * `POST /api/documents/jaaropgaven` queues the annual statements of a
 * finished year for every employee with payslips in it, as a background job
 * (payroll-annual-statement-action D2). HR, payroll or an administrator only.
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
 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\BackgroundJob\JaaropgaafYearJob;
use OCA\Humaniq\Service\HrDocumentService;
use OCA\Humaniq\Service\HumaniqRoles;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Queues a finished year's annual statements.
 *
 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
 */
class AnnualStatementBatchController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request           The request.
	 * @param HrDocumentService $hrDocumentService Lists the year's employees.
	 * @param IUserSession      $userSession       The caller.
	 * @param HumaniqRoles      $roles             Who may queue a year.
	 * @param IJobList          $jobList           Queues the batch.
	 * @param ITimeFactory      $timeFactory       The clock that decides whether a year is over.
	 */
	public function __construct(
		IRequest $request,
		private readonly HrDocumentService $hrDocumentService,
		private readonly IUserSession $userSession,
		private readonly HumaniqRoles $roles,
		private readonly IJobList $jobList,
		private readonly ITimeFactory $timeFactory,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

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
}//end class
