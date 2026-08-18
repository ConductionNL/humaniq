<?php

/**
 * Expense Controller
 *
 * Backs the `ExpenseDetail` manifest page action "Extract receipt data"
 * (receipt-ocr design.md D7): a single POST endpoint that resolves the
 * posted `expenseId` through OpenRegister's ObjectService under the caller's
 * ambient RBAC BEFORE any docudesk call (no-admin-idor guard, ADR-005 Rule 3
 * -- unresolvable/unauthorized both collapse to 404, never leaking
 * existence), THEN applies an explicit ownership check -- admin
 * (`PayrollController::isAdminOrHr()` precedent) OR the caller's Nextcloud
 * user id equals the resolved Expense's `userId` (the `MijnDeclaraties`
 * self-service convention) -- any other caller gets 403. Only once both
 * checks pass does it delegate to `ReceiptExtractionService::
 * extractForExpense()`.
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
 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Controller;

use OCA\Hrmq\AppInfo\Application;
use OCA\Hrmq\Service\ReceiptExtractionService;
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
 * Guarded endpoint that triggers a single receipt-extraction attempt.
 */
class ExpenseController extends Controller {

	/**
	 * @param IRequest $request The request object.
	 * @param ContainerInterface $container DI container for the RBAC-guarded ObjectService resolve.
	 * @param ReceiptExtractionService $receiptExtractionService The receipt-extraction service.
	 * @param SettingsService $settingsService The register-slug source.
	 * @param IUserSession $userSession The current user session (acting/owning userId).
	 * @param IGroupManager $groupManager To check the admin group (isAdminOrHr precedent).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly ReceiptExtractionService $receiptExtractionService,
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `POST /api/expenses/extract-receipt` -- resolves the posted
	 * `expenseId` under the caller's ambient RBAC (unresolvable/unauthorized
	 * -> 404) THEN an explicit ownership check (admin OR the caller owns the
	 * claim -> else 403), and only then triggers a single extraction attempt.
	 *
	 * @param string $expenseId The Expense id (row-scoped, `@objectId` from the manifest action).
	 *
	 * @return JSONResponse The extraction outcome, 404 when the Expense does not resolve, or 403 when the caller is neither admin nor owner.
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-007
	 */
	#[NoAdminRequired]
	public function extractReceipt(string $expenseId): JSONResponse {
		$expenseId = trim($expenseId);
		if ($expenseId === '') {
			return new JSONResponse(['error' => 'expenseId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		// No-admin-idor guard (ADR-005 Rule 3): the expense must resolve
		// through OpenRegister's ObjectService under the caller's RBAC before
		// any ownership decision or docudesk call -- an unresolvable/
		// unauthorized id never reaches either.
		$expense = $this->authorizeExpense($expenseId);
		if ($expense === null) {
			return new JSONResponse(['error' => 'Declaratie niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$uid = $this->userSession->getUser()?->getUID();
		if ($this->isAdminOrOwner($uid, $expense) === false) {
			return new JSONResponse(
				['error' => 'Alleen beheerders/HR of de indiener mogen de receipt-extractie starten.'],
				Http::STATUS_FORBIDDEN
			);
		}

		$result = $this->receiptExtractionService->extractForExpense($expenseId, $uid);

		return new JSONResponse($result);
	}//end extractReceipt()

	/**
	 * Resolve the posted expenseId through OpenRegister's ObjectService under
	 * the caller's ambient RBAC (default $_rbac=true) -- the no-admin-idor
	 * guard for this endpoint (mirrors `DocumentController::
	 * authorizeContract()`). Returns null when the Expense does not exist OR
	 * the caller's RBAC denies it (both collapse to the same 404 so existence
	 * is never leaked to an unauthorized caller).
	 *
	 * @param string $expenseId The Expense id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-007
	 */
	private function authorizeExpense(string $expenseId): ?array {
		try {
			$expense = $this->objectService()->find(
				id: $expenseId,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'Expense'
			);
		} catch (\Throwable $e) {
			$this->logger->info('ExpenseController: expense ' . $expenseId . ' kon niet worden opgehaald: ' . $e->getMessage());
			return null;
		}

		if ($expense === null) {
			return null;
		}

		return $this->toArray($expense);
	}//end authorizeExpense()

	/**
	 * The explicit ownership check applied AFTER the object is confirmed to
	 * exist and resolve under the caller's RBAC: a Nextcloud admin (the
	 * `PayrollController::isAdminOrHr()` "no dedicated HR group yet" gate) OR
	 * the caller's own claim (`Expense.userId` equals the caller's Nextcloud
	 * user id, the `MijnDeclaraties` self-service convention).
	 *
	 * @param string|null $uid The caller's Nextcloud user id.
	 * @param array<string, mixed> $expense The resolved Expense.
	 *
	 * @return bool
	 */
	private function isAdminOrOwner(?string $uid, array $expense): bool {
		if ($uid === null || $uid === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		return trim((string)($expense['userId'] ?? '')) === $uid;
	}//end isAdminOrOwner()

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
