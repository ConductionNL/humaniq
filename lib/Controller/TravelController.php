<?php

/**
 * Humaniq TravelController
 *
 * The two travel actions of expenses-travel-calculation:
 *
 * - `POST /api/travel/route-distance` fills an arrangement's one-way
 *   distance from the route planner integriq provides, with its source, and
 *   saves it so the allowance is recalculated. The arrangement is read under
 *   the caller's own RBAC (404 otherwise); only its employee, HR or an
 *   administrator may change it (403); without a route planner the answer is
 *   409 with the reason and the typed distance stays.
 * - `POST /api/travel/wpm-report` compiles the yearly mobility figures of an
 *   administration, for HR of that administration or an administrator.
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
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-003
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\RbacObjectReader;
use OCA\Humaniq\Service\RouteDistanceService;
use OCA\Humaniq\Service\RouteDistanceUnavailableException;
use OCA\Humaniq\Service\WpmReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Route distance and the mobility report.
 *
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-003
 */
class TravelController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request               The request.
	 * @param RouteDistanceService  $routes                Asks integriq for a distance.
	 * @param WpmReportService      $reports               Compiles the mobility figures.
	 * @param HoursRegisterGateway  $gateway               Saves the arrangement.
	 * @param RbacObjectReader      $rbac                  Reads under the caller's own RBAC.
	 * @param AdministrationService $administrationService The caller's administration and role.
	 * @param IGroupManager         $groupManager          The admin check.
	 * @param IUserSession          $userSession           The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly RouteDistanceService $routes,
		private readonly WpmReportService $reports,
		private readonly HoursRegisterGateway $gateway,
		private readonly RbacObjectReader $rbac,
		private readonly AdministrationService $administrationService,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Fill an arrangement's distance from the route planner.
	 *
	 * @param string $arrangementId The CommuteArrangement id.
	 *
	 * @return JSONResponse The saved arrangement; 404, 403 or 409 otherwise.
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-003
	 */
	#[NoAdminRequired]
	public function routeDistance(string $arrangementId): JSONResponse {
		$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') {
			return new JSONResponse(['message' => 'Niet ingelogd.'], Http::STATUS_UNAUTHORIZED);
		}

		$arrangement = $this->rbac->findOrNull(id: $arrangementId, schema: 'CommuteArrangement');
		if ($arrangement === null) {
			return new JSONResponse(['message' => 'Reisregeling niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		if ($this->mayChange($uid, $arrangement) === false) {
			return new JSONResponse(['message' => 'Alleen de medewerker zelf, HR of een beheerder kan deze reisregeling wijzigen.'], Http::STATUS_FORBIDDEN);
		}

		try {
			$route = $this->routes->lookup(
				(string)($arrangement['originPostcode'] ?? ''),
				(string)($arrangement['destinationPostcode'] ?? '')
			);
		} catch (RouteDistanceUnavailableException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		}

		$this->gateway->save(
			['distanceKmOneWay' => $route['distanceKm'], 'distanceSource' => 'routeplanner', 'routeProvider' => $route['provider']],
			'CommuteArrangement',
			$arrangementId
		);

		// Read back what was stored, so the answer carries the allowance the
		// save recalculated rather than the three fields sent.
		return new JSONResponse(($this->gateway->findObjectData($arrangementId, 'CommuteArrangement') ?? []));
	}//end routeDistance()

	/**
	 * Compile the mobility report of an administration for a year.
	 *
	 * @param string $administrationId The administration; the active one when empty.
	 * @param int    $year             The calendar year.
	 *
	 * @return JSONResponse The report; 403 for anyone but HR or an administrator.
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-004
	 */
	#[NoAdminRequired]
	public function wpmReport(string $administrationId='', int $year=0): JSONResponse {
		$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') {
			return new JSONResponse(['message' => 'Niet ingelogd.'], Http::STATUS_UNAUTHORIZED);
		}

		$active = (string)($this->administrationService->getActiveAdministrationId($uid) ?? '');
		$administrationId = trim($administrationId) === '' ? $active : trim($administrationId);
		if ($this->mayCompile($uid, $administrationId, $active) === false) {
			return new JSONResponse(['message' => 'Alleen HR van deze administratie of een beheerder kan het mobiliteitsrapport opstellen.'], Http::STATUS_FORBIDDEN);
		}

		if ($year < 2000 || $year > 2100) {
			$year = (int)gmdate('Y');
		}

		return new JSONResponse($this->reports->compile($administrationId, $year, $uid));
	}//end wpmReport()

	/**
	 * Whether the caller may change the arrangement: its own employee while it
	 * is a draft or rejected, or HR or an administrator until it has ended.
	 *
	 * @param string               $uid         The caller.
	 * @param array<string, mixed> $arrangement The arrangement.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-003
	 */
	private function mayChange(string $uid, array $arrangement): bool {
		if (($arrangement['status'] ?? null) === 'ended') {
			return false;
		}

		// The employee changes their own arrangement only before it is
		// approved; after that a new distance goes through HR.
		if ((string)($arrangement['userId'] ?? '') === $uid && in_array(($arrangement['status'] ?? 'draft'), ['draft', 'rejected'], true) === true) {
			return true;
		}

		return $this->groupManager->isAdmin($uid) === true || $this->administrationService->getActiveAdministrationRole($uid) === 'hr';
	}//end mayChange()

	/**
	 * Whether the caller may compile the report of an administration: an
	 * administrator, or HR in it as their active administration.
	 *
	 * @param string $uid              The caller.
	 * @param string $administrationId The administration asked for.
	 * @param string $active           The caller's active administration.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-004
	 */
	private function mayCompile(string $uid, string $administrationId, string $active): bool {
		if ($this->groupManager->isAdmin($uid) === true) {
			return $administrationId !== '';
		}

		return $administrationId !== '' && $administrationId === $active && $this->administrationService->getActiveAdministrationRole($uid) === 'hr';
	}//end mayCompile()

}//end class
