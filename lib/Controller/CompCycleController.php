<?php

/**
 * Comp Cycle Controller
 *
 * The three cycle endpoints (comp-collective-raise-and-step-increase D8):
 * propose, approve and effectuate a whole compensation cycle. Each resolves
 * the cycle under the caller's own RBAC first (404 otherwise, so existence is
 * never leaked), then requires a Nextcloud administrator or the `hr` role in
 * the caller's active administration (403 otherwise). None creates or edits a
 * cycle or an adjustment field by field; that stays the object API (ADR-022).
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
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\CompAdjustmentService;
use OCA\Humaniq\Service\CompCollectiveService;
use OCA\Humaniq\Service\CompCycleApprover;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Propose, approve and effectuate a whole compensation cycle.
 *
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 */
class CompCycleController extends Controller {

	/**
	 * @param IRequest $request The request.
	 * @param CompCollectiveService $compCollectiveService Bulk proposal.
	 * @param CompCycleApprover $compCycleApprover Bulk approval.
	 * @param CompAdjustmentService $compAdjustmentService The cycle-wide effectuation batch.
	 * @param RbacObjectReader $rbac Reads the cycle under the caller's own RBAC.
	 * @param AdministrationService $administrationService The caller's role in the active administration.
	 * @param IGroupManager $groupManager The admin check.
	 * @param IUserSession $userSession The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly CompCollectiveService $compCollectiveService,
		private readonly CompCycleApprover $compCycleApprover,
		private readonly CompAdjustmentService $compAdjustmentService,
		private readonly RbacObjectReader $rbac,
		private readonly AdministrationService $administrationService,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * `POST /api/comp/cycles/propose`: propose one adjustment per employee the
	 * cycle covers, or per employee in a hand-picked selection. With `dryRun`
	 * it counts and lists, and writes nothing.
	 *
	 * @param string|null $cycleId The CompReviewCycle id.
	 * @param mixed $dryRun Truthy for a preview.
	 * @param mixed $employeeIds A list of Employee ids that replaces the cycle's scope.
	 *
	 * @return JSONResponse The outcome; 400 when the cycle cannot take bulk proposals.
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
	 */
	#[NoAdminRequired]
	public function proposeCollective(?string $cycleId = null, mixed $dryRun = false, mixed $employeeIds = []): JSONResponse {
		$caller = $this->authorizeCycle($cycleId);
		if ($caller instanceof JSONResponse) {
			return $caller;
		}

		$selection = (is_array($employeeIds) === true ? array_values(array_map('strval', $employeeIds)) : []);
		$result = $this->compCollectiveService->proposeForCycle((string)$cycleId, $caller, $this->flag($dryRun), $selection);

		return $this->respond($result);
	}//end proposeCollective()

	/**
	 * `POST /api/comp/cycles/approve`: approve every proposed adjustment in the
	 * cycle, each through its own guarded transition. The caller's own
	 * proposals come back as `refused-self-approval`.
	 *
	 * @param string|null $cycleId The CompReviewCycle id.
	 * @param string|null $decisionReason An optional reason the employees receive.
	 *
	 * @return JSONResponse Counts and one row per proposed adjustment.
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-002
	 */
	#[NoAdminRequired]
	public function approveCycle(?string $cycleId = null, ?string $decisionReason = null): JSONResponse {
		$caller = $this->authorizeCycle($cycleId);
		if ($caller instanceof JSONResponse) {
			return $caller;
		}

		return $this->respond($this->compCycleApprover->approveCycle((string)$cycleId, $caller, $decisionReason));
	}//end approveCycle()

	/**
	 * `POST /api/comp/cycles/effectuate`: effectuate every approved, due
	 * adjustment in the cycle through the existing batch. With `dryRun` it is
	 * the preview of what it would write.
	 *
	 * @param string|null $cycleId The CompReviewCycle id.
	 * @param mixed $dryRun Truthy for a preview.
	 *
	 * @return JSONResponse `{cycleId, dryRun, counts, outcomes}`.
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-003
	 */
	#[NoAdminRequired]
	public function effectuateCycle(?string $cycleId = null, mixed $dryRun = false): JSONResponse {
		$caller = $this->authorizeCycle($cycleId);
		if ($caller instanceof JSONResponse) {
			return $caller;
		}

		$preview = $this->flag($dryRun);
		$outcomes = $this->compAdjustmentService->effectuateCycle((string)$cycleId, null, $preview);
		$counts = [];
		foreach ($outcomes as $outcome) {
			$status = (string)($outcome['status'] ?? '');
			$counts[$status] = (($counts[$status] ?? 0) + 1);
		}

		return new JSONResponse(['cycleId' => $cycleId, 'dryRun' => $preview, 'counts' => $counts, 'outcomes' => $outcomes]);
	}//end effectuateCycle()

	/**
	 * The cycle endpoints' guard: a signed-in caller, a cycle their RBAC can
	 * read (404 otherwise, so existence is never leaked), and an administrator
	 * or the `hr` role in their active administration (403 otherwise).
	 *
	 * @param string|null $cycleId The posted CompReviewCycle id.
	 *
	 * @return string|JSONResponse The caller's uid, or the refusal.
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
	 */
	private function authorizeCycle(?string $cycleId): string|JSONResponse {
		$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') {
			return new JSONResponse(['error' => 'Niet ingelogd.'], Http::STATUS_UNAUTHORIZED);
		}

		$cycleId = trim((string)$cycleId);
		if ($cycleId === '') {
			return new JSONResponse(['error' => 'cycleId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->rbac->findOrNull(id: $cycleId, schema: 'CompReviewCycle') === null) {
			return new JSONResponse(['error' => 'Beloningsronde niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		if ($this->groupManager->isAdmin($uid) === false && $this->administrationService->getActiveAdministrationRole($uid) !== 'hr') {
			return new JSONResponse(['error' => 'Alleen HR of een beheerder kan een hele ronde verwerken.'], Http::STATUS_FORBIDDEN);
		}

		return $uid;
	}//end authorizeCycle()

	/**
	 * A service outcome as a response: a refusal is 400, the rest 200.
	 *
	 * @param array<string, mixed> $result The outcome.
	 *
	 * @return JSONResponse
	 */
	private function respond(array $result): JSONResponse {
		if (str_starts_with((string)($result['status'] ?? ''), 'refused-') === true) {
			return new JSONResponse($result, Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result);
	}//end respond()

	/**
	 * A request flag as a boolean: true, 1, "1", "true", "yes", "on".
	 *
	 * @param mixed $value The posted value.
	 *
	 * @return bool
	 */
	private function flag(mixed $value): bool {
		return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
	}//end flag()

}//end class
