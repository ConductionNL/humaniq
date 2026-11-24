<?php

/**
 * Humaniq ApplicationCvController
 *
 * Read CV on an application (hiring-cv-profile-extraction REQ-CAS-004): the
 * application is resolved as the caller first (404); the reader then
 * requires HR or an administrator (403) and fills an empty profile from the
 * CV through filinq. Only a filled profile answers 200; every other outcome
 * answers 422 with the reason the page shows.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Controller
 * @package   OCA\Humaniq\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\CvProfileReader;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read CV.
 *
 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-004
 */
class ApplicationCvController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest         $request     The request.
	 * @param RbacObjectReader $rbac        Reads the application as the caller.
	 * @param CvProfileReader  $cvReader    Reads the CV into the profile.
	 * @param IUserSession     $userSession The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly RbacObjectReader $rbac,
		private readonly CvProfileReader $cvReader,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Fill an empty profile from the application's CV.
	 *
	 * @param string $id The application id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-004
	 */
	#[NoAdminRequired]
	public function readCv(string $id): JSONResponse {
		$application = $this->rbac->findOrNull(id: $id, schema: 'job-application');
		if ($application === null) {
			return new JSONResponse(['message' => 'Application not found.'], Http::STATUS_NOT_FOUND);
		}

		$answer = $this->cvReader->read(application: ($application + ['id' => $id]), userId: (string)($this->userSession->getUser()?->getUID() ?? ''));
		$code = match ($answer['status']) {
			'filled' => Http::STATUS_OK,
			'forbidden' => Http::STATUS_FORBIDDEN,
			default => Http::STATUS_UNPROCESSABLE_ENTITY,
		};

		return new JSONResponse(['applicationId' => $id] + $answer, $code);
	}//end readCv()
}//end class
