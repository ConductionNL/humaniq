<?php

/**
 * Humaniq LeaveCostController
 *
 * GET /api/leave/requests/{id}/cost: what a leave request costs, per year and
 * per day. Whoever may read the request may read its cost; the request is
 * resolved as the caller first and answers 404 when unreadable
 * (leave-hours-from-the-working-pattern D4).
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
 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\LeaveCostService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The cost of a leave request.
 */
class LeaveCostController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest         $request The request.
	 * @param RbacObjectReader $rbac    Reads as the caller.
	 * @param LeaveCostService $costs   The cost.
	 *
	 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-003
	 */
	public function __construct(
		IRequest $request,
		private readonly RbacObjectReader $rbac,
		private readonly LeaveCostService $costs,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The cost of one request.
	 *
	 * @param string $id The LeaveRequest id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-003
	 */
	#[NoAdminRequired]
	public function cost(string $id): JSONResponse {
		$request = $this->rbac->findOrNull(id: $id, schema: 'LeaveRequest');
		if ($request === null) {
			return new JSONResponse(['message' => 'Leave request not found.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->costs->costOf($request));
	}//end cost()

}//end class
