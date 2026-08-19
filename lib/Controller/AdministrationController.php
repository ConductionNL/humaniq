<?php

/**
 * Administration Controller
 *
 * Backs the multi-administratie switcher (design.md D4/D5): the ADR-001
 * Rule 3 active-administratie selection surface. `setActive()` resolves the
 * posted `administrationId` against the caller's own `AdministrationAccess`
 * rows BEFORE persisting it (the DocumentController/PayrollController
 * no-admin-idor resolve-first pattern -- unknown or not-accessible collapse
 * to the same 404, never leaking which administraties exist), so a caller
 * can never activate an administratie they have no membership in.
 * `context()` returns the active id plus the caller's accessible
 * administraties for the switcher and the Dashboard/Configuratie indicator.
 *
 * @category Controller
 * @package  OCA\Hrmq\Controller
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
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Controller;

use OCA\Hrmq\AppInfo\Application;
use OCA\Hrmq\Service\AdministrationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Guarded per-user active-administration selection + context endpoints.
 */
class AdministrationController extends Controller {

	/**
	 * @param IRequest $request The request object.
	 * @param AdministrationService $administrationService The access-resolution + active-pointer service.
	 * @param IUserSession $userSession The current user session (acting userId).
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationService $administrationService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `POST /api/administration/active` -- activates the posted
	 * `administrationId` for the caller. No-admin-idor guard (ADR-005 Rule
	 * 3): the id MUST resolve to one of the caller's own
	 * `AdministrationAccess` rows BEFORE it is persisted -- unknown and
	 * not-accessible both collapse to 404, so existence is never leaked to a
	 * caller without a membership row.
	 *
	 * @param string|null $administrationId The administratie id to activate.
	 *
	 * @return JSONResponse `{activeAdministrationId}` on success, 400 on a missing id, 404 when the caller has no AdministrationAccess row for it.
	 *
	 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
	 */
	#[NoAdminRequired]
	public function setActive(?string $administrationId = null): JSONResponse {
		$administrationId = trim((string)$administrationId);
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null || $userId === '') {
			return new JSONResponse(['error' => 'Niet ingelogd.'], Http::STATUS_NOT_FOUND);
		}

		// No-admin-idor guard: resolve-first, BEFORE any write. A caller
		// without an AdministrationAccess row for this id never reaches the
		// persist step.
		if ($this->administrationService->hasAccess($userId, $administrationId) === false) {
			return new JSONResponse(['error' => 'Administratie niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$this->administrationService->setActiveAdministrationId($userId, $administrationId);

		return new JSONResponse(['activeAdministrationId' => $administrationId]);
	}//end setActive()

	/**
	 * `GET /api/administration/context` -- the active administratie id (or
	 * null) plus the caller's accessible administraties, for the switcher
	 * and the Dashboard/Configuratie indicator. single-person-modes
	 * (REQ-SPM-002): every administratie entry additionally carries its
	 * resolved `mode` (via `AdministrationService::accessibleAdministrations()`),
	 * so the switcher can display it and a client-side switch can update
	 * `runtime.user.administrationMode` without a reload.
	 *
	 * @return JSONResponse `{activeAdministrationId, administrations}` (each administratie carries `mode`).
	 *
	 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-004
	 * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-002
	 */
	#[NoAdminRequired]
	public function context(): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null || $userId === '') {
			return new JSONResponse(['activeAdministrationId' => null, 'administrations' => []]);
		}

		return new JSONResponse($this->administrationService->context($userId));
	}//end context()

}//end class
