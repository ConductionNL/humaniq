<?php

/**
 * Humaniq OnboardingDocumentController
 *
 * Read document and Create contract from document on an onboarding case
 * (hiring-document-to-employee REQ-HTE-003). The case is resolved as the
 * caller first (404); the service then requires HR or an administrator
 * (403). A done read or a made contract answers 200; every other outcome
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
 * @spec openspec/changes/hiring-document-to-employee/specs/hire-to-employee/spec.md#REQ-HTE-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\EmployeeDocumentExtractionService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Documents read for a new hire.
 *
 * @spec openspec/changes/hiring-document-to-employee/specs/hire-to-employee/spec.md#REQ-HTE-003
 */
class OnboardingDocumentController extends Controller {

	/**
	 * The HTTP status of each outcome; anything else is 422.
	 *
	 * @var array<string, int>
	 */
	private const STATUS = ['read' => Http::STATUS_OK, 'created' => Http::STATUS_OK, 'forbidden' => Http::STATUS_FORBIDDEN];

	/**
	 * Constructor.
	 *
	 * @param IRequest                          $request     The request.
	 * @param RbacObjectReader                  $rbac        Reads the case as the caller.
	 * @param EmployeeDocumentExtractionService $documents   Reads the documents.
	 * @param IUserSession                      $userSession The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly RbacObjectReader $rbac,
		private readonly EmployeeDocumentExtractionService $documents,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Read every document of the case that is not read yet.
	 *
	 * @param string $id The onboarding case id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/hiring-document-to-employee/specs/hire-to-employee/spec.md#REQ-HTE-003
	 */
	#[NoAdminRequired]
	public function readDocument(string $id): JSONResponse {
		$onboarding = $this->rbac->findOrNull(id: $id, schema: 'Onboarding');
		if ($onboarding === null) {
			return new JSONResponse(['message' => 'Onboarding case not found.'], Http::STATUS_NOT_FOUND);
		}

		return $this->answer(id: $id, answer: $this->documents->read(onboarding: ($onboarding + ['id' => $id]), userId: $this->caller()));
	}//end readDocument()

	/**
	 * Make the employment contract from the case's read contract document.
	 *
	 * @param string $id The onboarding case id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/hiring-document-to-employee/specs/hire-to-employee/spec.md#REQ-HTE-003
	 */
	#[NoAdminRequired]
	public function contractFromDocument(string $id): JSONResponse {
		$onboarding = $this->rbac->findOrNull(id: $id, schema: 'Onboarding');
		if ($onboarding === null) {
			return new JSONResponse(['message' => 'Onboarding case not found.'], Http::STATUS_NOT_FOUND);
		}

		return $this->answer(id: $id, answer: $this->documents->contractFrom(onboarding: ($onboarding + ['id' => $id]), userId: $this->caller()));
	}//end contractFromDocument()

	/**
	 * The response for a service answer.
	 *
	 * @param string               $id     The case.
	 * @param array<string, mixed> $answer The answer, with `status`.
	 *
	 * @return JSONResponse
	 */
	private function answer(string $id, array $answer): JSONResponse {
		return new JSONResponse(['onboardingId' => $id] + $answer, (self::STATUS[$answer['status']] ?? Http::STATUS_UNPROCESSABLE_ENTITY));
	}//end answer()

	/**
	 * The caller's user id.
	 *
	 * @return string
	 */
	private function caller(): string {
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}//end caller()
}//end class
