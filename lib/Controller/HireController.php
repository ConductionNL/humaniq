<?php

/**
 * Humaniq HireController
 *
 * Create employee on a hired application: read the proposal and the earlier
 * records the person may have, then hire. Each method resolves the
 * application as the caller first (404), then requires HR or an
 * administrator (403) (hiring-hire-to-employee D5, the OfferController shape).
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
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\HireMatchService;
use OCA\Humaniq\Service\HireService;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The hire proposal and the hire. *
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
 */
class HireController extends Controller {

	private const STATUS_BY_OUTCOME = [
		'created' => Http::STATUS_CREATED,
		'attached' => Http::STATUS_OK,
		'already-linked' => Http::STATUS_OK,
		'matches' => Http::STATUS_CONFLICT,
		'not-hired' => Http::STATUS_CONFLICT,
		'not-found' => Http::STATUS_NOT_FOUND,
		'invalid' => Http::STATUS_BAD_REQUEST,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest         $request     The request.
	 * @param RbacObjectReader $rbac        Reads as the caller.
	 * @param HumaniqRoles     $roles       HR membership.
	 * @param HireService      $hires       Creates or attaches the employee.
	 * @param HireMatchService $matcher     The duplicate check.
	 * @param IUserSession     $userSession The caller.
	 *
	 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
	 */
	public function __construct(
		IRequest $request,
		private readonly RbacObjectReader $rbac,
		private readonly HumaniqRoles $roles,
		private readonly HireService $hires,
		private readonly HireMatchService $matcher,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The proposed name, the linked employee if any, and the earlier records
	 * matching the application's e-mail and whatever HR has typed so far.
	 *
	 * @param string $id The application id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
	 */
	#[NoAdminRequired]
	public function matches(string $id): JSONResponse {
		$application = $this->rbac->findOrNull(id: $id, schema: 'job-application');
		if ($application === null) {
			return new JSONResponse(['message' => 'Application not found.'], Http::STATUS_NOT_FOUND);
		}

		if ($this->isHr() === false) {
			return new JSONResponse(['message' => 'Only HR or an administrator can create an employee from an application.'], Http::STATUS_FORBIDDEN);
		}

		$proposal = $this->hires->proposedName((string)($application['candidateName'] ?? ''));
		$lastName = trim((string)$this->request->getParam('lastName', ''));
		$matches = $this->matcher->matches(
			[
				'privateEmail' => (string)($application['email'] ?? ''),
				'lastName' => ($lastName !== '' ? $lastName : $proposal['lastName']),
				'bsn' => trim((string)$this->request->getParam('bsn', '')),
				'dateOfBirth' => trim((string)$this->request->getParam('dateOfBirth', '')),
			]
		);
		$linked = trim((string)($application['employeeId'] ?? ''));

		return new JSONResponse(
			[
				'applicationId' => $id,
				'status' => (string)($application['status'] ?? ''),
				'employeeId' => ($linked !== '' ? $linked : null),
				'proposal' => $proposal,
				'matches' => $matches,
			]
		);
	}//end matches()

	/**
	 * Hire: create the employee or attach to the one HR chose. 409 with the
	 * matches while HR has not chosen.
	 *
	 * @param string $id The application id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
	 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
	 */
	#[NoAdminRequired]
	public function hire(string $id): JSONResponse {
		if ($this->rbac->findOrNull(id: $id, schema: 'job-application') === null) {
			return new JSONResponse(['message' => 'Application not found.'], Http::STATUS_NOT_FOUND);
		}

		if ($this->isHr() === false) {
			return new JSONResponse(['message' => 'Only HR or an administrator can create an employee from an application.'], Http::STATUS_FORBIDDEN);
		}

		$params = $this->request->getParams();
		$result = $this->hires->hire(
			$id,
			[
				'startDate' => ($params['startDate'] ?? null),
				'firstName' => ($params['firstName'] ?? null),
				'lastName' => ($params['lastName'] ?? null),
				'bsn' => ($params['bsn'] ?? null),
				'dateOfBirth' => ($params['dateOfBirth'] ?? null),
				'attachToEmployeeId' => ($params['attachToEmployeeId'] ?? null),
				'createNew' => (($params['createNew'] ?? false) === true || ($params['createNew'] ?? null) === 'true'),
			]
		);

		return new JSONResponse($result, (self::STATUS_BY_OUTCOME[$result['outcome'] ?? ''] ?? Http::STATUS_INTERNAL_SERVER_ERROR));
	}//end hire()

	/**
	 * Whether the caller is HR or an administrator.
	 *
	 * @return boolean
	 */
	private function isHr(): bool {
		return $this->roles->isHr((string)($this->userSession->getUser()?->getUID() ?? ''));
	}//end isHr()

}//end class
