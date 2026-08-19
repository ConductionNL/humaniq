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
 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Controller;

use OCA\Hrmq\AppInfo\Application;
use OCA\Hrmq\Service\CompAdjustmentService;
use OCA\Hrmq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Guarded endpoint that effectuates one approved, due CompAdjustment.
 */
class CompController extends Controller {

	/**
	 * @param IRequest $request The request object.
	 * @param ContainerInterface $container DI container for the RBAC-guarded ObjectService resolve.
	 * @param CompAdjustmentService $compAdjustmentService The effective-dating write service.
	 * @param SettingsService $settingsService The register-slug source.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly CompAdjustmentService $compAdjustmentService,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
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
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
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
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
	 */
	private function authorizeAdjustment(string $adjustmentId): ?array {
		try {
			$adjustment = $this->objectService()->find(
				id: $adjustmentId,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'CompAdjustment'
			);
		} catch (\Throwable $e) {
			$this->logger->info('CompController: aanpassing ' . $adjustmentId . ' kon niet worden opgehaald: ' . $e->getMessage());
			return null;
		}

		if ($adjustment === null) {
			return null;
		}

		return $this->toArray($adjustment);
	}//end authorizeAdjustment()

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
				'hrmq requires the OpenRegister app, which is not installed on this instance.'
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
