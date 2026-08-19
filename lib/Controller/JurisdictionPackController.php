<?php

/**
 * Jurisdiction Pack Controller
 *
 * The admin-only pack upload surface (jurisdiction-packs design.md D7/D11):
 * ONE endpoint, `POST /api/payroll/packs`, no CRUD (ADR-022).
 *
 * **This endpoint's payload determines people's wages.** Everything that makes
 * that acceptable is in `PackValidator` (nine blocking gates, including a
 * required in-process self-test dry-run) and in the DSL's closed grammar
 * (ADR-101 decisions 2 + 3: a pack can express arithmetic, never code). This
 * controller adds gate 7 — admin only — and nothing else: it does not
 * pre-filter, sanitise or "fix" a pack, because a pack that needs fixing must
 * be REJECTED, not repaired into something the uploader did not write.
 *
 * Rejections carry the offending op, reference, handler or bound in the error,
 * so a pack author can diagnose their own pack rather than being opaquely
 * refused (REQ-JP-008).
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-005
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-008
 */

declare(strict_types=1);

namespace OCA\Hrmq\Controller;

use OCA\Hrmq\AppInfo\Application;
use OCA\Hrmq\Payroll\Dsl\DslException;
use OCA\Hrmq\Service\JurisdictionPackService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Admin-only jurisdiction-pack upload.
 */
class JurisdictionPackController extends Controller {

	/**
	 * @param IRequest $request The request.
	 * @param JurisdictionPackService $packService The pack upload/validation service.
	 * @param IUserSession $userSession The user session.
	 * @param IGroupManager $groupManager The group manager (admin check).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly JurisdictionPackService $packService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `POST /api/payroll/packs` — validate and activate an uploaded
	 * jurisdiction pack.
	 *
	 * Gate 7 (admin-only) is enforced FIRST, before the payload is even
	 * parsed: a non-admin must not be able to probe the validator's behaviour,
	 * and pack validation executes the pack's own arithmetic.
	 *
	 * @param array<string, mixed>|null $pack The pack document.
	 * @param bool $override Explicitly activate this pack as a recorded override of a bundled pack (design.md D7 — overriding the bundled NL pack is a deliberate, auditable act).
	 *
	 * @return JSONResponse The stored pack summary; 400 when any gate rejects it (with the offending op/ref/handler/bound named), 403 for a non-admin caller.
	 *
	 * This method carried the no-admin-required attribute while the very first
	 * line of its body returns 403 to a non-admin — a contradiction. The
	 * annotation told Nextcloud's dispatcher "any logged-in user may call
	 * this" and the body then disagreed, so everything that reads the
	 * annotation rather than the body — the router, an audit, a human — got
	 * the wrong answer about who may reach this endpoint.
	 *
	 * AuthorizedAdminSetting is not a new decision; it is the one this code
	 * already documents. PackValidator's own header says "Gate 7, admin-only
	 * upload, is the controller's `AuthorizedAdminSetting`" — the attribute it
	 * names was simply never here. The isAdmin() check below is now defence in
	 * depth rather than the sole barrier. See phpstan.neon for why the app id
	 * is passed rather than a settings class.
	 *
	 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-005
	 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function upload(?array $pack = null, bool $override = false): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['error' => 'Alleen beheerders mogen jurisdictiepacks uploaden.'], Http::STATUS_FORBIDDEN);
		}

		if (is_array($pack) === false || $pack === []) {
			return new JSONResponse(['error' => 'Een pack-document is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$stored = $this->packService->upload($pack, $override);
		} catch (DslException $e) {
			// The pack is rejected, never repaired. The message names the
			// offending op/ref/handler/bound so the author can fix their pack.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('hrmq: jurisdictiepack-upload mislukt: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Pack-upload mislukt.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(
			[
				'packId' => $stored['packId'],
				'jurisdiction' => $stored['jurisdiction'],
				'taxYear' => $stored['taxYear'],
				'packVersion' => $stored['packVersion'],
				'engineVersion' => $stored['packId'] . '@' . $stored['packVersion'],
				'overridesBundled' => $stored['overridesBundled'],
				'provenance' => $stored['provenance'],
			]
		);

	}//end upload()

	/**
	 * Whether the caller is an administrator (design.md D11 gate 7).
	 *
	 * @return bool
	 */
	private function isAdmin(): bool {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			return false;
		}

		return $this->groupManager->isAdmin($uid);
	}//end isAdmin()

}//end class
