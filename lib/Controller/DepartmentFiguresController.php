<?php

/**
 * Department Figures Controller
 *
 * department-figures: the absence rate, absence frequency, wage cost and
 * open vacancies of org units. HR and accountants compare units side by
 * side (REQ-DPF-001); a manager reads the units they lead, as unit totals
 * under the small-unit rule (REQ-DPF-002). Access is resolved by
 * {@see AnalyticsAccess} from the caller's own access rows and the org
 * tree, never from a request parameter naming an administration.
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
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use InvalidArgumentException;
use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AnalyticsAccess;
use OCA\Humaniq\Service\DepartmentFiguresService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Guarded read-only endpoints for unit figures.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */
class DepartmentFiguresController extends Controller {

	/**
	 * @param IRequest                 $request           The request.
	 * @param AnalyticsAccess          $access            Who may read which figures.
	 * @param DepartmentFiguresService $departmentFigures The unit figures.
	 * @param IUserSession             $userSession       The acting user.
	 * @param LoggerInterface          $logger            Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly AnalyticsAccess $access,
		private readonly DepartmentFiguresService $departmentFigures,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * `GET /api/analytics/units?period=&parentUnitId=`: the units under a
	 * unit (or the top units) side by side, with their absence rate,
	 * frequency and wage cost. HR and accountants only.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	#[NoAdminRequired]
	public function units(): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null || $userId === '') {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->access->fullReaderAdministration($userId);
		if ($administrationId === null) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$parent = trim((string)$this->request->getParam('parentUnitId', ''));

		return $this->figuresResponse(
			fn (string $period): array => $this->departmentFigures->compare($administrationId, $period, ($parent === '') ? null : $parent)
		);
	}//end units()

	/**
	 * `GET /api/analytics/my-units?period=`: the figures of the units the
	 * caller manages, as unit totals under the small-unit rule. Someone who
	 * manages nothing gets an empty list.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
	 */
	#[NoAdminRequired]
	public function myUnits(): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null || $userId === '') {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->access->activeAdministration($userId);
		if ($administrationId === null) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return $this->figuresResponse(
			fn (string $period): array => $this->departmentFigures->managedUnits($userId, $administrationId, $period)
		);
	}//end myUnits()

	/**
	 * `GET /api/analytics/unit-figures?orgUnitId=&period=`: one unit's
	 * figures, for HR and accountants, or for the unit's manager under the
	 * small-unit rule.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
	 */
	#[NoAdminRequired]
	public function unitFigures(): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null || $userId === '') {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$orgUnitId = trim((string)$this->request->getParam('orgUnitId', ''));
		$access = $this->access->unitReader($userId, $orgUnitId);
		if ($access === null || $orgUnitId === '') {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$response = $this->figuresResponse(
			fn (string $period): array => ($this->departmentFigures->unitFigures($access['administrationId'], $orgUnitId, $period, $access['minimumMembers']) ?? [])
		);
		if ($response->getStatus() === Http::STATUS_OK && $response->getData() === []) {
			return new JSONResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return $response;
	}//end unitFigures()

	/**
	 * Run a unit-figures read for the requested period and map its failures
	 * onto static messages.
	 *
	 * @param callable(string): array<string, mixed> $read The read.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	private function figuresResponse(callable $read): JSONResponse {
		$period = (string)$this->request->getParam('period', 'quarter');
		try {
			return new JSONResponse($read($period));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['message' => 'Invalid period'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[DepartmentFiguresController] unit figures failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Analytics unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end figuresResponse()

}//end class
