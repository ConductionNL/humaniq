<?php

/**
 * Third-Party Report Controller
 *
 * `POST /api/third-party/reports/assemble` and
 * `POST /api/third-party/payees/{payeeId}/statement` (filings-ib47). Only HR,
 * payroll staff or an administrator may; the administration or payee must
 * resolve through OpenRegister under the caller's RBAC first. Everything
 * else about payees, payments and reports is the object API (ADR-022).
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
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\ThirdPartyReportService;
use OCA\Humaniq\Service\ThirdPartyStatementService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Assemble a third-party payments report; generate a payee's statement.
 *
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */
class ThirdPartyReportController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request     The request.
	 * @param ContainerInterface         $container   Resolves OpenRegister's ObjectService.
	 * @param SettingsService            $settings    The register slug.
	 * @param ThirdPartyReportService    $reports     Assembles the report.
	 * @param ThirdPartyStatementService $statements  Generates the statement.
	 * @param IUserSession               $userSession The caller.
	 * @param HumaniqRoles               $roles       Whether the caller is HR or payroll.
	 * @param LoggerInterface            $logger      The logger.
	 *
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly ThirdPartyReportService $reports,
		private readonly ThirdPartyStatementService $statements,
		private readonly IUserSession $userSession,
		private readonly HumaniqRoles $roles,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Assemble the report of one administration and year. 403 outside HR
	 * and payroll, 400 on a blank administration or a year that is not a
	 * year, 404 when the administration does not resolve for the caller,
	 * 409 when the service refuses (a report already ready or sent, a year
	 * without payments).
	 *
	 * @param string|null $administrationId The administration (administrationId, e.g. ADM-001).
	 * @param string|null $year             The year, YYYY.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
	 */
	#[NoAdminRequired]
	public function assemble(?string $administrationId = null, ?string $year = null): JSONResponse {
		$uid = $this->allowedCaller();
		if ($uid === null) {
			return $this->forbidden();
		}

		$administrationId = trim((string)$administrationId);
		$year = $this->year($year);
		if ($administrationId === '' || $year === null) {
			return new JSONResponse(['error' => 'administrationId en year (JJJJ) zijn verplicht.'], 400);
		}

		if ($this->administrationReadable($administrationId) === false) {
			return new JSONResponse(['error' => 'Administratie niet gevonden.'], 404);
		}

		$outcome = $this->reports->assemble($administrationId, $year, $uid);
		if (($outcome['status'] ?? '') !== 'assembled') {
			return new JSONResponse($outcome, 409);
		}

		return new JSONResponse($outcome);
	}//end assemble()

	/**
	 * Generate the yearly statement of every payee a report covers. 403
	 * outside HR and payroll, 400 on a blank report id, 404 when the report
	 * does not resolve for the caller, 409 when nothing was generated.
	 *
	 * @param string|null $reportId The ThirdPartyReport id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-003
	 */
	#[NoAdminRequired]
	public function statements(?string $reportId = null): JSONResponse {
		$uid = $this->allowedCaller();
		if ($uid === null) {
			return $this->forbidden();
		}

		$reportId = trim((string)$reportId);
		if ($reportId === '') {
			return new JSONResponse(['error' => 'reportId is verplicht.'], 400);
		}

		try {
			$report = $this->objects()->find(id: $reportId, register: $this->settings->getRegisterSlug(), schema: 'ThirdPartyReport');
		} catch (\Throwable $e) {
			$this->logger->info('ThirdPartyReportController: report ' . $reportId . ' could not be read: ' . $e->getMessage());
			$report = null;
		}

		if ($report === null || $this->settings->isOpenRegisterAvailable() === false) {
			return new JSONResponse(['error' => 'Overzicht niet gevonden.'], 404);
		}

		$report = (is_object($report) === true && method_exists($report, 'jsonSerialize') === true) ? (array)$report->jsonSerialize() : (array)$report;
		$outcome = $this->statements->generateForReport($report, $uid);
		if ($outcome['generated'] === 0) {
			return new JSONResponse($outcome, 409);
		}

		return new JSONResponse($outcome);
	}//end statements()

	/**
	 * The year as an integer between 2000 and 2100, or null.
	 *
	 * @param string|null $year The raw year.
	 *
	 * @return int|null
	 */
	private function year(?string $year): ?int {
		$year = trim((string)$year);
		if (preg_match('/^\d{4}$/', $year) !== 1 || (int)$year < 2000 || (int)$year > 2100) {
			return null;
		}

		return (int)$year;
	}//end year()

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
		return new JSONResponse(['error' => 'Alleen HR, de salarisadministratie en beheerders mogen betalingen aan derden opgeven.'], 403);
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
			$this->logger->info('ThirdPartyReportController: administration ' . $administrationId . ' could not be read: ' . $e->getMessage());
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
