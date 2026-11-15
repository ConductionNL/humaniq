<?php

/**
 * Humaniq DeploymentController
 *
 * GET /api/deployment/org-units/{id}: the school view of the deployment plans
 * (education-staff-deployment-and-count D3). For one org unit and school year
 * it lists every teacher placed there that the caller may read, with their
 * plan's planned, norm and difference, and the placed teachers without a plan.
 * The unit itself is read under the caller's RBAC: an unreadable or unknown
 * unit answers 404, without saying which.
 *
 * POST /api/deployment/ipto-export: the IPTO staff count rows of the caller's
 * active administration for a reference week (D4), for HR and administrators
 * only, from the confirmed plans; draft plans are listed as not confirmed.
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
 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-a-school-leader-shall-see-every-teacher-s-plan-for-their-school-req-edu-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use DateTimeImmutable;
use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\DeploymentPlanService;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\IptoExportService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves the per-school deployment view.
 *
 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-a-school-leader-shall-see-every-teacher-s-plan-for-their-school-req-edu-002
 */
class DeploymentController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request         The request.
	 * @param RecordStoreInterface  $gateway         Register reads.
	 * @param RbacObjectReader      $rbac            The unit and teachers, under the caller's RBAC.
	 * @param DeploymentPlanService $plans           The plan arithmetic and the school view.
	 * @param HumaniqRoles          $roles           The HR check.
	 * @param IUserSession          $session         The caller.
	 * @param AdministrationService $administrations The caller's active administration.
	 */
	public function __construct(
		IRequest $request,
		private readonly RecordStoreInterface $gateway,
		private readonly RbacObjectReader $rbac,
		private readonly DeploymentPlanService $plans,
		private readonly HumaniqRoles $roles,
		private readonly IUserSession $session,
		private readonly AdministrationService $administrations,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/deployment/org-units/{id}?schoolYear.
	 *
	 * @param string      $id         The org unit.
	 * @param string|null $schoolYear The school year (`YYYY-YYYY`, default the current one).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-a-school-leader-shall-see-every-teacher-s-plan-for-their-school-req-edu-002
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-room-in-the-team
	 */
	#[NoAdminRequired]
	public function orgUnit(string $id, ?string $schoolYear = null): JSONResponse {
		$unit = $this->rbac->findOrNull(id: $id, schema: 'OrgUnit');
		if ($unit === null) {
			return new JSONResponse(['message' => 'Org unit not found'], Http::STATUS_NOT_FOUND);
		}

		$year = ($schoolYear ?? DeploymentPlanService::schoolYearOf(day: new DateTimeImmutable('today')));
		if (DeploymentPlanService::schoolYearBounds(schoolYear: $year) === null) {
			return new JSONResponse(['message' => 'schoolYear must be YYYY-YYYY'], Http::STATUS_BAD_REQUEST);
		}

		$assignments = $this->gateway->findFiltered(schema: 'OrgAssignment', filters: ['orgUnitId' => $id]);
		$readable = $this->readableEmployees(assignments: $assignments);
		$plans = $this->gateway->findFiltered(
			schema: 'DeploymentPlan',
			filters: ['orgUnitId' => $id, 'schoolYear' => $year],
			asCaller: true
		);

		return new JSONResponse(
			[
				'orgUnitId'  => $id,
				'name'       => ($unit['name'] ?? null),
				'schoolYear' => $year,
				'teachers'   => $this->plans->schoolView(assignments: $assignments, plans: $plans, readable: $readable, schoolYear: $year),
			]
		);
	}//end orgUnit()

	/**
	 * POST /api/deployment/ipto-export {referenceWeek}.
	 *
	 * @param string|null $referenceWeek The reference week, `YYYY-Www`.
	 *
	 * @return JSONResponse The export; 403 for anyone but HR or an administrator, 400, or 409 without a BRIN number.
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-the-ipto-staff-count-file-shall-be-produced-from-the-confirmed-plans-req-edu-003
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-a-draft-plan-is-left-out
	 */
	#[NoAdminRequired]
	public function iptoExport(?string $referenceWeek = null): JSONResponse {
		$uid = $this->session->getUser()?->getUID();
		if ($this->roles->isHr($uid) === false) {
			return new JSONResponse(['message' => 'Only HR or an administrator can export the IPTO'], Http::STATUS_FORBIDDEN);
		}

		if ($referenceWeek === null || IptoExportService::weekStart(referenceWeek: $referenceWeek) === null) {
			return new JSONResponse(['message' => 'referenceWeek must be YYYY-Www'], Http::STATUS_BAD_REQUEST);
		}

		$administration = $this->activeAdministration(uid: (string)$uid);
		if ($administration === null) {
			return new JSONResponse(['message' => 'No active administration'], Http::STATUS_FORBIDDEN);
		}

		if (trim((string)($administration['brinNummer'] ?? '')) === '') {
			return new JSONResponse(['message' => 'The administration has no BRIN number'], Http::STATUS_CONFLICT);
		}

		$plans = $this->plansOf(administrationId: (string)$administration['id']);

		return new JSONResponse((new IptoExportService())->export(administration: $administration, plans: $plans, employees: $this->employeesOf(plans: $plans), referenceWeek: $referenceWeek));
	}//end iptoExport()

	/**
	 * The employees of a unit's assignments the caller may read, by employee id.
	 *
	 * @param array<int, array<string, mixed>> $assignments The unit's OrgAssignment rows.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-a-school-leader-shall-see-every-teacher-s-plan-for-their-school-req-edu-002
	 */
	private function readableEmployees(array $assignments): array {
		$readable = [];
		foreach ($assignments as $assignment) {
			$employeeId = (string)($assignment['employeeId'] ?? '');
			if ($employeeId === '' || array_key_exists($employeeId, $readable) === true) {
				continue;
			}

			$readable[$employeeId] = $this->rbac->findOrNull(id: $employeeId, schema: 'Employee');
		}

		return array_filter($readable, static fn (?array $employee): bool => $employee !== null);
	}//end readableEmployees()

	/**
	 * The caller's active hrAdministration with its `id`, or null when there is none.
	 *
	 * @param string $uid The caller.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-the-ipto-staff-count-file-shall-be-produced-from-the-confirmed-plans-req-edu-003
	 */
	private function activeAdministration(string $uid): ?array {
		$administrationId = $this->administrations->getActiveAdministrationId($uid);
		if ($administrationId === null) {
			return null;
		}

		$administration = ($this->gateway->findFiltered(schema: 'hrAdministration', filters: ['administrationId' => $administrationId])[0] ?? null);
		if ($administration === null) {
			return null;
		}

		$administration['id'] = $administrationId;

		return $administration;
	}//end activeAdministration()

	/**
	 * The DeploymentPlan rows of the org units of one administration.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-the-ipto-staff-count-file-shall-be-produced-from-the-confirmed-plans-req-edu-003
	 */
	private function plansOf(string $administrationId): array {
		$units = [];
		foreach ($this->gateway->findFiltered(schema: 'OrgUnit', filters: ['administrationId' => $administrationId]) as $unit) {
			$units[(string)($unit['id'] ?? '')] = true;
		}

		return array_values(
			array_filter(
				$this->gateway->loadAll('DeploymentPlan'),
				static fn (array $plan): bool => isset($units[(string)($plan['orgUnitId'] ?? '')]) === true
			)
		);
	}//end plansOf()

	/**
	 * The teachers of the plans, by employee id.
	 *
	 * @param array<int, array<string, mixed>> $plans The DeploymentPlan rows.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-the-november-export
	 */
	private function employeesOf(array $plans): array {
		$employees = [];
		foreach ($plans as $plan) {
			$employeeId = (string)($plan['employeeId'] ?? '');
			if ($employeeId !== '' && isset($employees[$employeeId]) === false) {
				$employees[$employeeId] = ($this->gateway->findObjectData(uuid: $employeeId, schema: 'Employee') ?? []);
			}
		}

		return $employees;
	}//end employeesOf()
}//end class
