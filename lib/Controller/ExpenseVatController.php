<?php

/**
 * Expense VAT controller
 *
 * `GET /api/expenses/vat?period=YYYY-MM`: the VAT to reclaim on the claims
 * reimbursed in a month, for HR and the accountant role (REQ-RCPT-008).
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
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/receipt-ocr/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use InvalidArgumentException;
use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\ExpenseVatService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The VAT overview per month.
 *
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/receipt-ocr/spec.md#requirement-a-claim-shall-record-its-vat-and-finance-shall-see-the-vat-to-reclaim-per-period-req-rcpt-008
 */
class ExpenseVatController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request     The request.
	 * @param ExpenseVatService $vat         The overview.
	 * @param IUserSession      $userSession The caller.
	 * @param LoggerInterface   $logger      Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ExpenseVatService $vat,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * `GET /api/expenses/vat?period=YYYY-MM`, the previous month by default.
	 *
	 * @return JSONResponse 200 {period, administrationId, count, totalVat, claims}, 400 for a bad period, 401 signed out, 403 outside HR and accountants.
	 *
	 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/receipt-ocr/spec.md#requirement-a-claim-shall-record-its-vat-and-finance-shall-see-the-vat-to-reclaim-per-period-req-rcpt-008
	 */
	#[NoAdminRequired]
	public function overview(): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null || $userId === '') {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$period = trim((string)$this->request->getParam('period', ''));
		if ($period === '') {
			$period = (new \DateTimeImmutable('first day of last month'))->format('Y-m');
		}

		try {
			$overview = $this->vat->overview(userId: $userId, period: $period);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['message' => 'Invalid period'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('ExpenseVatController: the VAT overview could not be read.', ['exceptionClass' => $e::class]);
			return new JSONResponse(['message' => 'VAT overview unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($overview === null) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($overview);
	}//end overview()
}//end class
