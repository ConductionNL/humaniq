<?php

/**
 * Humaniq OrgChartController
 *
 * GET /api/org/chart: the organisation chart of the caller's active
 * administration on a date (people-org-chart-view D1, D3). Units and managers
 * are organisational facts every employee may see; people are listed only
 * for employees the caller may read, so `withPeople` never widens access.
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
 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgChartService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves the organisation chart.
 *
 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
 */
class OrgChartController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request         The request.
	 * @param HoursRegisterGateway  $gateway         Register reads.
	 * @param RbacObjectReader      $rbac            Which employees the caller may read.
	 * @param OrgChartService       $charts          The chart composition.
	 * @param AdministrationService $administrations The caller's active administration.
	 * @param IUserSession          $session         The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly HoursRegisterGateway $gateway,
		private readonly RbacObjectReader $rbac,
		private readonly OrgChartService $charts,
		private readonly AdministrationService $administrations,
		private readonly IUserSession $session,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/org/chart?rootId&date&withPeople.
	 *
	 * @param string|null $rootId     Only this unit's subtree.
	 * @param string|null $date       The day (Y-m-d, default today).
	 * @param string|null $withPeople `true` or `1` to list the readable people per unit.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
	 */
	#[NoAdminRequired]
	public function chart(?string $rootId = null, ?string $date = null, ?string $withPeople = null): JSONResponse {
		$uid = $this->session->getUser()?->getUID();
		$administrationId = null;
		if ($uid !== null && $uid !== '') {
			$administrationId = $this->administrations->getActiveAdministrationId($uid);
		}

		if ($administrationId === null) {
			return new JSONResponse(['message' => 'No active administration'], Http::STATUS_FORBIDDEN);
		}

		$day = ($date ?? date('Y-m-d'));
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
			return new JSONResponse(['message' => 'date must be YYYY-MM-DD'], Http::STATUS_BAD_REQUEST);
		}

		$units = $this->inAdministration(rows: $this->gateway->loadAll('OrgUnit'), administrationId: $administrationId);
		$assignments = $this->inAdministration(rows: $this->gateway->loadAll('OrgAssignment'), administrationId: $administrationId);
		$employees = [];
		foreach ($this->gateway->loadAll('Employee') as $employee) {
			$employees[(string)($employee['id'] ?? '')] = $employee;
		}

		$readable = null;
		if (in_array($withPeople, ['true', '1'], true) === true) {
			$readable = $this->readableEmployees(assignments: $assignments);
		}

		$chart = $this->charts->chart(units: $units, assignments: $assignments, employeesById: $employees, date: $day, rootId: $rootId, readableEmployeeIds: $readable);

		return new JSONResponse(array_merge(['date' => $day], $chart));
	}//end chart()

	/**
	 * Rows of the administration, and rows that carry none.
	 *
	 * @param array<int, array<string, mixed>> $rows             The rows.
	 * @param string                           $administrationId The administration.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function inAdministration(array $rows, string $administrationId): array {
		return array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => in_array((string)($row['administrationId'] ?? ''), ['', $administrationId], true)
			)
		);
	}//end inAdministration()

	/**
	 * The placed employees the caller may read.
	 *
	 * @param array<int, array<string, mixed>> $assignments The placements.
	 *
	 * @return array<int, string>
	 */
	private function readableEmployees(array $assignments): array {
		$ids = array_unique(array_map(static fn (array $row): string => (string)($row['employeeId'] ?? ''), $assignments));

		return array_values(
			array_filter(
				$ids,
				fn (string $id): bool => $id !== '' && $this->rbac->findOrNull(id: $id, schema: 'Employee') !== null
			)
		);
	}//end readableEmployees()
}//end class
