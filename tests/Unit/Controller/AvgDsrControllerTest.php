<?php

/**
 * Unit tests for AvgDsrController.
 *
 * Pins the guarded endpoint contract (REQ-DSR-004): a non-admin caller is
 * refused 403 BEFORE any ObjectService resolve (no probe possible, unlike the
 * `isAdminOrHr()` precedent elsewhere in this app -- this gate is admin-ONLY,
 * a deliberate hrmq policy choice, hrmq#99); an unknown/unauthorized
 * `employeeId` collapses to 404; and any `RuntimeException` `AvgDsrService`
 * throws is translated into a 403 JSON response, never an uncaught 500 (kept
 * as defense-in-depth even though the guarded OpenRegister service consumed
 * since hrmq#99 does not throw one for privilege reasons). Drives the
 * controller through a fake ObjectService double for the no-admin-idor
 * resolve, and a mocked `AvgDsrService` for the operation itself (the
 * LoonbeslagControllerTest precedent).
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Controller
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Controller;

use OCA\Hrmq\Controller\AvgDsrController;
use OCA\Hrmq\Service\AvgDsrService;
use OCA\Hrmq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for AvgDsrController.
 *
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-004
 */
class AvgDsrControllerTest extends TestCase {

	/**
	 * A non-admin caller is refused 403 on every endpoint, BEFORE any
	 * ObjectService resolve.
	 *
	 * @return void
	 */
	public function testNonAdminCallerIsRefusedBeforeAnyResolve(): void {
		[$controller, $fake, ] = $this->buildController(isAdmin: false, employeeRow: null);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->export('emp-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->erasePreview('emp-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->eraseConfirm('emp-1', 'dsr-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->rectify('emp-1', ['lastName' => 'X'], 'dsr-1')->getStatus());

		$this->assertFalse($fake->findCalled, 'No ObjectService resolve occurs for a non-admin caller.');

	}//end testNonAdminCallerIsRefusedBeforeAnyResolve()

	/**
	 * An unknown/unauthorized employeeId collapses to 404 on export.
	 *
	 * @return void
	 */
	public function testUnknownEmployeeReturns404OnExport(): void {
		[$controller, , $service] = $this->buildController(isAdmin: true, employeeRow: null);

		$service->expects($this->never())->method('exportForSubject');

		$response = $controller->export('emp-ghost');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testUnknownEmployeeReturns404OnExport()

	/**
	 * A missing employeeId is refused 400 before any resolve.
	 *
	 * @return void
	 */
	public function testMissingEmployeeIdReturns400BeforeAnyResolve(): void {
		[$controller, $fake, ] = $this->buildController(isAdmin: true, employeeRow: null);

		$response = $controller->export(null);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($fake->findCalled);

	}//end testMissingEmployeeIdReturns400BeforeAnyResolve()

	/**
	 * REQ-DSR-004: a RuntimeException from AvgDsrService is translated into a
	 * 403 JSON response, never an uncaught 500.
	 *
	 * @return void
	 */
	public function testRuntimeExceptionIsTranslatedTo403(): void {
		[$controller, , $service] = $this->buildController(isAdmin: true, employeeRow: ['id' => 'emp-1']);

		$service->method('exportForSubject')->willThrowException(new \RuntimeException('DSAR operations require administrator privileges'));

		$response = $controller->export('emp-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testRuntimeExceptionIsTranslatedTo403()

	/**
	 * The happy path: export() resolves the employee and returns the service
	 * result.
	 *
	 * @return void
	 */
	public function testExportHappyPath(): void {
		[$controller, , $service] = $this->buildController(isAdmin: true, employeeRow: ['id' => 'emp-1']);

		$service->expects($this->once())
			->method('exportForSubject')
			->with('emp-1', 'inzage', null)
			->willReturn(['right' => 'inzage', 'count' => 0, 'objects' => []]);

		$response = $controller->export('emp-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('inzage', $response->getData()['right']);

	}//end testExportHappyPath()

	/**
	 * REQ-DSR-006: eraseConfirm() returns 400 when the service refuses (no
	 * recorded preview), never a 500.
	 *
	 * @return void
	 */
	public function testEraseConfirmReturns400WhenServiceRefuses(): void {
		[$controller, , $service] = $this->buildController(isAdmin: true, employeeRow: ['id' => 'emp-1']);

		$service->method('eraseSubject')->willReturn(['status' => 'refused', 'message' => 'Geen geregistreerd voorbeeld gevonden.']);

		$response = $controller->eraseConfirm('emp-1', 'dsr-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testEraseConfirmReturns400WhenServiceRefuses()

	/**
	 * rectify() refuses a missing/empty changes payload (400) before any
	 * resolve.
	 *
	 * @return void
	 */
	public function testRectifyRefusesEmptyChangesBeforeAnyResolve(): void {
		[$controller, $fake, ] = $this->buildController(isAdmin: true, employeeRow: ['id' => 'emp-1']);

		$response = $controller->rectify('emp-1', [], 'dsr-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($fake->findCalled);

	}//end testRectifyRefusesEmptyChangesBeforeAnyResolve()

	/**
	 * hrmq#99: rectify() passes the RBAC-resolved employeeId STRING directly
	 * to `rectifySubjectObject()` -- no internal-int-id resolution
	 * workaround (the guarded `Gdpr\DataSubjectRequestService::rectify()`
	 * takes a plain id/uuid string).
	 *
	 * @return void
	 */
	public function testRectifyHappyPathPassesEmployeeIdentifierDirectly(): void {
		[$controller, , $service] = $this->buildController(isAdmin: true, employeeRow: ['id' => 'emp-1']);

		$service->expects($this->once())
			->method('rectifySubjectObject')
			->with('emp-1', ['lastName' => 'Corrected'], 'dsr-1')
			->willReturn(['id' => 'emp-1', 'lastName' => 'Corrected']);

		$response = $controller->rectify('emp-1', ['lastName' => 'Corrected'], 'dsr-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testRectifyHappyPathPassesEmployeeIdentifierDirectly()

	/**
	 * Build an AvgDsrController with a fake container-resolved
	 * ObjectService (`find()` returns the fixed `$employeeRow`, null
	 * simulating unknown/unauthorized) and a mocked `AvgDsrService`.
	 *
	 * @param bool $isAdmin Whether the fake caller is an admin.
	 * @param array<string, mixed>|null $employeeRow The row `find()` should return.
	 *
	 * @return array{0: AvgDsrController, 1: object, 2: AvgDsrService&\PHPUnit\Framework\MockObject\MockObject}
	 */
	private function buildController(bool $isAdmin, ?array $employeeRow): array {
		$request = $this->createMock(IRequest::class);

		$fake = new class($employeeRow) {

			/**
			 * @var array<string, mixed>|null
			 */
			public ?array $row;

			/**
			 * @var bool
			 */
			public bool $findCalled = false;

			/**
			 * @param array<string, mixed>|null $row The row find() should return.
			 */
			public function __construct(?array $row) {
				$this->row = $row;

			}//end __construct()

			/**
			 * @param string $id Object id.
			 * @param string|null $register Register slug (unused by the fake).
			 * @param string|null $schema Schema name (unused by the fake).
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, ?string $register = null, ?string $schema = null): ?array {
				$this->findCalled = true;
				return $this->row;
			}//end find()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

		$avgDsrService = $this->createMock(AvgDsrService::class);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr-admin');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		$controller = new AvgDsrController($request, $container, $avgDsrService, $settings, $userSession, $groupManager);

		return [$controller, $fake, $avgDsrService];
	}//end buildController()

}//end class
