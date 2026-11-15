<?php

/**
 * Open a survey, answer it, and read its results
 * (talent-engagement-surveys D1-D3).
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
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\SurveyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Survey endpoints. Opening and the results are for HR or an
 * administrator; answering is for the invited employee only, which the
 * service checks against the caller's own invitation.
 *
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
 */
class SurveyController extends Controller {

	/**
	 * The controller.
	 *
	 * @param IRequest      $request     The request.
	 * @param SurveyService $surveys     The survey service.
	 * @param HumaniqRoles  $roles       Whether the caller is HR.
	 * @param IUserSession  $userSession The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly SurveyService $surveys,
		private readonly HumaniqRoles $roles,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Open a draft survey and invite the employees in its scope.
	 *
	 * @param string $id The survey.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
	 */
	#[NoAdminRequired]
	public function open(string $id): JSONResponse {
		if ($this->roles->isHr($this->uid()) === false) {
			return new JSONResponse(['message' => 'Only HR or an administrator can open a survey.'], Http::STATUS_FORBIDDEN);
		}

		$outcome = $this->surveys->open(surveyId: $id, today: gmdate('Y-m-d'));

		return new JSONResponse($outcome, $outcome['status']);
	}//end open()

	/**
	 * Answer a survey as the caller.
	 *
	 * @param string               $id      The survey.
	 * @param array<string, mixed> $answers The answers by question key.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
	 */
	#[NoAdminRequired]
	public function respond(string $id, array $answers=[]): JSONResponse {
		$uid = $this->uid();
		if ($uid === '') {
			return new JSONResponse(['message' => 'Not logged in.'], Http::STATUS_UNAUTHORIZED);
		}

		$outcome = $this->surveys->respond(surveyId: $id, uid: $uid, answers: $answers, today: gmdate('Y-m-d'));

		return new JSONResponse($outcome, $outcome['status']);
	}//end respond()

	/**
	 * The results of a survey, folded below the minimum group size.
	 *
	 * @param string $id The survey.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-003
	 */
	#[NoAdminRequired]
	public function results(string $id): JSONResponse {
		if ($this->roles->isHr($this->uid()) === false) {
			return new JSONResponse(['message' => 'Only HR or an administrator can read the results of a survey.'], Http::STATUS_FORBIDDEN);
		}

		$results = $this->surveys->results(surveyId: $id);
		if ($results === null) {
			return new JSONResponse(['message' => 'Survey not found.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($results);
	}//end results()

	/**
	 * The caller's account, or '' when logged out.
	 *
	 * @return string
	 */
	private function uid(): string {
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}//end uid()

}//end class
