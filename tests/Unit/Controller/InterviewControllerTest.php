<?php

/**
 * Unit tests for InterviewController.
 *
 * Pins the guarded `sync()` endpoint contract (REQ-INTV-008): a non-admin/HR
 * caller is refused 403 BEFORE any ObjectService resolve or service call, an
 * unknown/unauthorized `interviewId` collapses to 404 before any calendar
 * write, a missing `interviewId` is refused 400 before any resolve, and the
 * happy path delegates to `InterviewCalendarService::syncOne()` returning
 * its outcome verbatim. Drives the controller through a fake ObjectService
 * double (a fake collaborator, not a fake of the logic under test) plus a
 * mocked `InterviewCalendarService`, mirroring the `OfferControllerTest`
 * precedent.
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
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-008
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Controller;

use OCA\Hrmq\Controller\InterviewController;
use OCA\Hrmq\Service\InterviewCalendarService;
use OCA\Hrmq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for InterviewController.
 *
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-008
 */
class InterviewControllerTest extends TestCase {

	/**
	 * A scheduled Interview fixture, overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function interview(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'intv-1',
				'status' => 'scheduled',
			],
			$overrides
		);

	}//end interview()

	/**
	 * REQ-INTV-008 Scenario "Non-admin, non-HR user is rejected".
	 *
	 * @return void
	 */
	public function testNonAdminCallerIsRefusedBeforeAnyResolveOrServiceCall(): void {
		[$controller, $fake, $service] = $this->buildController(isAdmin: false, interviewRow: $this->interview());
		$service->expects($this->never())->method('syncOne');

		$response = $controller->sync('intv-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($fake->findCalled, 'No ObjectService resolve occurs for a non-admin/HR caller.');

	}//end testNonAdminCallerIsRefusedBeforeAnyResolveOrServiceCall()

	/**
	 * An unknown/unauthorized interviewId collapses to 404 before any
	 * calendar write.
	 *
	 * @return void
	 */
	public function testUnknownOrUnauthorizedInterviewIdReturns404(): void {
		[$controller, , $service] = $this->buildController(isAdmin: true, interviewRow: null);
		$service->expects($this->never())->method('syncOne');

		$response = $controller->sync('intv-ghost');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testUnknownOrUnauthorizedInterviewIdReturns404()

	/**
	 * A missing interviewId is refused 400 before any resolve.
	 *
	 * @return void
	 */
	public function testMissingInterviewIdReturns400BeforeAnyResolve(): void {
		[$controller, $fake] = $this->buildController(isAdmin: true, interviewRow: null);

		$response = $controller->sync(null);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($fake->findCalled);

	}//end testMissingInterviewIdReturns400BeforeAnyResolve()

	/**
	 * REQ-INTV-008 Scenario "Admin/HR user syncs one interview from the
	 * detail page" — the happy path delegates to
	 * `InterviewCalendarService::syncOne()` and returns its outcome
	 * verbatim.
	 *
	 * @return void
	 */
	public function testHappyPathDelegatesToTheServiceAndReturnsItsOutcome(): void {
		[$controller, , $service] = $this->buildController(isAdmin: true, interviewRow: $this->interview());

		$outcome = [
			'type' => 'interview',
			'sourceId' => 'intv-1',
			'status' => 'created',
			'message' => 'Kalenderafspraak aangemaakt.',
		];

		$service->expects($this->once())
			->method('syncOne')
			->with('intv-1')
			->willReturn($outcome);

		$response = $controller->sync('intv-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($outcome, $response->getData());

	}//end testHappyPathDelegatesToTheServiceAndReturnsItsOutcome()

	/**
	 * Build an `InterviewController` with a fake container-resolved
	 * ObjectService whose `find()` returns the fixed `$interviewRow` (null
	 * simulating unknown/unauthorized) and a mocked `InterviewCalendarService`.
	 *
	 * @param bool $isAdmin Whether the fake caller is an admin/HR principal.
	 * @param array<string, mixed>|null $interviewRow The row `find()` should return.
	 *
	 * @return array{0: InterviewController, 1: object, 2: InterviewCalendarService&\PHPUnit\Framework\MockObject\MockObject}
	 */
	private function buildController(bool $isAdmin, ?array $interviewRow): array {
		$request = $this->createMock(IRequest::class);

		$fake = new class($interviewRow) {

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
			 * @param string $id The object id.
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

		$interviewCalendarService = $this->createMock(InterviewCalendarService::class);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr-admin');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		$logger = $this->createMock(LoggerInterface::class);

		return [
			new InterviewController($request, $container, $interviewCalendarService, $settings, $userSession, $groupManager, $logger),
			$fake,
			$interviewCalendarService,
		];

	}//end buildController()

}//end class
