<?php

/**
 * The offboarding page actions: revoke access, calculate the transition
 * payment, and the leavers' reasons (hiring-offboarding-completion D1-D3).
 * Each resolves the case under the caller's own rights first (404 when it
 * cannot be read), then checks the role (403).
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AccessRevocationService;
use OCA\Humaniq\Service\ExitInterviewService;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\RbacObjectReader;
use OCA\Humaniq\Service\TransitionPaymentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The offboarding endpoints.
 *
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
 */
class OffboardingController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request     The request.
	 * @param RbacObjectReader         $rbac        Reads the case under the caller's rights.
	 * @param HumaniqRoles             $roles       Who is HR or payroll.
	 * @param AccessRevocationService  $revocation  Disables the account.
	 * @param TransitionPaymentService $payments    Calculates the payment.
	 * @param ExitInterviewService     $interviews  Counts the reasons.
	 * @param IUserSession             $userSession The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly RbacObjectReader $rbac,
		private readonly HumaniqRoles $roles,
		private readonly AccessRevocationService $revocation,
		private readonly TransitionPaymentService $payments,
		private readonly ExitInterviewService $interviews,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Disable the leaver's Nextcloud account and tick the case. HR or an
	 * administrator only.
	 *
	 * @param string $id The offboarding case.
	 *
	 * @return JSONResponse 200 with the case; 404, 403 or 409 with the reason.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
	 */
	#[NoAdminRequired]
	public function revokeAccess(string $id): JSONResponse {
		$uid = $this->uid();
		if ($this->rbac->findOrNull(id: $id, schema: 'Offboarding') === null) {
			return new JSONResponse(['message' => 'Offboarding case not found.'], Http::STATUS_NOT_FOUND);
		}

		if ($this->roles->isHr($uid) === false) {
			return new JSONResponse(['message' => 'Only HR or an administrator can revoke access.'], Http::STATUS_FORBIDDEN);
		}

		$result = $this->revocation->revoke(offboardingId: $id, actorUid: $uid, now: gmdate('c'));
		if ($result['status'] !== Http::STATUS_OK) {
			return new JSONResponse(['message' => $result['message']], $result['status']);
		}

		return new JSONResponse(['message' => $result['message'], 'offboarding' => $result['offboarding']]);
	}//end revokeAccess()

	/**
	 * Calculate the statutory transition payment and store it on the case.
	 * HR, payroll or an administrator only.
	 *
	 * @param string $id The offboarding case.
	 *
	 * @return JSONResponse 200 with the breakdown (zero with the reason when none is due); 404 or 403.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-004
	 */
	#[NoAdminRequired]
	public function transitionPayment(string $id): JSONResponse {
		$uid = $this->uid();
		if ($this->rbac->findOrNull(id: $id, schema: 'Offboarding') === null) {
			return new JSONResponse(['message' => 'Offboarding case not found.'], Http::STATUS_NOT_FOUND);
		}

		if ($this->roles->isHr($uid) === false && $this->roles->isPayroll($uid) === false) {
			return new JSONResponse(['message' => 'Only HR, payroll or an administrator can calculate the transition payment.'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->payments->calculateFor(offboardingId: $id);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		}

		if ($result === null) {
			return new JSONResponse(['message' => 'Offboarding case not found.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result);
	}//end transitionPayment()

	/**
	 * The leavers' own reasons counted over the last months. HR only.
	 *
	 * @param int $months How many months back, 1 to 60.
	 *
	 * @return JSONResponse The counts; 403 for anyone but HR or an administrator.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-001
	 */
	#[NoAdminRequired]
	public function exitReasons(int $months=12): JSONResponse {
		if ($this->roles->isHr($this->uid()) === false) {
			return new JSONResponse(['message' => 'Only HR or an administrator can see the reasons for leaving.'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($this->interviews->reasonCounts(today: gmdate('Y-m-d'), months: min(60, max(1, $months))));
	}//end exitReasons()

	/**
	 * The caller's uid, or ''.
	 *
	 * @return string
	 */
	private function uid(): string {
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}//end uid()
}//end class
