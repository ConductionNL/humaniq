<?php

/**
 * Comp Controller
 *
 * Backs the `CompAdjustmentDetail` manifest page action "Effectueren"
 * (comp-cycles design.md D6): a single POST endpoint that resolves the posted
 * `adjustmentId` through OpenRegister's ObjectService under the caller's
 * ambient RBAC BEFORE any write (the `PayrollController::calculate()` /
 * `DocumentController` no-admin-idor pattern — an unknown or unauthorized
 * adjustmentId never reaches the effectuation service, and both collapse to
 * the same 404 so existence is never leaked), refuses non-approved
 * adjustments (400 — the deeper approved+due+within-band predicate is
 * re-checked by the service), then delegates to
 * `CompAdjustmentService::effectuateOne()`. ONE endpoint, no CRUD (ADR-022 —
 * the comp pages read/write the register declaratively via the object
 * store).
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
 * The three cycle endpoints (comp-collective-raise-and-step-increase D8)
 * propose, approve and effectuate a whole cycle. Each resolves the cycle under
 * the caller's RBAC first (404 otherwise), then requires a Nextcloud
 * administrator or the `hr` role in the caller's active administration (403
 * otherwise). None edits a cycle or an adjustment field by field.
 *
 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-006
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\CompAdjustmentService;
use OCA\Humaniq\Service\CompCollectiveService;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Guarded endpoints that effectuate one approved, due CompAdjustment, and
 * propose, approve and effectuate a whole cycle.
 *
 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-006
 */
class CompController extends Controller {

	/**
	 * @param IRequest $request The request object.
	 * @param ContainerInterface $container DI container for the RBAC-guarded ObjectService resolve.
	 * @param CompAdjustmentService $compAdjustmentService The effective-dating write service.
	 * @param SettingsService $settingsService The register-slug source.
	 * @param LoggerInterface $logger Logger.
	 * @param CompCollectiveService $compCollectiveService Bulk proposal and approval for a cycle.
	 * @param AdministrationService $administrationService The caller's role in the active administration.
	 * @param IGroupManager $groupManager The admin check.
	 * @param IUserSession $userSession The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly CompAdjustmentService $compAdjustmentService,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly CompCollectiveService $compCollectiveService,
		private readonly AdministrationService $administrationService,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `POST /api/comp/effectuate` — effectuate one approved, due
	 * CompAdjustment. The posted `adjustmentId` must resolve through
	 * ObjectService under the caller's RBAC before anything is written
	 * (unknown/unauthorized -> 404); a non-approved adjustment is refused
	 * (400) before the service is invoked.
	 *
	 * @param string|null $adjustmentId The CompAdjustment id (row-scoped, `@objectId` from the manifest action).
	 *
	 * @return JSONResponse The effectuation outcome, 400 on a missing/non-approved adjustment, 404 when it does not resolve.
	 *
	 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-006
	 */
	#[NoAdminRequired]
	public function effectuate(?string $adjustmentId = null): JSONResponse {
		$adjustmentId = trim((string)$adjustmentId);
		if ($adjustmentId === '') {
			return new JSONResponse(['error' => 'adjustmentId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		// No-admin-idor guard (ADR-005 Rule 3): the adjustment must resolve
		// through OpenRegister's ObjectService under the caller's RBAC before
		// any write — an unresolvable/unauthorized id never reaches the
		// effectuation service.
		$adjustment = $this->authorizeAdjustment($adjustmentId);
		if ($adjustment === null) {
			return new JSONResponse(['error' => 'Aanpassing niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$status = (string)($adjustment['status'] ?? '');
		if ($status !== 'approved') {
			return new JSONResponse(
				['error' => 'Aanpassing heeft status "' . $status . '" — alleen goedgekeurde aanpassingen kunnen worden geëffectueerd.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$result = $this->compAdjustmentService->effectuateOne($adjustmentId);

		$resultStatus = (string)($result['status'] ?? '');
		if ($resultStatus === 'failed') {
			return new JSONResponse($result, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if (str_starts_with($resultStatus, 'refused-') === true) {
			return new JSONResponse($result, Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result);
	}//end effectuate()

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

		return $this->respond($this->compCollectiveService->approveCycle((string)$cycleId, $caller, $decisionReason));
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

		if ($this->authorizeObject('CompReviewCycle', $cycleId) === null) {
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

	/**
	 * Resolve the posted adjustmentId through OpenRegister's ObjectService
	 * under the caller's ambient RBAC (default $_rbac=true) — the
	 * no-admin-idor guard for this endpoint (the
	 * `PayrollController::authorizeRun()` pattern). Returns null when the
	 * adjustment does not exist OR the caller's RBAC denies it (both
	 * collapse to the same 404 so existence is never leaked).
	 *
	 * @param string $adjustmentId The CompAdjustment id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-006
	 */
	private function authorizeAdjustment(string $adjustmentId): ?array {
		return $this->authorizeObject('CompAdjustment', $adjustmentId);
	}//end authorizeAdjustment()

	/**
	 * Resolve an object under the caller's ambient RBAC; null when it does not
	 * exist or the caller may not read it.
	 *
	 * @param string $schema The schema.
	 * @param string $id The object id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-006
	 */
	private function authorizeObject(string $schema, string $id): ?array {
		try {
			$row = $this->objectService()->find(
				id: $id,
				register: $this->settingsService->getRegisterSlug(),
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->info('CompController: ' . $schema . ' ' . $id . ' kon niet worden opgehaald: ' . $e->getMessage());
			return null;
		}

		if ($row === null) {
			return null;
		}

		return $this->toArray($row);
	}//end authorizeObject()

	/**
	 * @return mixed The OpenRegister ObjectService, resolved with the caller's ambient RBAC (default $_rbac=true).
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. Unguarded, an
		// instance without OpenRegister gets a container exception naming a
		// class the admin has never heard of; guarded, it is told which app to
		// install — which is rule 3's promise that the app still explains
		// itself.
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * Normalise an ObjectService row (entity or array) to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()

}//end class
