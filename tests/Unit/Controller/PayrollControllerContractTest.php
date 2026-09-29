<?php

/**
 * Contract tests for PayrollController's mutation / retro / WKR endpoints.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payroll-core-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\PayrollController;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\PayrollMutationService;
use OCA\Humaniq\Service\PayrollRunService;
use OCA\Humaniq\Service\ProformaPayslipService;
use OCA\Humaniq\Service\RetroAdjustmentService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\WkrService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Contract tests for /api/payroll/mutations, /api/payroll/adjust and
 * /api/payroll/wkr-assess (gate-25).
 *
 * WHAT IS PINNED HERE, AND WHY IT IS THE PART WORTH PINNING.
 *
 * These three endpoints all guard before they touch anything: a non-admin,
 * non-HR caller is refused with 403, and a missing identifier is refused with
 * 400. Both refusals happen BEFORE OpenRegister is consulted, which is exactly
 * why they are testable in this standalone suite — and exactly why they matter.
 * The 403 is the access-control contract for payroll data; the 400 is what
 * stops an empty identifier reaching a lookup.
 *
 * The success paths need a live OpenRegister register and belong to the
 * integration suite; asserting them here would mean mocking the register into
 * agreement with itself and proving nothing. The ObjectService double below
 * THROWS on any call, so a regression that lets one of these endpoints reach
 * the register before its guard fails loudly rather than silently passing.
 */
class PayrollControllerContractTest extends TestCase {

	/**
	 * A caller who is neither admin nor HR is refused mutation reports.
	 *
	 * @return void
	 */
	public function testMutationsRefusesANonPrivilegedCaller(): void {
		$response = $this->buildController(isPrivileged: false)->mutations('run-2');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testMutationsRefusesANonPrivilegedCaller()

	/**
	 * REQ-RFA-001: an HR adviser outside the payroll group may not calculate a
	 * payroll run; the refusal comes before any lookup.
	 *
	 * @return void
	 */
	public function testCalculateRefusesAnHrAdviserOutsidePayroll(): void {
		$response = $this->buildController(isPrivileged: false, groups: ['humaniq-hr'])->calculate('run-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testCalculateRefusesAnHrAdviserOutsidePayroll()

	/**
	 * REQ-RFA-001: a payroll member who is not an administrator passes the
	 * role check and reaches the run lookup (which this hostile register
	 * answers with not found).
	 *
	 * @return void
	 */
	public function testCalculateLetsAPayrollMemberReachTheRun(): void {
		$response = $this->buildController(isPrivileged: false, groups: ['humaniq-payroll'])->calculate('run-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testCalculateLetsAPayrollMemberReachTheRun()

	/**
	 * REQ-RFA-001: a payroll member reaches the mutation report's own input
	 * checks rather than the role refusal.
	 *
	 * @return void
	 */
	public function testMutationsLetsAPayrollMemberThrough(): void {
		$response = $this->buildController(isPrivileged: false, groups: ['humaniq-payroll'])->mutations('');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testMutationsLetsAPayrollMemberThrough()

	/**
	 * An empty toRunId is a 400, not a lookup for the empty string.
	 *
	 * @return void
	 */
	public function testMutationsRequiresAToRunId(): void {
		$response = $this->buildController(isPrivileged: true)->mutations('');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testMutationsRequiresAToRunId()

	/**
	 * An empty adjustmentId is a 400.
	 *
	 * @return void
	 */
	public function testAdjustRequiresAnAdjustmentId(): void {
		$response = $this->buildController(isPrivileged: true)->adjust('');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testAdjustRequiresAnAdjustmentId()

	/**
	 * A caller who is neither admin nor HR is refused WKR (re)assessment.
	 *
	 * @return void
	 */
	public function testWkrAssessRefusesANonPrivilegedCaller(): void {
		$response = $this->buildController(isPrivileged: false)->wkrAssess('wkr-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testWkrAssessRefusesANonPrivilegedCaller()

	/**
	 * An empty assessmentId is a 400.
	 *
	 * @return void
	 */
	public function testWkrAssessRequiresAnAssessmentId(): void {
		$response = $this->buildController(isPrivileged: true)->wkrAssess('');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testWkrAssessRequiresAnAssessmentId()

	/**
	 * Builds the controller with every collaborator mocked.
	 *
	 * @param bool $isPrivileged Whether the caller is a Nextcloud administrator.
	 * @param list<string> $groups The groups the caller is a member of.
	 *
	 * @return PayrollController
	 */
	private function buildController(bool $isPrivileged, array $groups = []): PayrollController {
		$request = $this->createMock(IRequest::class);

		// Deliberately hostile: every call is a failure. These endpoints must
		// refuse before they reach OpenRegister, so any read here is the
		// regression this test exists to catch.
		$objectService = new class {

			/**
			 * @param string $name The called method.
			 * @param array<int, mixed> $args The call arguments.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $args): mixed {
				throw new \RuntimeException(
					'guard must refuse before ObjectService::' . $name . ' is reached'
				);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('tester');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isPrivileged);
		$groupManager->method('isInGroup')->willReturnCallback(fn (string $uid, string $gid): bool => in_array($gid, $groups, true));

		return new PayrollController(
			$request,
			$container,
			$this->createMock(PayrollRunService::class),
			$this->createMock(PayrollMutationService::class),
			$this->createMock(ProformaPayslipService::class),
			$this->createMock(RetroAdjustmentService::class),
			$this->createMock(WkrService::class),
			$settings,
			$userSession,
			new HumaniqRoles($groupManager),
			$this->createMock(LoggerInterface::class)
		);

	}//end buildController()

}//end class
