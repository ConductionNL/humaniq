<?php

/**
 * Contract tests for the three cycle endpoints on CompCycleController.
 *
 * Each endpoint resolves the cycle under the caller's own RBAC first (an
 * unreadable cycle is a 404, never a hint that it exists), then requires an
 * administrator or the `hr` role for the active administration (an employee
 * gets 403), and only then hands the work to the services.
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
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-002
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\CompCycleController;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\CompAdjustmentService;
use OCA\Humaniq\Service\CompCollectiveService;
use OCA\Humaniq\Service\CompCycleApprover;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The cycle endpoints' 404, 403 and happy paths.
 */
class CompCycleControllerTest extends TestCase {

	/**
	 * The collective service double.
	 *
	 * @var CompCollectiveService&MockObject
	 */
	private CompCollectiveService&MockObject $collective;

	/**
	 * The approval service double.
	 *
	 * @var CompCycleApprover&MockObject
	 */
	private CompCycleApprover&MockObject $approver;

	/**
	 * The effectuation service double.
	 *
	 * @var CompAdjustmentService&MockObject
	 */
	private CompAdjustmentService&MockObject $adjustments;

	/**
	 * Build the controller for a caller.
	 *
	 * @param bool $cycleReadable Whether the caller's RBAC reads the cycle.
	 * @param bool $admin Whether the caller is a Nextcloud admin.
	 * @param string|null $role The caller's role in the active administration.
	 *
	 * @return CompCycleController
	 */
	private function controller(bool $cycleReadable, bool $admin, ?string $role): CompCycleController {
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturn($cycleReadable === true ? ['id' => 'cycle-1', 'status' => 'open', 'kind' => 'collective'] : null);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('salarisadmin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		$administrations = $this->createMock(AdministrationService::class);
		$administrations->method('getActiveAdministrationRole')->willReturn($role);

		$this->collective = $this->createMock(CompCollectiveService::class);
		$this->approver = $this->createMock(CompCycleApprover::class);
		$this->adjustments = $this->createMock(CompAdjustmentService::class);

		return new CompCycleController(
			$this->createMock(IRequest::class),
			$this->collective,
			$this->approver,
			$this->adjustments,
			$rbac,
			$administrations,
			$groups,
			$session,
		);
	}//end controller()

	/**
	 * An unreadable cycle is a 404 on all three endpoints, and nothing runs.
	 *
	 * @return void
	 */
	public function testAnUnreadableCycleIs404(): void {
		$controller = $this->controller(false, true, 'hr');
		$this->collective->expects($this->never())->method('proposeForCycle');
		$this->approver->expects($this->never())->method('approveCycle');
		$this->adjustments->expects($this->never())->method('effectuateCycle');

		self::assertSame(Http::STATUS_NOT_FOUND, $controller->proposeCollective('cycle-1')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $controller->approveCycle('cycle-1')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $controller->effectuateCycle('cycle-1')->getStatus());
	}//end testAnUnreadableCycleIs404()

	/**
	 * An employee who can read the cycle still gets 403 on all three.
	 *
	 * @return void
	 */
	public function testAnEmployeeRoleIs403(): void {
		$controller = $this->controller(true, false, 'employee');
		$this->collective->expects($this->never())->method('proposeForCycle');
		$this->approver->expects($this->never())->method('approveCycle');
		$this->adjustments->expects($this->never())->method('effectuateCycle');

		self::assertSame(Http::STATUS_FORBIDDEN, $controller->proposeCollective('cycle-1')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $controller->approveCycle('cycle-1')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $controller->effectuateCycle('cycle-1')->getStatus());
	}//end testAnEmployeeRoleIs403()

	/**
	 * An HR user proposes with a dry run and a hand-picked selection; the
	 * caller's uid is what the service stamps as proposer.
	 *
	 * @return void
	 */
	public function testHrProposesWithADryRunAndASelection(): void {
		$controller = $this->controller(true, false, 'hr');
		$this->collective->expects($this->once())->method('proposeForCycle')
			->with('cycle-1', 'salarisadmin', true, ['emp-1', 'emp-2'])
			->willReturn(['status' => 'ok', 'wouldCreate' => 2]);

		$response = $controller->proposeCollective('cycle-1', 'true', ['emp-1', 'emp-2']);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(2, $response->getData()['wouldCreate']);
	}//end testHrProposesWithADryRunAndASelection()

	/**
	 * A refused proposal run (an individual cycle) is a 400 with the reason.
	 *
	 * @return void
	 */
	public function testARefusedRunIs400(): void {
		$controller = $this->controller(true, true, null);
		$this->collective->method('proposeForCycle')->willReturn(['status' => 'refused-not-collective', 'message' => 'Deze ronde is voor losse voorstellen.']);

		self::assertSame(Http::STATUS_BAD_REQUEST, $controller->proposeCollective('cycle-1')->getStatus());
	}//end testARefusedRunIs400()

	/**
	 * An admin approves with a reason and effectuates with a preview.
	 *
	 * @return void
	 */
	public function testAnAdminApprovesAndPreviewsTheEffectuation(): void {
		$controller = $this->controller(true, true, null);
		$this->approver->expects($this->once())->method('approveCycle')
			->with('cycle-1', 'salarisadmin', 'CAO 2026')
			->willReturn(['status' => 'ok', 'approved' => 3]);
		$this->adjustments->expects($this->once())->method('effectuateCycle')
			->with('cycle-1', null, true)
			->willReturn([['adjustmentId' => 'a', 'status' => 'would-apply'], ['adjustmentId' => 'b', 'status' => 'refused-not-due']]);

		self::assertSame(3, $controller->approveCycle('cycle-1', 'CAO 2026')->getData()['approved']);

		$preview = $controller->effectuateCycle('cycle-1', true)->getData();
		self::assertTrue($preview['dryRun']);
		self::assertSame(['would-apply' => 1, 'refused-not-due' => 1], $preview['counts']);
		self::assertCount(2, $preview['outcomes']);
	}//end testAnAdminApprovesAndPreviewsTheEffectuation()

}//end class
