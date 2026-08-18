<?php

/**
 * Roster Controller
 *
 * Backs the `RosterDetail` manifest page action "ATW-controle" (rostering MVP
 * design D5, REQ-ROST-005): a single POST endpoint that resolves the posted
 * `rosterId` through OpenRegister's ObjectService under the caller's ambient
 * RBAC BEFORE any computation (the `DocumentController` no-admin-idor pattern
 * — an unknown or unauthorized rosterId never reaches the check, and both
 * collapse to the same 404 so existence is never leaked), then delegates to
 * `RosterCheckService::checkRoster()`. ONE endpoint, no CRUD (ADR-022 — the
 * roster/shift/assignment pages read/write the register declaratively via
 * the object store).
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
 * @spec openspec/changes/rostering/specs/rostering/spec.md#REQ-ROST-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Controller;

use OCA\Hrmq\AppInfo\Application;
use OCA\Hrmq\Service\RosterCheckService;
use OCA\Hrmq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guarded endpoint that runs the on-demand ATW cross-check for one roster.
 */
class RosterController extends Controller {

	/**
	 * @param IRequest $request The request object.
	 * @param ContainerInterface $container DI container for the RBAC-guarded ObjectService resolve.
	 * @param RosterCheckService $rosterCheckService The on-demand roster ATW auditor.
	 * @param SettingsService $settingsService The register-slug source.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly RosterCheckService $rosterCheckService,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `POST /api/roster/check` — run the ATW cross-check for one Roster. The
	 * posted `rosterId` must resolve through ObjectService under the
	 * caller's RBAC before anything is checked (unknown/unauthorized -> 404,
	 * no assignments loaded or evaluated).
	 *
	 * @param string|null $rosterId The Roster id (row-scoped, `@objectId` from the manifest action).
	 *
	 * @return JSONResponse The check report, 400 on a missing rosterId, 404 when the roster does not resolve.
	 *
	 * @spec openspec/changes/rostering/specs/rostering/spec.md#REQ-ROST-005
	 */
	#[NoAdminRequired]
	public function check(?string $rosterId = null): JSONResponse {
		$rosterId = trim((string)$rosterId);
		if ($rosterId === '') {
			return new JSONResponse(['error' => 'rosterId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		// No-admin-idor guard (ADR-005 Rule 3): the roster must resolve
		// through OpenRegister's ObjectService under the caller's RBAC
		// before any assignment is loaded or evaluated.
		if ($this->authorizeRoster($rosterId) === null) {
			return new JSONResponse(['error' => 'Roster niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$report = $this->rosterCheckService->checkRoster($rosterId);

		return new JSONResponse($report);
	}//end check()

	/**
	 * Resolve the posted rosterId through OpenRegister's ObjectService under
	 * the caller's ambient RBAC (default $_rbac=true) — the no-admin-idor
	 * guard for this endpoint. Returns null when the roster does not exist
	 * OR the caller's RBAC denies it (both collapse to the same 404 so
	 * existence is never leaked to an unauthorized caller).
	 *
	 * @param string $rosterId The Roster id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/rostering/specs/rostering/spec.md#REQ-ROST-005
	 */
	private function authorizeRoster(string $rosterId): ?array {
		try {
			$roster = $this->objectService()->find(
				id: $rosterId,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'Roster'
			);
		} catch (\Throwable $e) {
			$this->logger->info('RosterController: roster ' . $rosterId . ' kon niet worden opgehaald: ' . $e->getMessage());
			return null;
		}

		if ($roster === null) {
			return null;
		}

		return $this->toArray($roster);
	}//end authorizeRoster()

	/**
	 * @return mixed The OpenRegister ObjectService, resolved with the caller's ambient RBAC (default $_rbac=true).
	 */
	private function objectService(): mixed {
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
