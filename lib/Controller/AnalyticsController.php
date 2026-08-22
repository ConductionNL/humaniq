<?php

/**
 * Analytics Controller
 *
 * Backs the Dashboard's five trend widgets and the Obligations list
 * (humaniq-dashboard-steering-indicators): `GET /api/analytics/trends` and
 * `GET /api/analytics/obligations`, mirroring pipelinq's `AnalyticsController`
 * shape (401 no session, 403 wrong/no role, 400 unknown metric/period, 500 on
 * unexpected failure).
 *
 * humaniq has NO ambient access control today — 0 of 55 schemas declare an
 * `authorization` block, and OpenRegister's `PermissionHandler` treats an
 * empty block as default-OPEN. This is the first humaniq endpoint that reads
 * `AdministrationAccess.role` past row presence: every action here requires
 * the caller's ACTIVE administration to carry a `hr` or `accountant` role row
 * — resolved server-side via `AdministrationService`, exactly as
 * `PageController::index()` already resolves it for `IInitialState`. NEITHER
 * action accepts an `administrationId` request parameter, ever: trusting a
 * client-supplied value (even a correctly-substituted one) would let a caller
 * ask for a tenant they merely typed rather than one their own
 * `AdministrationAccess` rows grant (design.md D4).
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
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use InvalidArgumentException;
use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\AnalyticsService;
use OCA\Humaniq\Service\ObligationsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Guarded read-only analytics endpoints for the Dashboard's steering
 * indicators.
 *
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-005
 */
class AnalyticsController extends Controller {

	/**
	 * `AdministrationAccess.role` values this endpoint admits
	 * (REQ-DSI-005) — `employee` is refused.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_ROLES = ['hr', 'accountant'];

	/**
	 * @param IRequest $request The request object.
	 * @param AnalyticsService $analyticsService The trends aggregation service.
	 * @param ObligationsService $obligationsService The cross-schema obligations merge service.
	 * @param AdministrationService $administrationService Resolves the caller's active administration + role.
	 * @param IUserSession $userSession The current user session (acting userId).
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly AnalyticsService $analyticsService,
		private readonly ObligationsService $obligationsService,
		private readonly AdministrationService $administrationService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `GET /api/analytics/trends?metric=&period=` — time-series data for
	 * one of the three `endpointSource`-bound trend widgets.
	 *
	 * @return JSONResponse The trend payload, or an error envelope.
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-004
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-005
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-006
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-007
	 */
	#[NoAdminRequired]
	public function trends(): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null || $userId === '') {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->authorizeCaller($userId);
		if ($administrationId === null) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$metric = (string)$this->request->getParam('metric', '');
		$period = (string)$this->request->getParam('period', AnalyticsService::DEFAULT_PERIOD);

		try {
			return new JSONResponse($this->analyticsService->getTrends($metric, $period, $administrationId));
		} catch (InvalidArgumentException $e) {
			// Map the (static) service exception text onto a controller-owned
			// static label so the response envelope never carries through any
			// value derived from $e->getMessage() — the pipelinq precedent.
			$label = ($e->getMessage() === 'Invalid period') ? 'Invalid period' : 'Unsupported metric';
			return new JSONResponse(['message' => $label], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[AnalyticsController] trends failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Analytics unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end trends()

	/**
	 * `GET /api/analytics/obligations` — the merged, rule-badged Obligations
	 * list backing the Dashboard's full-width `object-table` widget.
	 *
	 * @return JSONResponse `{obligations: [...]}`, or an error envelope.
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-005
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-008
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-009
	 */
	#[NoAdminRequired]
	public function obligations(): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null || $userId === '') {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->authorizeCaller($userId);
		if ($administrationId === null) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse(['obligations' => $this->obligationsService->getObligations($administrationId)]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[AnalyticsController] obligations failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Analytics unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end obligations()

	/**
	 * Resolve + authorize the caller (REQ-DSI-005): the caller's active
	 * administration id when their `AdministrationAccess` role for it is
	 * `hr` or `accountant`, else `null`. Deliberately reads NO request
	 * parameter of any name — the only tenant a caller may query is the one
	 * their own access rows already grant.
	 *
	 * @param string $userId The Nextcloud user id.
	 *
	 * @return string|null The authorized active administration id, or null when unauthorized.
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function authorizeCaller(string $userId): ?string {
		$administrationId = $this->administrationService->getActiveAdministrationId($userId);
		if ($administrationId === null) {
			return null;
		}

		$role = $this->administrationService->getActiveAdministrationRole($userId);
		if (in_array($role, self::ALLOWED_ROLES, true) === false) {
			return null;
		}

		return $administrationId;
	}//end authorizeCaller()

}//end class
