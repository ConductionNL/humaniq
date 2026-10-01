<?php

/**
 * Payroll Handoff Controller
 *
 * `POST /api/payroll/handoffs/compile` and `POST /api/payroll/handoffs/check-intake`
 * (payroll-external-bureau-handoff D5). Only HR, payroll staff or an
 * administrator may; the administration or handoff must resolve through
 * OpenRegister under the caller's RBAC first. Everything else about a
 * handoff is the object API (ADR-022).
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
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\PayrollHandoffService;
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
 * Compile a bureau handoff and check what came back.
 *
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
 */
class PayrollHandoffController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request     The request.
	 * @param ContainerInterface    $container   Resolves OpenRegister's ObjectService.
	 * @param SettingsService       $settings    The register slug.
	 * @param PayrollHandoffService $handoffs    Compile and intake.
	 * @param IUserSession          $userSession The caller.
	 * @param HumaniqRoles          $roles       Whether the caller is HR or payroll.
	 * @param LoggerInterface       $logger      The logger.
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly PayrollHandoffService $handoffs,
		private readonly IUserSession $userSession,
		private readonly HumaniqRoles $roles,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Compile the handoff of one administration and period. 403 outside HR
	 * and payroll, 400 on a blank administration or a period that is not
	 * YYYY-MM, 404 when the administration does not resolve for the caller,
	 * 409 when the service refuses (an engine administration, a handoff
	 * that already left).
	 *
	 * @param string|null $administrationId The administration (administrationId, e.g. ADM-006).
	 * @param string|null $period           The wage period, YYYY-MM.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
	 */
	#[NoAdminRequired]
	public function compile(?string $administrationId = null, ?string $period = null): JSONResponse {
		$uid = $this->allowedCaller();
		if ($uid === null) {
			return $this->forbidden();
		}

		$administrationId = trim((string)$administrationId);
		$period = trim((string)$period);
		if ($administrationId === '' || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
			return new JSONResponse(['error' => 'administrationId en period (JJJJ-MM) zijn verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationReadable($administrationId) === false) {
			return new JSONResponse(['error' => 'Administratie niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$outcome = $this->handoffs->compile($administrationId, $period, $uid);
		if (($outcome['status'] ?? '') !== 'compiled') {
			return new JSONResponse($outcome, Http::STATUS_CONFLICT);
		}

		return new JSONResponse($outcome);
	}//end compile()

	/**
	 * Check the bureau's returned payslips of one handoff. 403 outside HR
	 * and payroll, 400 on a blank id, 404 when the handoff does not resolve
	 * for the caller.
	 *
	 * @param string|null $handoffId The PayrollHandoff id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-004
	 */
	#[NoAdminRequired]
	public function checkIntake(?string $handoffId = null): JSONResponse {
		if ($this->allowedCaller() === null) {
			return $this->forbidden();
		}

		$handoffId = trim((string)$handoffId);
		if ($handoffId === '') {
			return new JSONResponse(['error' => 'handoffId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$handoff = $this->objects()->find(id: $handoffId, register: $this->settings->getRegisterSlug(), schema: 'PayrollHandoff');
		} catch (\Throwable $e) {
			$this->logger->info('PayrollHandoffController: handoff ' . $handoffId . ' could not be read: ' . $e->getMessage());
			$handoff = null;
		}

		if ($handoff === null || $this->settings->isOpenRegisterAvailable() === false) {
			return new JSONResponse(['error' => 'Overdracht niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->handoffs->checkIntake($handoffId));
	}//end checkIntake()

	/**
	 * The caller's uid when they hold HR or payroll (or are an
	 * administrator), else null.
	 *
	 * @return string|null
	 */
	private function allowedCaller(): ?string {
		$uid = $this->userSession->getUser()?->getUID();
		if ($this->roles->isHr($uid) === false && $this->roles->isPayroll($uid) === false) {
			return null;
		}

		return (string)$uid;
	}//end allowedCaller()

	/**
	 * The 403 answer.
	 *
	 * @return JSONResponse
	 */
	private function forbidden(): JSONResponse {
		return new JSONResponse(['error' => 'Alleen HR, de salarisadministratie en beheerders mogen een loonoverdracht samenstellen of controleren.'], Http::STATUS_FORBIDDEN);
	}//end forbidden()

	/**
	 * Whether the administration resolves under the caller's RBAC.
	 *
	 * @param string $administrationId The administrationId.
	 *
	 * @return bool
	 */
	private function administrationReadable(string $administrationId): bool {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return false;
		}

		try {
			$rows = $this->objects()
				->setRegister($this->settings->getRegisterSlug())
				->setSchema('hrAdministration')
				->findAll(['filters' => ['administrationId' => $administrationId], 'limit' => 1]);
		} catch (\Throwable $e) {
			$this->logger->info('PayrollHandoffController: administration ' . $administrationId . ' could not be read: ' . $e->getMessage());
			return false;
		}

		return (is_array($rows) === true && $rows !== []);
	}//end administrationReadable()

	/**
	 * OpenRegister's ObjectService.
	 *
	 * @return mixed
	 */
	private function objects(): mixed {
		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objects()

}//end class
