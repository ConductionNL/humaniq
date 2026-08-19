<?php

/**
 * Leave Controller
 *
 * Backs the `LeaveTransactionDetail` manifest page action "Verrekenen"
 * (leave-buy-sell design.md D6): a single POST endpoint that resolves the
 * posted `transactionId` through OpenRegister's ObjectService under the
 * caller's ambient RBAC BEFORE any write (the `PayrollController::calculate()`/
 * `CompController::effectuate()` no-admin-idor pattern — an unknown or
 * unauthorized transactionId never reaches the settlement service, and both
 * collapse to the same 404 so existence is never leaked), refuses
 * non-approved transactions (400 — the deeper approved+settlementPeriod+
 * sufficiency predicate is re-checked by the service), then delegates to
 * `LeaveBuySellSettlementService::settle()`. ONE endpoint, no CRUD (ADR-022
 * — the leave pages read/write the register declaratively via the object
 * store). `settle` is deliberately NOT exposed as a bare `lifecycleActions`
 * button anywhere in the manifest — the balance write lives in the service,
 * so this guarded endpoint is the only settle path (the
 * `CompAdjustmentDetail` orphaned-capability precedent).
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
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Controller;

use OCA\Hrmq\AppInfo\Application;
use OCA\Hrmq\Service\LeaveBuySellSettlementService;
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
 * Guarded endpoint that settles one approved LeaveTransaction.
 */
class LeaveController extends Controller {

	/**
	 * @param IRequest $request The request object.
	 * @param ContainerInterface $container DI container for the RBAC-guarded ObjectService resolve.
	 * @param LeaveBuySellSettlementService $leaveBuySellSettlementService The balance-mutating settlement service.
	 * @param SettingsService $settingsService The register-slug source.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly LeaveBuySellSettlementService $leaveBuySellSettlementService,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `POST /api/leave/settle` — settle one approved LeaveTransaction (buy or
	 * sell leave hours). The posted `transactionId` must resolve through
	 * ObjectService under the caller's RBAC before anything is written
	 * (unknown/unauthorized -> 404); a non-approved transaction is refused
	 * (400) before the service is invoked — the deeper
	 * approved+settlementPeriod+sufficiency predicate is re-checked by
	 * `LeaveBuySellSettlementService::settle()` regardless.
	 *
	 * @param string|null $transactionId The LeaveTransaction id (row-scoped, `@objectId` from the manifest action).
	 *
	 * @return JSONResponse The settlement outcome, 400 on a missing/non-approved transaction, 404 when it does not resolve.
	 *
	 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-004
	 */
	#[NoAdminRequired]
	public function settle(?string $transactionId = null): JSONResponse {
		$transactionId = trim((string)$transactionId);
		if ($transactionId === '') {
			return new JSONResponse(['error' => 'transactionId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		// No-admin-idor guard (ADR-005 Rule 3): the transaction must resolve
		// through OpenRegister's ObjectService under the caller's RBAC before
		// any write -- an unresolvable/unauthorized id never reaches the
		// settlement service.
		$transaction = $this->authorizeTransaction($transactionId);
		if ($transaction === null) {
			return new JSONResponse(['error' => 'Transactie niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		if ((string)($transaction['status'] ?? '') !== 'approved') {
			return new JSONResponse(
				['error' => 'Transactie heeft status "' . ((string)($transaction['status'] ?? 'onbekend')) . '" — alleen goedgekeurde transacties kunnen worden verrekend.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$result = $this->leaveBuySellSettlementService->settle($transactionId);

		if ((string)$result['status'] === 'failed') {
			return new JSONResponse($result, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result);
	}//end settle()

	/**
	 * Resolve the posted transactionId through OpenRegister's ObjectService
	 * under the caller's ambient RBAC (default $_rbac=true) — the
	 * no-admin-idor guard for `settle()` (the `PayrollController::authorizeRun()`
	 * precedent). Returns null when the transaction does not exist OR the
	 * caller's RBAC denies it (both collapse to the same 404 so existence is
	 * never leaked).
	 *
	 * @param string $transactionId The LeaveTransaction id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-004
	 */
	private function authorizeTransaction(string $transactionId): ?array {
		try {
			$transaction = $this->objectService()->find(
				id: $transactionId,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'LeaveTransaction'
			);
		} catch (\Throwable $e) {
			$this->logger->info('LeaveController: transactie ' . $transactionId . ' kon niet worden opgehaald: ' . $e->getMessage());
			return null;
		}

		if ($transaction === null) {
			return null;
		}

		return $this->toArray($transaction);
	}//end authorizeTransaction()

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
