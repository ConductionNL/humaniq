<?php

/**
 * Interview Controller
 *
 * Backs the `InterviewDetail` manifest page action "Sync naar agenda"
 * (interview-scheduling design.md D6): a single POST endpoint that rejects a
 * non-admin/non-HR caller with 403 BEFORE any resolve (`isAdminOrHr()`, the
 * `PayrollController`/`LoonbeslagController`/`OfferController` precedent),
 * resolves the posted `interviewId` through OpenRegister's ObjectService
 * under the caller's ambient RBAC BEFORE any calendar write (the
 * `OfferController::authorizeApplication()` no-admin-idor guard --
 * unresolvable/unauthorized collapses to the same 404, never leaking
 * existence), then delegates to `InterviewCalendarService::syncOne()` for
 * exactly that one Interview.
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
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\InterviewCalendarService;
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

/**
 * Guarded endpoint that triggers a single Interview-to-calendar sync attempt.
 */
class InterviewController extends Controller {

	/**
	 * @param IRequest $request The request object.
	 * @param ContainerInterface $container DI container for the RBAC-guarded ObjectService resolve.
	 * @param InterviewCalendarService $interviewCalendarService The interview-calendar-sync service.
	 * @param SettingsService $settingsService The register-slug source.
	 * @param IUserSession $userSession The current user session (admin/HR check).
	 * @param IGroupManager $groupManager To check the caller's admin membership (admin/HR gate).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly InterviewCalendarService $interviewCalendarService,
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `POST /api/interviews/sync` -- a non-admin/non-HR caller is refused
	 * 403 before any resolve; the posted `interviewId` must then resolve
	 * through ObjectService under the caller's ambient RBAC (unknown/
	 * unauthorized -> 404, no calendar write).
	 *
	 * @param string|null $interviewId The Interview id (row-scoped, `@objectId` from the manifest action).
	 *
	 * @return JSONResponse The syncOne() outcome, 400 on a missing interviewId, 403 for a non-admin/HR caller, 404 when the Interview does not resolve.
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-008
	 */
	#[NoAdminRequired]
	public function sync(?string $interviewId = null): JSONResponse {
		if ($this->isAdminOrHr() === false) {
			return new JSONResponse(['error' => 'Alleen beheerders/HR mogen een gesprek naar de agenda synchroniseren.'], Http::STATUS_FORBIDDEN);
		}

		$interviewId = trim((string)$interviewId);
		if ($interviewId === '') {
			return new JSONResponse(['error' => 'interviewId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		// No-admin-idor guard (ADR-005 Rule 3): the Interview must resolve
		// through OpenRegister's ObjectService under the caller's RBAC
		// before any calendar write -- an unresolvable/unauthorized id
		// never reaches the service.
		$interview = $this->authorizeInterview($interviewId);
		if ($interview === null) {
			return new JSONResponse(['error' => 'Interview niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$outcome = $this->interviewCalendarService->syncOne($interviewId);

		return new JSONResponse($outcome);
	}//end sync()

	/**
	 * Whether the current caller is a Nextcloud admin -- the admin/HR gate
	 * (the `PayrollController::isAdminOrHr()` precedent; no dedicated "HR"
	 * Nextcloud group exists yet).
	 *
	 * @return bool
	 */
	private function isAdminOrHr(): bool {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			return false;
		}

		return $this->groupManager->isAdmin($uid);
	}//end isAdminOrHr()

	/**
	 * Resolve the posted interviewId through OpenRegister's ObjectService
	 * under the caller's ambient RBAC (default $_rbac=true) -- the
	 * no-admin-idor guard for this endpoint (the
	 * `OfferController::authorizeApplication()` shape). Returns null when
	 * the Interview does not exist OR the caller's RBAC denies it (both
	 * collapse to the same 404 so existence is never leaked to an
	 * unauthorized caller).
	 *
	 * @param string $interviewId The Interview id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-008
	 */
	private function authorizeInterview(string $interviewId): ?array {
		// ADR-083: establish availability before reaching, and degrade into the
		// 404 this method already documents. A controller is the wrong place to
		// turn a missing optional app into a 500 — the caller of an HTTP
		// endpoint cannot install anything, and "cannot be resolved" is exactly
		// the outcome the catch below already collapses to.
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$this->logger->info('InterviewController: OpenRegister is niet beschikbaar; interview ' . $interviewId . ' kan niet worden opgehaald.');
			return null;
		}

		try {
			$interview = $this->objectService()->find(
				id: $interviewId,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'Interview'
			);
		} catch (\Throwable $e) {
			$this->logger->info('InterviewController: interview ' . $interviewId . ' kon niet worden opgehaald: ' . $e->getMessage());
			return null;
		}

		if ($interview === null) {
			return null;
		}

		return $this->toArray($interview);
	}//end authorizeInterview()

	/**
	 * @return mixed The OpenRegister ObjectService, resolved with the caller's ambient RBAC (default $_rbac=true).
	 */
	private function objectService(): mixed {
		// Availability is established by the sole caller, authorizeInterview(),
		// before it reaches here (ADR-083).
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
