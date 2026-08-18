<?php

/**
 * Contract tests for PayrollController's mutation / retro / WKR endpoints.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payroll-core-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Controller;

use OCA\Hrmq\Controller\PayrollController;
use OCA\Hrmq\Service\PayrollMutationService;
use OCA\Hrmq\Service\PayrollRunService;
use OCA\Hrmq\Service\ProformaPayslipService;
use OCA\Hrmq\Service\RetroAdjustmentService;
use OCA\Hrmq\Service\SettingsService;
use OCA\Hrmq\Service\WkrService;
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
	 * @param bool $isPrivileged Whether the caller is in the admin group.
	 *
	 * @return PayrollController
	 */
	private function buildController(bool $isPrivileged): PayrollController {
		$request = $this->createMock(IRequest::class);

		// Deliberately hostile: every call is a failure. These endpoints must
		// refuse before they reach OpenRegister, so any read here is the
		// regression this test exists to catch.
		$objectService = new class {

			/**
			 * @param string             $name The called method.
			 * @param array<int, mixed>  $args The call arguments.
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
		$settings->method('getRegisterSlug')->willReturn('hrmq');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('tester');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isPrivileged);
		$groupManager->method('isInGroup')->willReturn($isPrivileged);

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
			$groupManager,
			$this->createMock(LoggerInterface::class)
		);

	}//end buildController()

}//end class
