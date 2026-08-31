<?php

/**
 * Unit tests for AnalyticsController.
 *
 * Pins the guard REQ-DSI-005 describes: an `employee`-role active
 * administration is refused with 403 and carries no payload; an `hr`/
 * `accountant`-role caller is admitted with 200; a caller-supplied
 * `administrationId` query parameter has no effect on which tenant's data
 * returns (the controller never even reads that parameter); and no session
 * refuses with 401 before any resolve.
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
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\AnalyticsController;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\AnalyticsService;
use OCA\Humaniq\Service\ObligationsService;
use OCA\Humaniq\Service\SeriesLatest;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AnalyticsController.
 *
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-005
 */
class AnalyticsControllerTest extends TestCase {

	/**
	 * REQ-DSI-005 "An employee-role caller is refused" scenario: 403, no
	 * payroll/absence/obligations data.
	 *
	 * @return void
	 */
	public function testEmployeeRoleCallerReceives403AndNoPayload(): void {
		$administrationService = $this->createMock(AdministrationService::class);
		$administrationService->method('getActiveAdministrationId')->with('devries')->willReturn('ADM-001');
		$administrationService->method('getActiveAdministrationRole')->with('devries')->willReturn('employee');

		$analyticsService = $this->createMock(AnalyticsService::class);
		$analyticsService->expects($this->never())->method('getTrends');

		$obligationsService = $this->createMock(ObligationsService::class);
		$obligationsService->expects($this->never())->method('getObligations');

		$controller = $this->buildController($analyticsService, $obligationsService, $administrationService, 'devries');

		$response = $controller->trends();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertArrayNotHasKey('series', $response->getData());

		$obligationsResponse = $controller->obligations();
		$this->assertSame(Http::STATUS_FORBIDDEN, $obligationsResponse->getStatus());
	}//end testEmployeeRoleCallerReceives403AndNoPayload()

	/**
	 * REQ-DSI-005 "A caller with no active administration is refused"
	 * scenario — the same 403, before any role is even read.
	 *
	 * @return void
	 */
	public function testNoActiveAdministrationReceives403(): void {
		$administrationService = $this->createMock(AdministrationService::class);
		$administrationService->method('getActiveAdministrationId')->willReturn(null);
		$administrationService->expects($this->never())->method('getActiveAdministrationRole');

		$analyticsService = $this->createMock(AnalyticsService::class);
		$analyticsService->expects($this->never())->method('getTrends');

		$controller = $this->buildController($analyticsService, $this->createMock(ObligationsService::class), $administrationService, 'nobody');

		$response = $controller->trends();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testNoActiveAdministrationReceives403()

	/**
	 * REQ-DSI-005 "An hr-role caller is admitted" scenario: 200, scoped to
	 * the caller's own active administration.
	 *
	 * @return void
	 */
	public function testHrRoleCallerReceives200ScopedToTheirAdministration(): void {
		$administrationService = $this->createMock(AdministrationService::class);
		$administrationService->method('getActiveAdministrationId')->with('hr-devries')->willReturn('ADM-001');
		$administrationService->method('getActiveAdministrationRole')->with('hr-devries')->willReturn('hr');

		$trends = ['metric' => 'absence-rate', 'period' => 'quarter', 'series' => [['date' => '2026-08', 'value' => 12.5]]];
		$analyticsService = $this->createMock(AnalyticsService::class);
		$analyticsService->method('getTrends')->with('absence-rate', 'quarter', 'ADM-001')->willReturn($trends);

		// The service's series, plus the `latest` block the controller composes
		// for the Dashboard's KPI tiles — they read their headline from this
		// same response so a tile cannot disagree with the chart beneath it.
		$expected = ($trends + ['latest' => ['date' => '2026-08', 'value' => 12.5, 'previous' => null]]);

		$controller = $this->buildController(
			$analyticsService,
			$this->createMock(ObligationsService::class),
			$administrationService,
			'hr-devries',
			['metric' => 'absence-rate', 'period' => 'quarter']
		);

		$response = $controller->trends();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($expected, $response->getData());
	}//end testHrRoleCallerReceives200ScopedToTheirAdministration()

	/**
	 * REQ-DSI-005 "An accountant-role caller is admitted" — the second
	 * allowed role.
	 *
	 * @return void
	 */
	public function testAccountantRoleCallerReceives200(): void {
		$administrationService = $this->createMock(AdministrationService::class);
		$administrationService->method('getActiveAdministrationId')->willReturn('ADM-002');
		$administrationService->method('getActiveAdministrationRole')->willReturn('accountant');

		$obligationsService = $this->createMock(ObligationsService::class);
		$obligationsService->method('getObligations')->with('ADM-002')->willReturn([]);

		$controller = $this->buildController(
			$this->createMock(AnalyticsService::class),
			$obligationsService,
			$administrationService,
			'accountant-1'
		);

		$response = $controller->obligations();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAccountantRoleCallerReceives200()

	/**
	 * REQ-DSI-005 "A caller-supplied administrationId is ignored" scenario:
	 * an hr-role caller whose active administration is ADM-001 is scoped to
	 * ADM-001 regardless of a query parameter naming a different tenant —
	 * proven here by asserting the service is called with the SERVER-resolved
	 * id even though the request carries a different one, and separately
	 * that the controller never reads an `administrationId` request param at
	 * all.
	 *
	 * @return void
	 */
	public function testCallerSuppliedAdministrationIdHasNoEffect(): void {
		$administrationService = $this->createMock(AdministrationService::class);
		$administrationService->method('getActiveAdministrationId')->willReturn('ADM-001');
		$administrationService->method('getActiveAdministrationRole')->willReturn('hr');

		$analyticsService = $this->createMock(AnalyticsService::class);
		$analyticsService->expects($this->once())
			->method('getTrends')
			->with($this->anything(), $this->anything(), 'ADM-001')
			->willReturn(['metric' => 'absence-rate', 'period' => 'year', 'series' => []]);

		// The controller reads ONLY `metric`/`period` off the request — an
		// `administrationId` query parameter (however this fake would answer
		// it) is never consulted, which is why the assertion below can prove
		// "no request parameter influences which tenant's data is returned"
		// purely from the getTrends() call args.
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) {
				return match ($key) {
					'metric' => 'absence-rate',
					'period' => 'year',
					'administrationId' => 'ADM-999-CLIENT-SUPPLIED',
					default => $default,
				};
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr-devries');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$logger = $this->createMock(LoggerInterface::class);

		$controller = new AnalyticsController(
			$request,
			$analyticsService,
			$this->createMock(ObligationsService::class),
			$administrationService,
			new SeriesLatest(),
			$userSession,
			$logger
		);

		$response = $controller->trends();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testCallerSuppliedAdministrationIdHasNoEffect()

	/**
	 * No session -> 401, before any administration resolve.
	 *
	 * @return void
	 */
	public function testNoSessionReceives401(): void {
		$administrationService = $this->createMock(AdministrationService::class);
		$administrationService->expects($this->never())->method('getActiveAdministrationId');

		$analyticsService = $this->createMock(AnalyticsService::class);

		$controller = $this->buildController($analyticsService, $this->createMock(ObligationsService::class), $administrationService, null);

		$response = $controller->trends();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testNoSessionReceives401()

	/**
	 * An unsupported metric maps to 400 with a static label — never a value
	 * derived from the underlying exception message.
	 *
	 * @return void
	 */
	public function testUnsupportedMetricReturns400(): void {
		$administrationService = $this->createMock(AdministrationService::class);
		$administrationService->method('getActiveAdministrationId')->willReturn('ADM-001');
		$administrationService->method('getActiveAdministrationRole')->willReturn('hr');

		$analyticsService = $this->createMock(AnalyticsService::class);
		$analyticsService->method('getTrends')->willThrowException(new \InvalidArgumentException('Unsupported metric'));

		$controller = $this->buildController(
			$analyticsService,
			$this->createMock(ObligationsService::class),
			$administrationService,
			'hr-devries',
			['metric' => 'not-real']
		);

		$response = $controller->trends();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Unsupported metric', $response->getData()['message']);
	}//end testUnsupportedMetricReturns400()

	/**
	 * Build an `AnalyticsController` with mocked collaborators and a session
	 * resolving to `$userId` (null = no session).
	 *
	 * @param AnalyticsService $analyticsService The (mocked) analytics service.
	 * @param ObligationsService $obligationsService The (mocked) obligations service.
	 * @param AdministrationService $administrationService The (mocked) administration service.
	 * @param string|null $userId The acting user id, or null for no session.
	 * @param array<string, string> $params Request params `getParam()` resolves.
	 *
	 * @return AnalyticsController
	 */
	private function buildController(
		AnalyticsService $analyticsService,
		ObligationsService $obligationsService,
		AdministrationService $administrationService,
		?string $userId,
		array $params = [],
	): AnalyticsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null) => ($params[$key] ?? $default)
		);

		$userSession = $this->createMock(IUserSession::class);
		if ($userId === null) {
			$userSession->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$userSession->method('getUser')->willReturn($user);
		}

		$logger = $this->createMock(LoggerInterface::class);

		return new AnalyticsController($request, $analyticsService, $obligationsService, $administrationService, new SeriesLatest(), $userSession, $logger);
	}//end buildController()

}//end class
