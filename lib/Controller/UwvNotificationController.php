<?php

/**
 * UWV Notification Controller
 *
 * `POST /api/sick-leave/{id}/uwv-notification`: generate the 42-week
 * notification to UWV for one sickness case (absence-deadlines-and-signals
 * design.md D3). The case resolves under the caller's own RBAC first (404
 * otherwise, so existence is never leaked), then only a Nextcloud
 * administrator or the `hr` role in the caller's active administration may
 * generate (403 otherwise), the `DocumentController::generate()` guard shape.
 * Nothing is transmitted to UWV.
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
 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\HrDocumentService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Generates the 42-week notification for a sickness case.
 *
 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-002
 */
class UwvNotificationController extends Controller {

	/**
	 * @param IRequest $request The request.
	 * @param HrDocumentService $documents Renders and files the notification.
	 * @param RbacObjectReader $rbac Reads the case under the caller's own RBAC.
	 * @param AdministrationService $administrationService The caller's role in the active administration.
	 * @param IGroupManager $groupManager The admin check.
	 * @param IUserSession $userSession The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly HrDocumentService $documents,
		private readonly RbacObjectReader $rbac,
		private readonly AdministrationService $administrationService,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Generate the 42-week notification for the case.
	 *
	 * @param string $id The SickLeaveCase id.
	 *
	 * @return JSONResponse The outcome; 404 unreadable, 403 not HR, 400 refused.
	 *
	 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-002
	 */
	#[NoAdminRequired]
	public function generate(string $id): JSONResponse {
		$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') {
			return new JSONResponse(['error' => 'Niet ingelogd.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->rbac->findOrNull(id: $id, schema: 'SickLeaveCase') === null) {
			return new JSONResponse(['error' => 'Ziektegeval niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		if ($this->mayGenerate($uid) === false) {
			return new JSONResponse(['error' => 'Alleen HR of een beheerder kan de 42-wekenmelding maken.'], Http::STATUS_FORBIDDEN);
		}

		$result = $this->documents->generateUwvNotification($id, $uid);
		$status = (string)($result['status'] ?? '');
		if (str_starts_with($status, 'refused-') === true) {
			return new JSONResponse($result, Http::STATUS_BAD_REQUEST);
		}

		if ($status === 'failed') {
			return new JSONResponse($result, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result);
	}//end generate()

	/**
	 * Whether the caller is a Nextcloud administrator or HR in their active
	 * administration. HR is a role per administration, not admin, so this is
	 * not an admin-only endpoint.
	 *
	 * @param string $uid The caller.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-002
	 */
	private function mayGenerate(string $uid): bool {
		return $this->groupManager->isAdmin($uid) === true || $this->administrationService->getActiveAdministrationRole($uid) === 'hr';
	}//end mayGenerate()

}//end class
