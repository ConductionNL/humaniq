<?php

/**
 * Offer Controller
 *
 * Backs the `ApplicationDetail` manifest page action "request-offer-signature"
 * (offer-esign design.md D8): a single POST endpoint that rejects a
 * non-admin/non-HR caller with 403 BEFORE any resolve (`isAdminOrHr()`, the
 * `PayrollController::mutations()` precedent), resolves the posted
 * `applicationId` through OpenRegister's ObjectService under the caller's
 * ambient RBAC BEFORE any docudesk call (the `DocumentController`
 * no-admin-idor guard -- unresolvable/unauthorized collapses to the same 404,
 * never leaking existence), refuses a non-`aanbod` Application with 400, then
 * delegates to `OfferEsignService::requestSignature()`.
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
 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Controller;

use OCA\Hrmq\AppInfo\Application;
use OCA\Hrmq\Service\OfferEsignService;
use OCA\Hrmq\Service\SettingsService;
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
 * Guarded endpoint that triggers a single offer-letter + e-signature-request attempt.
 */
class OfferController extends Controller {

	/**
	 * @param IRequest $request The request object.
	 * @param ContainerInterface $container DI container for the RBAC-guarded ObjectService resolve.
	 * @param OfferEsignService $offerEsignService The offer-letter + e-signature service.
	 * @param SettingsService $settingsService The register-slug source.
	 * @param IUserSession $userSession The current user session (acting userId + admin/HR check).
	 * @param IGroupManager $groupManager To check the caller's admin membership (admin/HR gate).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly OfferEsignService $offerEsignService,
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `POST /api/offer/request-signature` -- a non-admin/non-HR caller is
	 * refused 403 before any resolve; the posted `applicationId` must then
	 * resolve through ObjectService under the caller's ambient RBAC
	 * (unknown/unauthorized -> 404, no docudesk call); a resolved Application
	 * not in status `aanbod` is refused 400.
	 *
	 * @param string|null $applicationId The Application id (row-scoped, `@objectId` from the manifest action).
	 *
	 * @return JSONResponse The requestSignature() outcome, 400 on a missing applicationId or wrong stage, 403 for a non-admin/HR caller, 404 when the Application does not resolve.
	 *
	 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-007
	 */
	#[NoAdminRequired]
	public function requestSignature(?string $applicationId = null): JSONResponse {
		if ($this->isAdminOrHr() === false) {
			return new JSONResponse(['error' => 'Alleen beheerders/HR mogen een aanbiedingsbrief/e-handtekening aanvragen.'], Http::STATUS_FORBIDDEN);
		}

		$applicationId = trim((string)$applicationId);
		if ($applicationId === '') {
			return new JSONResponse(['error' => 'applicationId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		// No-admin-idor guard (ADR-005 Rule 3): the Application must resolve
		// through OpenRegister's ObjectService under the caller's RBAC
		// before any docudesk call -- an unresolvable/unauthorized id never
		// reaches it.
		$application = $this->authorizeApplication($applicationId);
		if ($application === null) {
			return new JSONResponse(['error' => 'Sollicitatie niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$status = (string)($application['status'] ?? '');
		if ($status !== 'aanbod') {
			return new JSONResponse(
				['error' => 'Sollicitatie heeft status "' . $status . '" -- alleen sollicitaties in status "aanbod" komen in aanmerking.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$userId = $this->userSession->getUser()?->getUID();
		$result = $this->offerEsignService->requestSignature($applicationId, $userId);

		return new JSONResponse($result);
	}//end requestSignature()

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
	 * Resolve the posted applicationId through OpenRegister's ObjectService
	 * under the caller's ambient RBAC (default $_rbac=true) -- the
	 * no-admin-idor guard for this endpoint (the `DocumentController::authorizeContract()`
	 * shape). Returns null when the Application does not exist OR the
	 * caller's RBAC denies it (both collapse to the same 404 so existence is
	 * never leaked to an unauthorized caller).
	 *
	 * @param string $applicationId The Application id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-007
	 */
	private function authorizeApplication(string $applicationId): ?array {
		// ADR-083: establish availability before reaching, and degrade into the
		// 404 this method already documents. A controller is the wrong place to
		// turn a missing optional app into a 500 — the caller of an HTTP
		// endpoint cannot install anything, and "cannot be resolved" is exactly
		// the outcome the catch below already collapses to.
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$this->logger->info('OfferController: OpenRegister is niet beschikbaar; application ' . $applicationId . ' kan niet worden opgehaald.');
			return null;
		}

		try {
			$application = $this->objectService()->find(
				id: $applicationId,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'Application'
			);
		} catch (\Throwable $e) {
			$this->logger->info('OfferController: application ' . $applicationId . ' kon niet worden opgehaald: ' . $e->getMessage());
			return null;
		}

		if ($application === null) {
			return null;
		}

		return $this->toArray($application);
	}//end authorizeApplication()

	/**
	 * @return mixed The OpenRegister ObjectService, resolved with the caller's ambient RBAC (default $_rbac=true).
	 */
	private function objectService(): mixed {
		// Availability is established by the sole caller,
		// authorizeApplication(), before it reaches here (ADR-083).
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
