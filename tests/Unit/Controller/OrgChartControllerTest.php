<?php

/**
 * Unit tests for the organisation chart endpoint.
 *
 * Real OrgChartService and OrgResolutionService; the register reads, the
 * RBAC reader, the administration service and the session are doubles.
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
 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\OrgChartController;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgChartService;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for OrgChartController.
 */
class OrgChartControllerTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param string|null $administration The caller's active administration.
	 *
	 * @return OrgChartController
	 */
	private function controller(?string $administration = 'ADM-001'): OrgChartController {
		$rows = [
			'OrgUnit'       => [
				['id' => 'u-root', 'name' => 'Organisatie', 'active' => true, 'managerId' => 'e-1', 'administrationId' => 'ADM-001'],
				['id' => 'u-team', 'name' => 'Backoffice', 'parentUnitId' => 'u-root', 'active' => true, 'administrationId' => 'ADM-001'],
				['id' => 'u-other', 'name' => 'Elders', 'active' => true, 'administrationId' => 'ADM-002'],
			],
			'OrgAssignment' => [
				['employeeId' => 'e-1', 'orgUnitId' => 'u-team', 'role' => 'Teamleider', 'startDate' => '2024-01-01', 'administrationId' => 'ADM-001'],
				['employeeId' => 'e-2', 'orgUnitId' => 'u-team', 'role' => 'Medewerker', 'startDate' => '2024-01-01', 'administrationId' => 'ADM-001'],
			],
			'Employee'      => [
				['id' => 'e-1', 'firstName' => 'Sam', 'lastName' => 'Jansen'],
				['id' => 'e-2', 'firstName' => 'Kim', 'lastName' => 'de Vries'],
			],
		];
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('loadAll')->willReturnCallback(static fn (string $schema): array => ($rows[$schema] ?? []));
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturnCallback(static fn (string $id): ?array => $id === 'e-1' ? ['id' => 'e-1'] : null);
		$administrations = $this->createMock(AdministrationService::class);
		$administrations->method('getActiveAdministrationId')->willReturn($administration);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new OrgChartController(
			$this->createMock(IRequest::class),
			$gateway,
			$rbac,
			new OrgChartService(new OrgResolutionService()),
			$administrations,
			$session
		);
	}//end controller()

	/**
	 * The chart covers the caller's administration only, with names and headcounts.
	 *
	 * @return void
	 */
	public function testTheChartCoversTheCallersAdministration(): void {
		$response = $this->controller()->chart(null, '2026-06-01');
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['Organisatie'], array_column($data['tree'], 'label'));
		self::assertSame('Sam Jansen', $data['tree'][0]['managerName']);
		self::assertSame(2, $data['tree'][0]['children'][0]['headcount']);
		self::assertSame('2026-06-01', $data['date']);
	}//end testTheChartCoversTheCallersAdministration()

	/**
	 * People are listed only for employees the caller may read.
	 *
	 * @return void
	 */
	public function testWithPeopleListsOnlyReadableEmployees(): void {
		$data = $this->controller()->chart(null, '2026-06-01', 'true')->getData();

		self::assertSame([['id' => 'e-1', 'name' => 'Sam Jansen', 'role' => 'Teamleider']], $data['tree'][0]['children'][0]['people']);
	}//end testWithPeopleListsOnlyReadableEmployees()

	/**
	 * A bad date is a 400; a caller with no administration is refused.
	 *
	 * @return void
	 */
	public function testABadDateAndNoAdministrationAreRefused(): void {
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->chart(null, 'soon')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(null)->chart()->getStatus());
	}//end testABadDateAndNoAdministrationAreRefused()
}//end class
