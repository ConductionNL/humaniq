<?php

/**
 * Unit tests for DepartmentFiguresController: who may compare units, read
 * their own units, and read one unit.
 *
 * Drives the real AnalyticsAccess over a mocked AdministrationService and
 * DepartmentFiguresService (their real method names).
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
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\DepartmentFiguresController;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\AnalyticsAccess;
use OCA\Humaniq\Service\DepartmentFiguresService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for DepartmentFiguresController.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
 */
class DepartmentFiguresControllerTest extends TestCase {

	/**
	 * The comparison is for HR and accountants; a manager is refused it and
	 * reads their own units instead.
	 *
	 * @return void
	 */
	public function testTheComparisonIsForHrAndAManagerReadsTheirOwnUnits(): void {
		$figures = $this->createMock(DepartmentFiguresService::class);
		$figures->expects($this->never())->method('compare');
		$figures->expects($this->once())->method('managedUnits')->with('lead', 'ADM-001', 'quarter')
			->willReturn(['period' => 'quarter', 'units' => [['id' => 'burgerzaken']]]);

		$controller = $this->buildController('employee', $figures, 'lead', ['period' => 'quarter']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->units()->getStatus());
		$mine = $controller->myUnits();
		$this->assertSame(Http::STATUS_OK, $mine->getStatus());
		$this->assertSame('burgerzaken', $mine->getData()['units'][0]['id']);

		$hrFigures = $this->createMock(DepartmentFiguresService::class);
		$hrFigures->expects($this->once())->method('compare')->with('ADM-001', '2026-06', 'gemeente')
			->willReturn(['units' => []]);

		$hr = $this->buildController('hr', $hrFigures, 'hr-devries', ['period' => '2026-06', 'parentUnitId' => 'gemeente']);
		$this->assertSame(Http::STATUS_OK, $hr->units()->getStatus());
	}//end testTheComparisonIsForHrAndAManagerReadsTheirOwnUnits()

	/**
	 * "Another team stays closed": a manager reading a unit they do not lead
	 * is refused; the unit they lead is read under the small-unit rule.
	 *
	 * @return void
	 */
	public function testAManagerReadsOnlyTheUnitTheyLead(): void {
		$figures = $this->createMock(DepartmentFiguresService::class);
		$figures->method('managesUnit')->willReturnCallback(
			static fn (string $userId, string $administrationId, string $unitId): bool => ($unitId === 'burgerzaken')
		);
		$figures->method('minimumMembers')->willReturn(5);
		$figures->expects($this->once())->method('unitFigures')->with('ADM-001', 'burgerzaken', 'quarter', 5)
			->willReturn(['id' => 'burgerzaken', 'members' => 3, 'absenceRate' => null, 'suppressed' => 'unit-too-small']);

		$other = $this->buildController('employee', $figures, 'lead', ['orgUnitId' => 'belastingen']);
		$this->assertSame(Http::STATUS_FORBIDDEN, $other->unitFigures()->getStatus());

		$none = $this->buildController('employee', $figures, 'lead', []);
		$this->assertSame(Http::STATUS_FORBIDDEN, $none->unitFigures()->getStatus());

		$own = $this->buildController('employee', $figures, 'lead', ['orgUnitId' => 'burgerzaken', 'period' => 'quarter']);
		$response = $own->unitFigures();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('unit-too-small', $response->getData()['suppressed']);
	}//end testAManagerReadsOnlyTheUnitTheyLead()

	/**
	 * A unit outside the administration answers 404, and a bad period 400.
	 *
	 * @return void
	 */
	public function testAnUnknownUnitIs404AndABadPeriod400(): void {
		$figures = $this->createMock(DepartmentFiguresService::class);
		$figures->method('unitFigures')->willReturnCallback(
			static function (string $administrationId, string $unitId, string $period): ?array {
				if ($period === 'decade') {
					throw new \InvalidArgumentException('Invalid period');
				}

				return null;
			}
		);

		$missing = $this->buildController('hr', $figures, 'hr-devries', ['orgUnitId' => 'nope', 'period' => 'quarter']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->unitFigures()->getStatus());

		$bad = $this->buildController('hr', $figures, 'hr-devries', ['orgUnitId' => 'nope', 'period' => 'decade']);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $bad->unitFigures()->getStatus());
	}//end testAnUnknownUnitIs404AndABadPeriod400()

	/**
	 * Build the controller for a caller with the given role in ADM-001.
	 *
	 * @param string                   $role    The caller's AdministrationAccess role.
	 * @param DepartmentFiguresService $figures The (mocked) figures service.
	 * @param string                   $userId  The caller.
	 * @param array<string, string>    $params  Request params.
	 *
	 * @return DepartmentFiguresController
	 */
	private function buildController(string $role, DepartmentFiguresService $figures, string $userId, array $params): DepartmentFiguresController {
		$administrationService = $this->createMock(AdministrationService::class);
		$administrationService->method('getActiveAdministrationId')->willReturn('ADM-001');
		$administrationService->method('getActiveAdministrationRole')->willReturn($role);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null) => ($params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return new DepartmentFiguresController(
			$request,
			new AnalyticsAccess($administrationService, $figures),
			$figures,
			$userSession,
			$this->createMock(LoggerInterface::class)
		);
	}//end buildController()

}//end class
