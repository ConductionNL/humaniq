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
use OCA\Humaniq\Service\DeploymentPlanService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves the per-school deployment view.
 *
 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-a-school-leader-shall-see-every-teacher-s-plan-for-their-school-req-edu-002
 */
class DeploymentController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request The request.
	 * @param RecordStoreInterface  $gateway Register reads.
	 * @param RbacObjectReader      $rbac    The unit and teachers, under the caller's RBAC.
	 * @param DeploymentPlanService $plans   The plan arithmetic and the school view.
	 */
	public function __construct(
		IRequest $request,
		private readonly RecordStoreInterface $gateway,
		private readonly RbacObjectReader $rbac,
		private readonly DeploymentPlanService $plans,
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
		$readable = [];
		foreach ($assignments as $assignment) {
			$employeeId = (string)($assignment['employeeId'] ?? '');
			if ($employeeId === '' || array_key_exists($employeeId, $readable) === true) {
				continue;
			}

			$readable[$employeeId] = $this->rbac->findOrNull(id: $employeeId, schema: 'Employee');
		}

		$readable = array_filter($readable, static fn (?array $employee): bool => $employee !== null);
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
}//end class
