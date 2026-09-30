<?php

/**
 * Announcements for the caller, their confirmation, and HR's overview
 * (self-service-announcements-and-digest D2).
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
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AnnouncementService;
use OCA\Humaniq\Service\HumaniqRoles;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The announcement endpoints.
 *
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-001
 */
class AnnouncementController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request       The request.
	 * @param AnnouncementService $announcements Audience and confirmations.
	 * @param HumaniqRoles        $roles         Who is HR.
	 * @param IUserSession        $userSession   The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly AnnouncementService $announcements,
		private readonly HumaniqRoles $roles,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The announcements the caller is in the audience of today.
	 *
	 * @return JSONResponse `{announcements: [...]}`; 401 when logged out.
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-001
	 */
	#[NoAdminRequired]
	public function mine(): JSONResponse {
		$uid = $this->uid();
		if ($uid === '') {
			return new JSONResponse(['message' => 'Not logged in.'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['announcements' => $this->announcements->mine(uid: $uid, today: gmdate('Y-m-d'))]);
	}//end mine()

	/**
	 * Confirm, as the caller, that they read an announcement. The audience
	 * check is the caller's own: an announcement they do not see is 404.
	 *
	 * @param string $announcementId The announcement.
	 *
	 * @return JSONResponse 201 with the confirmation; 404 or 409 with the reason.
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-002
	 */
	#[NoAdminRequired]
	public function confirm(string $announcementId): JSONResponse {
		$uid = $this->uid();
		if ($uid === '') {
			return new JSONResponse(['message' => 'Not logged in.'], Http::STATUS_UNAUTHORIZED);
		}

		$result = $this->announcements->confirm(announcementId: $announcementId, uid: $uid, now: gmdate('c'));
		if ($result['status'] !== Http::STATUS_CREATED) {
			return new JSONResponse(['message' => $result['message']], $result['status']);
		}

		return new JSONResponse($result['confirmation'], Http::STATUS_CREATED);
	}//end confirm()

	/**
	 * Who in the audience confirmed an announcement and who did not.
	 *
	 * @param string $id The announcement.
	 *
	 * @return JSONResponse The overview; 403 for anyone but HR or an administrator, 404 when unknown.
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-002
	 */
	#[NoAdminRequired]
	public function confirmations(string $id): JSONResponse {
		if ($this->roles->isHr($this->uid()) === false) {
			return new JSONResponse(['message' => 'Only HR or an administrator can see who confirmed an announcement.'], Http::STATUS_FORBIDDEN);
		}

		$overview = $this->announcements->overview(announcementId: $id, today: gmdate('Y-m-d'));
		if ($overview === null) {
			return new JSONResponse(['message' => 'Announcement not found.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($overview);
	}//end confirmations()

	/**
	 * The caller's uid, or ''.
	 *
	 * @return string
	 */
	private function uid(): string {
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}//end uid()

}//end class
