<?php

/**
 * Compliance check controller
 *
 * `GET /api/compliance/check`: runs the rule audit for the `Compliance check`
 * page and answers with the corpus coverage and the violations about records
 * the caller may read (REQ-RULE-010).
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
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\ComplianceCheckService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The compliance check for the signed-in caller.
 *
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/hrm-rule-engine/spec.md#requirement-hr-shall-see-the-audits-violations-and-coverage-on-a-page-req-rule-010
 */
class ComplianceCheckController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request     The request.
	 * @param ComplianceCheckService $check       The audit, scoped to the caller.
	 * @param IUserSession           $userSession The caller.
	 * @param LoggerInterface        $logger      Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ComplianceCheckService $check,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * `GET /api/compliance/check`: coverage and the caller's visible violations.
	 *
	 * @return JSONResponse 200 {coverage, counts, violations}, 401 when signed out, 500 when the audit cannot read the register.
	 *
	 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/hrm-rule-engine/spec.md#requirement-hr-shall-see-the-audits-violations-and-coverage-on-a-page-req-rule-010
	 */
	#[NoAdminRequired]
	public function check(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Sign in to run the compliance check.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->check->run());
		} catch (\Throwable $e) {
			$this->logger->error('ComplianceCheckController: de nalevingscontrole is mislukt (' . $e::class . ').');
			return new JSONResponse(['error' => 'The compliance check could not read the register.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end check()
}//end class
