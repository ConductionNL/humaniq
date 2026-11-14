<?php

/**
 * Unit tests for the per-school deployment endpoint.
 *
 * Real DeploymentPlanService; the register reads and the RBAC reader are doubles.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Controller
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

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\DeploymentController;
use OCA\Humaniq\Service\DeploymentPlanService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DeploymentController.
 *
 * @covers \OCA\Humaniq\Controller\DeploymentController
 * @uses   \OCA\Humaniq\Service\DeploymentPlanService
 */
class DeploymentControllerTest extends TestCase {

	/**
	 * The filters each findFiltered call was made with, by schema.
	 *
	 * @var array<string, array{filters: array<string, mixed>, asCaller: bool}>
	 */
	private array $calls = [];

	/**
	 * Build the controller over one school with three placed teachers.
	 *
	 * e-1 is overloaded, e-2 has room, e-3 has no plan and e-4 is placed but
	 * the caller may not read them. The unit `u-hidden` is not readable.
	 *
	 * @return DeploymentController
	 */
	private function controller(): DeploymentController {
		$rows = [
			'OrgAssignment'  => [
				['employeeId' => 'e-1', 'orgUnitId' => 'u-school', 'startDate' => '2020-01-01'],
				['employeeId' => 'e-2', 'orgUnitId' => 'u-school', 'startDate' => '2020-01-01'],
				['employeeId' => 'e-3', 'orgUnitId' => 'u-school', 'startDate' => '2026-09-01'],
				['employeeId' => 'e-4', 'orgUnitId' => 'u-school', 'startDate' => '2020-01-01'],
			],
			'DeploymentPlan' => [
				['id' => 'p-2', 'employeeId' => 'e-2', 'orgUnitId' => 'u-school', 'schoolYear' => '2026-2027', 'status' => 'concept', 'normHours' => 995.4, 'normSource' => 'handmatig', 'lines' => [['category' => 'lesgevend', 'hoursPerWeek' => 15, 'weeks' => 40], ['category' => 'schooltaak', 'hours' => 275.4]]],
				['id' => 'p-1', 'employeeId' => 'e-1', 'orgUnitId' => 'u-school', 'schoolYear' => '2026-2027', 'status' => 'vastgesteld', 'normHours' => 1659, 'normSource' => 'handmatig', 'lines' => [['category' => 'lesgevend', 'hoursPerWeek' => 25, 'weeks' => 40], ['category' => 'lesgebonden', 'hours' => 700]]],
				['id' => 'p-4', 'employeeId' => 'e-4', 'orgUnitId' => 'u-school', 'schoolYear' => '2026-2027', 'status' => 'concept', 'normHours' => 100, 'lines' => [['category' => 'schooltaak', 'hours' => 900]]],
			],
		];
		$this->calls = [];
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('findFiltered')->willReturnCallback(
			function (string $schema, array $filters, bool $asCaller = false) use ($rows): array {
				$this->calls[$schema] = ['filters' => $filters, 'asCaller' => $asCaller];
				return ($rows[$schema] ?? []);
			}
		);
		$employees = [
			'e-1' => ['id' => 'e-1', 'firstName' => 'Sam', 'lastName' => 'Jansen'],
			'e-2' => ['id' => 'e-2', 'firstName' => 'Kim', 'lastName' => 'de Vries'],
			'e-3' => ['id' => 'e-3', 'firstName' => 'Ali', 'lastName' => 'Bakker'],
		];
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturnCallback(
			static function (string $id, string $schema) use ($employees): ?array {
				if ($schema === 'OrgUnit') {
					return ($id === 'u-school' ? ['id' => 'u-school', 'name' => 'De Klimop'] : null);
				}

				return ($employees[$id] ?? null);
			}
		);

		return new DeploymentController($this->createMock(IRequest::class), $gateway, $rbac, new DeploymentPlanService(static fn (string $caoId): ?float => null));
	}//end controller()

	/**
	 * An unreadable or unknown unit answers 404 and reads nothing else.
	 *
	 * @return void
	 */
	public function testAnUnreadableUnitAnswers404(): void {
		$response = $this->controller()->orgUnit('u-hidden', '2026-2027');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Org unit not found'], $response->getData());
		$this->assertSame([], $this->calls);
	}//end testAnUnreadableUnitAnswers404()

	/**
	 * A malformed school year answers 400.
	 *
	 * @return void
	 */
	public function testAMalformedSchoolYearAnswers400(): void {
		$response = $this->controller()->orgUnit('u-school', '2026-2028');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAMalformedSchoolYearAnswers400()

	/**
	 * The overloaded teacher comes first, the teacher with room next, the teacher without a plan last, and the unreadable teacher not at all.
	 *
	 * @return void
	 */
	public function testListsReadableTeachersOverloadFirstAndThoseWithoutAPlan(): void {
		$response = $this->controller()->orgUnit('u-school', '2026-2027');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('De Klimop', $data['name']);
		$this->assertSame('2026-2027', $data['schoolYear']);
		$this->assertSame(['e-1', 'e-2', 'e-3'], array_column($data['teachers'], 'employeeId'));

		[$overloaded, $room, $none] = $data['teachers'];
		$this->assertSame(1700.0, $overloaded['plannedHours']);
		$this->assertSame(1659.0, $overloaded['normHours']);
		$this->assertSame(41.0, $overloaded['differenceHours']);
		$this->assertTrue($overloaded['overload']);
		$this->assertSame('vastgesteld', $overloaded['status']);
		$this->assertSame(-120.0, $room['differenceHours']);
		$this->assertFalse($room['overload']);
		$this->assertSame('Kim de Vries', $room['name']);
		$this->assertFalse($none['hasPlan']);
		$this->assertNull($none['planId']);
		$this->assertSame('Ali Bakker', $none['name']);
	}//end testListsReadableTeachersOverloadFirstAndThoseWithoutAPlan()

	/**
	 * The placements are read for the unit and the plans for the unit and year, under the caller's access.
	 *
	 * @return void
	 */
	public function testReadsPlansForTheUnitAndYearAsTheCaller(): void {
		$this->controller()->orgUnit('u-school', '2026-2027');

		$this->assertSame(['filters' => ['orgUnitId' => 'u-school'], 'asCaller' => false], $this->calls['OrgAssignment']);
		$this->assertSame(['filters' => ['orgUnitId' => 'u-school', 'schoolYear' => '2026-2027'], 'asCaller' => true], $this->calls['DeploymentPlan']);
	}//end testReadsPlansForTheUnitAndYearAsTheCaller()
}//end class
