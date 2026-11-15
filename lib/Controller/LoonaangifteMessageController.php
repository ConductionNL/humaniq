<?php

/**
 * LoonaangifteMessageController
 *
 * POST /api/loonaangifte/filings/{filingId}/message makes the wage tax
 * return message of a filing (filings-wage-tax-message D1). HR, payroll or
 * an administrator only; the filing is read under the caller's RBAC first,
 * so a filing the caller cannot see is a 404.
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\CallerContext;
use OCA\Humaniq\Service\PayrollTaxFilingServices;
use OCA\Humaniq\Service\SettingsService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Make the wage tax return message of a filing.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */
class LoonaangifteMessageController extends Controller {

	/**
	 * The controller.
	 *
	 * @param IRequest                   $request     The request.
	 * @param SettingsService            $settings    The register slug.
	 * @param PayrollTaxFilingServices $filings Builds the messages and the corrections.
	 * @param CallerContext $caller The signed-in caller, their roles, the container and the logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly SettingsService $settings,
		private readonly PayrollTaxFilingServices $filings,
		private readonly CallerContext $caller,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Make the message: 200 when made, 409 when refused or blocked by findings.
	 *
	 * @param string|null $filingId The LoonaangifteFiling.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
	 */
	#[NoAdminRequired]
	public function render(?string $filingId=null): JSONResponse {
		$uid = $this->caller->userSession->getUser()?->getUID();
		if ($this->caller->roles->isHr($uid) === false && $this->caller->roles->isPayroll($uid) === false) {
			return new JSONResponse(['error' => 'Alleen HR, de salarisadministratie en beheerders mogen het aangiftebericht maken.'], 403);
		}

		$filingId = trim((string)$filingId);
		if ($filingId === '') {
			return new JSONResponse(['error' => 'filingId is verplicht.'], 400);
		}

		$filing = $this->readableFiling($filingId);
		if ($filing === null) {
			return new JSONResponse(['error' => 'Aangifte niet gevonden.'], 404);
		}

		if ((string)($filing['filingType'] ?? '') === 'correctie') {
			$outcome = $this->filings->corrections->render($filing, (string)$uid);
			return new JSONResponse($outcome, (($outcome['status'] ?? '') === 'prepared' ? 200 : 409));
		}

		$outcome = $this->filings->messages->render($filing, (string)$uid);
		return new JSONResponse($outcome, (($outcome['status'] ?? '') === 'rendered' ? 200 : 409));
	}//end render()

	/**
	 * Correct a sent return: open a linked correction, 201, or 200 with the
	 * one already open; 409 when the filing is not a sent Dutch return.
	 *
	 * @param string|null $filingId The sent LoonaangifteFiling.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-001
	 */
	#[NoAdminRequired]
	public function correction(?string $filingId=null): JSONResponse {
		$uid = $this->caller->userSession->getUser()?->getUID();
		if ($this->caller->roles->isHr($uid) === false && $this->caller->roles->isPayroll($uid) === false) {
			return new JSONResponse(['error' => 'Alleen HR, de salarisadministratie en beheerders mogen een aangifte corrigeren.'], 403);
		}

		$filing = (trim((string)$filingId) === '' ? null : $this->readableFiling(trim((string)$filingId)));
		if ($filing === null) {
			return new JSONResponse(['error' => 'Aangifte niet gevonden.'], 404);
		}

		$outcome = $this->filings->corrections->open($filing, (string)$uid);
		$codes = ['opened' => 201, 'exists' => 200];
		return new JSONResponse($outcome, ($codes[$outcome['status']] ?? 409));
	}//end correction()

	/**
	 * The filing as the caller may read it, or null.
	 *
	 * @param string $filingId The filing.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readableFiling(string $filingId): ?array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			$filing = $this->caller->container->get('OCA\OpenRegister\Service\ObjectService')->find(
				id: $filingId,
				options: new ObjectFindOptions(register: $this->settings->getRegisterSlug(), schema: 'LoonaangifteFiling')
			);
		} catch (\Throwable $e) {
			$this->caller->logger->warning('LoonaangifteMessageController: a filing lookup failed and is answered as not found.', ['exceptionClass' => $e::class]);
			return null;
		}

		if ($filing === null) {
			return null;
		}

		$data = (is_object($filing) === true && method_exists($filing, 'jsonSerialize') === true) ? (array)$filing->jsonSerialize() : (array)$filing;
		if (isset($data['id']) === false) {
			$data['id'] = $filingId;
		}

		return $data;
	}//end readableFiling()

}//end class
