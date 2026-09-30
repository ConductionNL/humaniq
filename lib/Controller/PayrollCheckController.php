<?php

/**
 * Payroll Check Controller
 *
 * `POST /api/payroll/check`: run the check of one payroll run on demand
 * (payroll-run-checks D2). Only HR, payroll staff or an administrator may;
 * the run must resolve through OpenRegister under the caller's RBAC first.
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
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRC-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\PayrollRunCheckService;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The on-demand run check.
 *
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRC-001
 */
class PayrollCheckController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request     The request.
	 * @param ContainerInterface     $container   Resolves OpenRegister's ObjectService.
	 * @param SettingsService        $settings    The register slug.
	 * @param PayrollRunCheckService $check       The run check.
	 * @param IUserSession           $userSession The caller.
	 * @param HumaniqRoles           $roles       Whether the caller is HR or payroll.
	 * @param LoggerInterface        $logger      The logger.
	 *
	 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRC-001
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly PayrollRunCheckService $check,
		private readonly IUserSession $userSession,
		private readonly HumaniqRoles $roles,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Check one run and answer its counts. 403 outside HR and payroll, 400 on
	 * a blank id, 404 when the run does not resolve for the caller.
	 *
	 * @param string|null $runId The PayrollRun id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRC-001
	 */
	#[NoAdminRequired]
	public function check(?string $runId = null): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($this->roles->isHr($uid) === false && $this->roles->isPayroll($uid) === false) {
			return new JSONResponse(['error' => 'Alleen HR, de salarisadministratie en beheerders mogen een loonrun controleren.'], Http::STATUS_FORBIDDEN);
		}

		$runId = trim((string)$runId);
		if ($runId === '') {
			return new JSONResponse(['error' => 'runId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$run = $this->container->get('OCA\OpenRegister\Service\ObjectService')->find(id: $runId, register: $this->settings->getRegisterSlug(), schema: 'PayrollRun');
		} catch (\Throwable $e) {
			$this->logger->info('PayrollCheckController: run ' . $runId . ' could not be read: ' . $e->getMessage());
			$run = null;
		}

		if ($run === null || $this->settings->isOpenRegisterAvailable() === false) {
			return new JSONResponse(['error' => 'Loonrun niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->check->check($runId));
	}//end check()

}//end class
