<?php

/**
 * Loonbeslag Controller
 *
 * Backs the `LoonbeslagDetail` manifest page actions "Verifiëren en
 * activeren" / "Markeer voldaan" / "Intrekken" (loonbeslag design.md D5):
 * `Loonbeslag.status` carries no `x-openregister-lifecycle` map -- a
 * garnishment order is legally sensitive (it exposes an employee's debt
 * situation, and a wrong floor computation is a labour-law violation), so
 * every transition goes through this guarded controller, the SAME two-gate
 * shape as `PayrollController::mutations()`/`wkrAssess()`: an admin/HR
 * membership check returns 403 BEFORE any ObjectService resolve (an
 * unauthorized caller cannot even probe garnishment existence), then the
 * posted `loonbeslagId` is RBAC-resolved through ObjectService under the
 * caller's ambient RBAC (unknown/unauthorized -> 404, the `calculate()`/
 * `adjust()` no-admin-idor pattern), then a status-precondition check (400
 * on the wrong current status) before the stamped write. `activate()` is the
 * "verification" step named in the feature brief -- a second admin/HR
 * principal confirms the order before any deduction starts.
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
 * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Controller;

use OCA\Hrmq\AppInfo\Application;
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
use RuntimeException;

/**
 * Guarded endpoint that activates/settles/withdraws one Loonbeslag.
 */
class LoonbeslagController extends Controller {

	/**
	 * @param IRequest $request The request object.
	 * @param ContainerInterface $container DI container for the RBAC-guarded ObjectService resolve.
	 * @param SettingsService $settingsService The register-slug source.
	 * @param IUserSession $userSession The current user session (admin/HR check + stamped by/at fields).
	 * @param IGroupManager $groupManager To check the caller's admin membership (admin/HR gate).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `POST /api/loonbeslag/activate` -- verify and activate a `concept`
	 * Loonbeslag (`concept` -> `actief`), the second admin/HR principal's
	 * confirmation before any deduction starts.
	 *
	 * @param string|null $loonbeslagId The Loonbeslag id (row-scoped, `@objectId` from the manifest action).
	 *
	 * @return JSONResponse The updated Loonbeslag, 400 on a missing id or non-`concept` status, 403 for a non-admin/HR caller, 404 when it does not resolve.
	 *
	 * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-006
	 */
	#[NoAdminRequired]
	public function activate(?string $loonbeslagId = null): JSONResponse {
		return $this->transition(
			$loonbeslagId,
			expectedStatus: 'concept',
			newStatus: 'actief',
			wrongStatusMessage: 'alleen concept-loonbeslagen kunnen worden geactiveerd',
			stampFields: static fn (string $uid, string $now): array => ['activatedBy' => $uid, 'activatedAt' => $now],
			successMessage: 'Loonbeslag geverifieerd en geactiveerd.'
		);

	}//end activate()

	/**
	 * `POST /api/loonbeslag/settle` -- mark an `actief` Loonbeslag settled
	 * (`actief` -> `voldaan`), the claim satisfied.
	 *
	 * @param string|null $loonbeslagId The Loonbeslag id (row-scoped, `@objectId` from the manifest action).
	 *
	 * @return JSONResponse The updated Loonbeslag, 400 on a missing id or non-`actief` status, 403 for a non-admin/HR caller, 404 when it does not resolve.
	 *
	 * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-006
	 */
	#[NoAdminRequired]
	public function settle(?string $loonbeslagId = null): JSONResponse {
		return $this->transition(
			$loonbeslagId,
			expectedStatus: 'actief',
			newStatus: 'voldaan',
			wrongStatusMessage: 'alleen actieve loonbeslagen kunnen als voldaan worden gemarkeerd',
			stampFields: static fn (string $uid, string $now): array => ['settledBy' => $uid, 'settledAt' => $now],
			successMessage: 'Loonbeslag gemarkeerd als voldaan.'
		);

	}//end settle()

	/**
	 * `POST /api/loonbeslag/withdraw` -- withdraw a `concept` or `actief`
	 * Loonbeslag (terminal `-> ingetrokken`). A non-empty `reason` is
	 * required and stamped as `withdrawnReason`.
	 *
	 * @param string|null $loonbeslagId The Loonbeslag id (row-scoped, `@objectId` from the manifest action).
	 * @param string|null $reason Why the garnishment is being withdrawn.
	 *
	 * @return JSONResponse The updated Loonbeslag, 400 on a missing id/reason or an already-terminal status, 403 for a non-admin/HR caller, 404 when it does not resolve.
	 *
	 * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-006
	 */
	#[NoAdminRequired]
	public function withdraw(?string $loonbeslagId = null, ?string $reason = null): JSONResponse {
		if ($this->isAdminOrHr() === false) {
			return new JSONResponse(['error' => 'Alleen beheerders/HR mogen loonbeslagen intrekken.'], Http::STATUS_FORBIDDEN);
		}

		$loonbeslagId = trim((string)$loonbeslagId);
		if ($loonbeslagId === '') {
			return new JSONResponse(['error' => 'loonbeslagId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		$reason = trim((string)$reason);
		if ($reason === '') {
			return new JSONResponse(['error' => 'reason is verplicht bij het intrekken van een loonbeslag.'], Http::STATUS_BAD_REQUEST);
		}

		// No-admin-idor guard (ADR-005 Rule 3): the loonbeslag must resolve
		// through OpenRegister's ObjectService under the caller's RBAC before
		// any write -- an unresolvable/unauthorized id never reaches it.
		$loonbeslag = $this->authorizeLoonbeslag($loonbeslagId);
		if ($loonbeslag === null) {
			return new JSONResponse(['error' => 'Loonbeslag niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$status = (string)($loonbeslag['status'] ?? '');
		if (in_array($status, ['concept', 'actief'], true) === false) {
			return new JSONResponse(
				['error' => 'Loonbeslag heeft status "' . $status . '" — alleen concept- of actieve loonbeslagen kunnen worden ingetrokken.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$uid = $this->currentUserId();

		$saved = $this->save(
			$loonbeslag,
			[
				'status' => 'ingetrokken',
				'withdrawnBy' => $uid,
				'withdrawnAt' => gmdate('Y-m-d\TH:i:s\Z'),
				'withdrawnReason' => $reason,
			]
		);

		return new JSONResponse(['status' => 'ok', 'message' => 'Loonbeslag ingetrokken.', 'loonbeslag' => $saved]);
	}//end withdraw()

	/**
	 * The shared two-gate transition shape for `activate()`/`settle()`:
	 * admin/HR 403 gate BEFORE resolve, RBAC-resolve-first 404, then the
	 * status-precondition check (400) and the stamped write.
	 *
	 * @param string|null $loonbeslagId The Loonbeslag id.
	 * @param string $expectedStatus The required current status.
	 * @param string $newStatus The status to write.
	 * @param string $wrongStatusMessage Human-readable precondition failure reason.
	 * @param callable $stampFields `fn(string $uid, string $nowIso): array<string, mixed>` -- the transition-specific stamp fields.
	 * @param string $successMessage Human-readable success message.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-006
	 */
	private function transition(
		?string $loonbeslagId,
		string $expectedStatus,
		string $newStatus,
		string $wrongStatusMessage,
		callable $stampFields,
		string $successMessage,
	): JSONResponse {
		if ($this->isAdminOrHr() === false) {
			return new JSONResponse(['error' => 'Alleen beheerders/HR mogen loonbeslagen wijzigen.'], Http::STATUS_FORBIDDEN);
		}

		$loonbeslagId = trim((string)$loonbeslagId);
		if ($loonbeslagId === '') {
			return new JSONResponse(['error' => 'loonbeslagId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		// No-admin-idor guard (ADR-005 Rule 3): the loonbeslag must resolve
		// through OpenRegister's ObjectService under the caller's RBAC before
		// any write -- an unresolvable/unauthorized id never reaches it.
		$loonbeslag = $this->authorizeLoonbeslag($loonbeslagId);
		if ($loonbeslag === null) {
			return new JSONResponse(['error' => 'Loonbeslag niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$status = (string)($loonbeslag['status'] ?? '');
		if ($status !== $expectedStatus) {
			return new JSONResponse(
				['error' => 'Loonbeslag heeft status "' . $status . '" — ' . $wrongStatusMessage . '.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$uid = $this->currentUserId();

		$saved = $this->save(
			$loonbeslag,
			array_merge(['status' => $newStatus], $stampFields($uid, gmdate('Y-m-d\TH:i:s\Z')))
		);

		return new JSONResponse(['status' => 'ok', 'message' => $successMessage, 'loonbeslag' => $saved]);
	}//end transition()

	/**
	 * Whether the current caller is a Nextcloud admin -- the admin/HR gate
	 * (the `PayrollController::isAdminOrHr()` precedent). No dedicated "HR"
	 * Nextcloud group exists in this app yet, so the gate is the standard
	 * admin-group check; introducing a separate HR group is a named
	 * fast-follow shared across every admin/HR-gated endpoint in this app.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-006
	 */
	private function isAdminOrHr(): bool {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			return false;
		}

		return $this->groupManager->isAdmin($uid);
	}//end isAdminOrHr()

	/**
	 * The current caller's Nextcloud user id, or '' when there is none.
	 *
	 * @return string
	 */
	private function currentUserId(): string {
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}//end currentUserId()

	/**
	 * Merge the stamped fields onto the loonbeslag and save it in place
	 * (upsert keyed on its own id -- never a second record).
	 *
	 * @param array<string, mixed> $loonbeslag The current Loonbeslag.
	 * @param array<string, mixed> $updates Fields to merge over it.
	 *
	 * @return array<string, mixed> The saved object.
	 */
	private function save(array $loonbeslag, array $updates): array {
		$id = $this->idOf($loonbeslag);

		$object = array_merge($loonbeslag, $updates);
		unset($object['@self']);

		$saved = $this->objectService()->saveObject(
			object: $object,
			register: $this->settingsService->getRegisterSlug(),
			schema: 'Loonbeslag',
			uuid: ($id === '' ? null : $id)
		);

		return $this->toArray($saved);
	}//end save()

	/**
	 * Resolve the posted loonbeslagId through OpenRegister's ObjectService
	 * under the caller's ambient RBAC (default $_rbac=true) -- the
	 * no-admin-idor guard (the `PayrollController::authorizeAdjustment()`
	 * precedent). Returns null when the loonbeslag does not exist OR the
	 * caller's RBAC denies it (both collapse to the same 404 so existence is
	 * never leaked).
	 *
	 * @param string $loonbeslagId The Loonbeslag id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-006
	 */
	private function authorizeLoonbeslag(string $loonbeslagId): ?array {
		try {
			$loonbeslag = $this->objectService()->find(
				id: $loonbeslagId,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'Loonbeslag'
			);
		} catch (\Throwable $e) {
			$this->logger->info('LoonbeslagController: loonbeslag ' . $loonbeslagId . ' kon niet worden opgehaald: ' . $e->getMessage());
			return null;
		}

		if ($loonbeslag === null) {
			return null;
		}

		return $this->toArray($loonbeslag);
	}//end authorizeLoonbeslag()

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

	/**
	 * The object id of a row, falling back to `@self.id`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? $row['@self']['id'] ?? '');
	}//end idOf()

}//end class
