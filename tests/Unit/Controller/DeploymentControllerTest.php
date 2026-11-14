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
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\DeploymentPlanService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\IptoExportService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DeploymentController.
 *
 * @covers \OCA\Humaniq\Controller\DeploymentController
 * @uses   \OCA\Humaniq\Service\DeploymentPlanService
 * @uses   \OCA\Humaniq\Service\IptoExportService
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
	 * @param bool        $hr             Whether the caller is HR or an administrator.
	 * @param string|null $brin           The administration's BRIN number.
	 * @param string|null $administration The caller's active administration.
	 *
	 * @return DeploymentController
	 */
	private function controller(bool $hr = true, ?string $brin = '12AB', ?string $administration = 'adm-1'): DeploymentController {
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
				['id' => 'p-9', 'employeeId' => 'e-9', 'orgUnitId' => 'u-elsewhere', 'schoolYear' => '2026-2027', 'status' => 'vastgesteld', 'lines' => [['category' => 'lesgevend', 'subject' => 'Frans', 'educationLevel' => 'vwo', 'hoursPerWeek' => 20, 'weeks' => 40]]],
			],
			'OrgUnit'        => [['id' => 'u-school', 'administrationId' => 'adm-1']],
		];
		$this->calls = [];
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('loadAll')->willReturnCallback(static fn (string $schema): array => ($rows[$schema] ?? []));
		$gateway->method('findObjectData')->willReturnCallback(
			static fn (string $uuid, string $schema): array => ['id' => $uuid, 'firstName' => strtoupper($uuid), 'lastName' => 'Docent']
		);
		$gateway->method('findFiltered')->willReturnCallback(
			function (string $schema, array $filters, bool $asCaller = false) use ($rows, $brin): array {
				$this->calls[$schema] = ['filters' => $filters, 'asCaller' => $asCaller];
				if ($schema === 'hrAdministration') {
					return (($filters['administrationId'] ?? null) === 'adm-1' ? [['name' => 'De Klimop', 'administrationId' => 'adm-1', 'brinNummer' => $brin]] : []);
				}

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

		$roles = $this->createMock(HumaniqRoles::class);
		$roles->method('isHr')->willReturnCallback(static fn (?string $uid): bool => $hr && $uid === 'hr-user');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr-user');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$administrations = $this->createMock(AdministrationService::class);
		$administrations->method('getActiveAdministrationId')->willReturnCallback(static fn (string $uid): ?string => $uid === 'hr-user' ? $administration : null);

		return new DeploymentController(
			$this->createMock(IRequest::class),
			$gateway,
			$rbac,
			new DeploymentPlanService(static fn (string $caoId): ?float => null),
			new IptoExportService(),
			$roles,
			$session,
			$administrations
		);
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
		$this->assertSame('p-1', $overloaded['id']);
		$this->assertSame(-120.0, $room['differenceHours']);
		$this->assertFalse($room['overload']);
		$this->assertSame('Kim de Vries', $room['name']);
		$this->assertFalse($none['hasPlan']);
		$this->assertNull($none['planId']);
		$this->assertNull($none['id']);
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

	/**
	 * Only HR or an administrator may export, and nothing is read for anyone else.
	 *
	 * @return void
	 */
	public function testIptoExportRefusesAnyoneButHr(): void {
		$response = $this->controller(hr: false)->iptoExport('2026-W46');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->calls);
	}//end testIptoExportRefusesAnyoneButHr()

	/**
	 * A malformed or missing reference week answers 400.
	 *
	 * @return void
	 */
	public function testIptoExportRefusesAMalformedWeek(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->iptoExport('2026-11-10')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->iptoExport(null)->getStatus());
	}//end testIptoExportRefusesAMalformedWeek()

	/**
	 * An administration without a BRIN number cannot be exported; no active administration is refused.
	 *
	 * @return void
	 */
	public function testIptoExportNeedsABrinNumberAndAnAdministration(): void {
		$response = $this->controller(brin: null)->iptoExport('2026-W46');
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['message' => 'The administration has no BRIN number'], $response->getData());

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(administration: null)->iptoExport('2026-W46')->getStatus());
	}//end testIptoExportNeedsABrinNumberAndAnAdministration()

	/**
	 * Only confirmed plans of the administration's units are exported; the draft plans are listed as not confirmed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-a-draft-plan-is-left-out
	 */
	public function testIptoExportsOnlyConfirmedPlansOfTheAdministration(): void {
		$response = $this->controller()->iptoExport('2026-W46');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('12AB', $data['brinNummer']);
		$this->assertSame('adm-1', $data['administrationId']);
		$this->assertSame(['filters' => ['administrationId' => 'adm-1'], 'asCaller' => false], $this->calls['OrgUnit']);
		$this->assertSame(['filters' => ['administrationId' => 'adm-1'], 'asCaller' => false], $this->calls['hrAdministration']);
		$this->assertSame([['employeeId' => 'e-1', 'employeeNumber' => null, 'name' => 'E-1 Docent', 'subject' => '', 'educationLevel' => '', 'hoursPerWeek' => 25.0]], $data['rows']);
		$this->assertSame(['e-2', 'e-4'], array_column($data['notConfirmed'], 'employeeId'));
	}//end testIptoExportsOnlyConfirmedPlansOfTheAdministration()
}//end class
