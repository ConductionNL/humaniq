<?php

/**
 * TravelControllerTest
 *
 * The two travel endpoints of expenses-travel-calculation: the route lookup
 * on an arrangement (404 unreadable, 403 not yours and not HR, 409 without a
 * route planner, the distance saved with its source) and the WPM compile
 * (HR or an administrator only).
 *
 * @category Tests
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
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\TravelController;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\RbacObjectReader;
use OCA\Humaniq\Service\RouteDistanceService;
use OCA\Humaniq\Service\RouteDistanceUnavailableException;
use OCA\Humaniq\Service\WpmReportService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Who may look up a distance or compile a report, and what they get.
 */
class TravelControllerTest extends TestCase {

	/**
	 * The route lookup double.
	 *
	 * @var RouteDistanceService&MockObject
	 */
	private RouteDistanceService&MockObject $routes;

	/**
	 * The register gateway double.
	 *
	 * @var HoursRegisterGateway&MockObject
	 */
	private HoursRegisterGateway&MockObject $gateway;

	/**
	 * The report service double.
	 *
	 * @var WpmReportService&MockObject
	 */
	private WpmReportService&MockObject $reports;

	/**
	 * The controller for a caller.
	 *
	 * @param array<string, mixed>|null $arrangement What the caller can read.
	 * @param bool                      $admin       Whether the caller is an administrator.
	 * @param string|null               $role        The caller's role in the active administration.
	 *
	 * @return TravelController
	 */
	private function controller(?array $arrangement, bool $admin=false, ?string $role=null): TravelController {
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturn($arrangement);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pjansen');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);
		$administrations = $this->createMock(AdministrationService::class);
		$administrations->method('getActiveAdministrationRole')->willReturn($role);
		$administrations->method('getActiveAdministrationId')->willReturn('ADM-001');
		$this->routes = $this->createMock(RouteDistanceService::class);
		$this->gateway = $this->createMock(HoursRegisterGateway::class);
		$this->reports = $this->createMock(WpmReportService::class);

		return new TravelController(
			request: $this->createMock(IRequest::class),
			routes: $this->routes,
			reports: $this->reports,
			gateway: $this->gateway,
			rbac: $rbac,
			administrationService: $administrations,
			groupManager: $groups,
			userSession: $session
		);
	}//end controller()

	/**
	 * An arrangement of the calling employee.
	 *
	 * @return array<string, mixed>
	 */
	private function ownArrangement(): array {
		return ['id' => 'arr-1', 'userId' => 'pjansen', 'originPostcode' => '2611 AB', 'destinationPostcode' => '2628 CD', 'distanceKmOneWay' => 18, 'status' => 'draft'];
	}//end ownArrangement()

	/**
	 * An arrangement the caller cannot read is not found.
	 *
	 * @return void
	 */
	public function testAnUnreadableArrangementIs404(): void {
		$controller = $this->controller(null, false, 'hr');
		$this->routes->expects(self::never())->method('lookup');

		self::assertSame(Http::STATUS_NOT_FOUND, $controller->routeDistance('arr-1')->getStatus());
	}//end testAnUnreadableArrangementIs404()

	/**
	 * Someone else's arrangement, read by a colleague who is not HR, is refused.
	 *
	 * @return void
	 */
	public function testSomeoneElsesArrangementIsRefusedToAColleague(): void {
		$controller = $this->controller(array_merge($this->ownArrangement(), ['userId' => 'kdevries']));
		$this->routes->expects(self::never())->method('lookup');

		self::assertSame(Http::STATUS_FORBIDDEN, $controller->routeDistance('arr-1')->getStatus());

		$controller = $this->controller(array_merge($this->ownArrangement(), ['status' => 'approved']));
		self::assertSame(Http::STATUS_FORBIDDEN, $controller->routeDistance('arr-1')->getStatus(), 'An approved arrangement changes through HR, not its employee.');
	}//end testSomeoneElsesArrangementIsRefusedToAColleague()

	/**
	 * Scenario: no route planner installed, 409 with the reason, nothing saved.
	 *
	 * @return void
	 */
	public function testWithoutARoutePlannerTheTypedDistanceIsKept(): void {
		$controller = $this->controller(array_merge($this->ownArrangement(), ['userId' => 'kdevries']), false, 'hr');
		$this->routes->method('lookup')->willThrowException(new RouteDistanceUnavailableException('Er is geen routeplanner beschikbaar.'));
		$this->gateway->expects(self::never())->method('save');

		$response = $controller->routeDistance('arr-1');
		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame('Er is geen routeplanner beschikbaar.', $response->getData()['message']);
	}//end testWithoutARoutePlannerTheTypedDistanceIsKept()

	/**
	 * Scenario: distance from the route planner, saved with its source so the
	 * allowance is recalculated on the save.
	 *
	 * @return void
	 */
	public function testTheRoutePlannerDistanceIsSavedWithItsSource(): void {
		$controller = $this->controller($this->ownArrangement());
		$this->routes->method('lookup')->with('2611 AB', '2628 CD')->willReturn(['distanceKm' => 7.4, 'provider' => 'ANWB Routeplanner']);
		$stored = array_merge($this->ownArrangement(), ['employeeId' => 'emp-1', 'daysPerWeek' => 4, 'transportMode' => 'car', 'monthlyAllowance' => 90.72]);
		$this->gateway->method('findObjectData')->with('arr-1', 'CommuteArrangement')->willReturnOnConsecutiveCalls(
			array_merge($stored, ['@self' => ['id' => 'arr-1']]),
			['id' => 'arr-1', 'distanceKmOneWay' => 7.4, 'monthlyAllowance' => 48.56]
		);
		$saved = null;
		$this->gateway->expects(self::once())->method('save')->willReturnCallback(
			function (array $payload, string $schema, ?string $uuid) use (&$saved): ObjectEntity {
				self::assertSame('CommuteArrangement', $schema);
				self::assertSame('arr-1', $uuid);
				$saved = $payload;
				return new ObjectEntity();
			}
		);

		$response = $controller->routeDistance('arr-1');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(48.56, $response->getData()['monthlyAllowance']);
		self::assertSame(7.4, $saved['distanceKmOneWay']);
		self::assertSame('routeplanner', $saved['distanceSource']);
		self::assertSame('ANWB Routeplanner', $saved['routeProvider']);
	}//end testTheRoutePlannerDistanceIsSavedWithItsSource()

	/**
	 * OpenRegister's save replaces the whole object, so the route distance is
	 * saved on top of the stored arrangement: the fields it does not change
	 * (employee, days, mode, postcodes, status) are carried, not nulled.
	 *
	 * @return void
	 */
	public function testTheRouteDistanceSaveKeepsTheRestOfTheArrangement(): void {
		$controller = $this->controller($this->ownArrangement());
		$this->routes->method('lookup')->willReturn(['distanceKm' => 7.4, 'provider' => 'ANWB Routeplanner']);
		$stored = array_merge($this->ownArrangement(), ['employeeId' => 'emp-1', 'daysPerWeek' => 4, 'transportMode' => 'car']);
		$this->gateway->method('findObjectData')->willReturnOnConsecutiveCalls(
			array_merge($stored, ['@self' => ['id' => 'arr-1']]),
			$stored
		);
		$saved = null;
		$this->gateway->expects(self::once())->method('save')->willReturnCallback(
			function (array $payload) use (&$saved): ObjectEntity {
				$saved = $payload;
				return new ObjectEntity();
			}
		);

		$controller->routeDistance('arr-1');

		self::assertIsArray($saved);
		foreach (['employeeId' => 'emp-1', 'userId' => 'pjansen', 'daysPerWeek' => 4, 'transportMode' => 'car', 'originPostcode' => '2611 AB', 'destinationPostcode' => '2628 CD', 'status' => 'draft'] as $field => $value) {
			self::assertSame($value, ($saved[$field] ?? null), $field.' is carried through the save.');
		}

		self::assertSame(7.4, $saved['distanceKmOneWay']);
		self::assertArrayNotHasKey('@self', $saved);
		self::assertArrayNotHasKey('id', $saved);
	}//end testTheRouteDistanceSaveKeepsTheRestOfTheArrangement()

	/**
	 * Only HR or an administrator compiles the mobility report.
	 *
	 * @return void
	 */
	public function testOnlyHrCompilesTheMobilityReport(): void {
		$controller = $this->controller(null);
		$this->reports->expects(self::never())->method('compile');
		self::assertSame(Http::STATUS_FORBIDDEN, $controller->wpmReport('ADM-001', 2026)->getStatus());

		$controller = $this->controller(null, false, 'hr');
		$this->reports->expects(self::once())->method('compile')->with('ADM-001', 2026, 'pjansen')->willReturn(['id' => 'wpm-1', 'totalBusinessKm' => 150.0]);
		$response = $controller->wpmReport('ADM-001', 2026);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(150.0, $response->getData()['totalBusinessKm']);
	}//end testOnlyHrCompilesTheMobilityReport()

}//end class
