<?php

/**
 * Humaniq ApprovalsController
 *
 * `GET /api/approvals?state=open|decided&kind=`: the caller's approvals inbox
 * (self-service-approvals-inbox D1, D4). Open lists every submitted request
 * waiting for the caller as manager or as a deputy today; decided lists what
 * the caller decided in the last 90 days with each request's timeline. Every
 * row is read under the caller's own rights. Approve and reject are not
 * routed here: the inbox posts OpenRegister's lifecycle transition, the same
 * one the detail pages post, so every guard still applies.
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
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\ApprovalsInboxService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The approvals inbox endpoint.
 *
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
 */
class ApprovalsController extends Controller {

	/**
	 * The kinds a caller may filter on.
	 */
	private const KINDS = ['leave', 'hours', 'expense', 'leave-trade'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ApprovalsInboxService $inbox The inbox composition.
	 * @param IUserSession $userSession The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly ApprovalsInboxService $inbox,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/approvals: the caller's own inbox, open or decided.
	 *
	 * The caller is the only subject: there is no id to swap, and every row
	 * is read under the caller's own OpenRegister rights.
	 *
	 * @param string $state open or decided.
	 * @param string|null $kind Only this kind of request.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
	 */
	#[NoAdminRequired]
	public function index(string $state = 'open', ?string $kind = null): JSONResponse {
		$uid = trim((string)($this->userSession->getUser()?->getUID() ?? ''));
		if ($uid === '') {
			return new JSONResponse(['error' => 'Not logged in.'], Http::STATUS_UNAUTHORIZED);
		}

		if (in_array($state, ['open', 'decided'], true) === false) {
			return new JSONResponse(['error' => 'state is open or decided.'], Http::STATUS_BAD_REQUEST);
		}

		if ($kind !== null && $kind !== '' && in_array($kind, self::KINDS, true) === false) {
			return new JSONResponse(['error' => 'kind is one of ' . implode(', ', self::KINDS) . '.'], Http::STATUS_BAD_REQUEST);
		}

		$today = gmdate('Y-m-d');
		$requests = ($state === 'open')
			? $this->inbox->open(uid: $uid, today: $today, kind: $kind)
			: $this->inbox->decided(uid: $uid, today: $today, kind: $kind);

		return new JSONResponse(['state' => $state, 'date' => $today, 'requests' => $requests]);
	}//end index()

}//end class
