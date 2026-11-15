<?php

/**
 * Payroll controller role gate test
 *
 * The payroll endpoints open only for the session user's own payroll role:
 * HR without payroll and users without a role are refused before any read.
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
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-008
 */


declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\PayrollController;
use OCA\Humaniq\Service\PayrollMutationService;
use OCA\Humaniq\Service\PayrollRunService;
use OCA\Humaniq\Service\PayrollRunServices;
use OCA\Humaniq\Service\ProformaPayslipService;
use OCA\Humaniq\Service\RetroAdjustmentService;
use OCA\Humaniq\Service\WkrService;
use Psr\Container\ContainerInterface;

/**
 * Only the session user's payroll role opens the payroll endpoints.
 */
class PayrollControllerRoleGateTest extends CallerIdentityTestCase {

	/**
	 * The payroll controller for one session user.
	 *
	 * @param string $uid The session user.
	 * @param ContainerInterface $c The container.
	 * @param PayrollRunServices|null $runs The run services.
	 * @param WkrService|null $wkr The WKR service.
	 *
	 * @return PayrollController
	 */
	private function ctl(string $uid, ContainerInterface $c, ?PayrollRunServices $runs = null, ?WkrService $wkr = null): PayrollController {
		return new PayrollController($this->request('boss'), $runs ?? $this->runs(), $wkr ?? $this->createMock(WkrService::class), $this->settings(), $this->context($uid, $c));
	}

	/**
	 * Run services with the given doubles.
	 *
	 * @param PayrollRunService|null $run The run service.
	 * @param PayrollMutationService|null $mut The mutation service.
	 *
	 * @return PayrollRunServices
	 */
	private function runs(?PayrollRunService $run = null, ?PayrollMutationService $mut = null): PayrollRunServices {
		return new PayrollRunServices($run ?? $this->createMock(PayrollRunService::class), $mut ?? $this->createMock(PayrollMutationService::class), $this->createMock(ProformaPayslipService::class), $this->createMock(RetroAdjustmentService::class));
	}

	/**
	 * No payroll role in the session (clerk, or HR without payroll): refused before any read, whatever the request claims.
	 *
	 * @return void
	 */
	public function testNonPayrollSessionUsersAreRefusedFirst(): void {
		foreach (['clerk', 'hr-only'] as $uid) {
			$run = $this->createMock(PayrollRunService::class);
			$run->expects($this->never())->method($this->anything());
			$mut = $this->createMock(PayrollMutationService::class);
			$mut->expects($this->never())->method($this->anything());
			$wkr = $this->createMock(WkrService::class);
			$wkr->expects($this->never())->method($this->anything());
			$this->assertSame(403, $this->ctl($uid, $this->untouchable(), $this->runs($run, $mut))->calculate('run-7')->getStatus(), $uid);
			$this->assertSame(403, $this->ctl($uid, $this->untouchable(), $this->runs($run, $mut))->mutations('run-7', 'run-6')->getStatus(), $uid);
			$this->assertSame(403, $this->ctl($uid, $this->untouchable(), null, $wkr)->wkrAssess('wkr-1')->getStatus(), $uid);
		}
	}

	/**
	 * Payroll and the admin recalculate the asked run.
	 *
	 * @return void
	 */
	public function testPayrollAndAdminRecalculate(): void {
		foreach (['pay-only', 'boss'] as $uid) {
			$run = $this->createMock(PayrollRunService::class);
			$run->expects($this->once())->method('recalculateRun')->with('run-7')->willReturn(['status' => 'calculated']);
			$this->assertSame(200, $this->ctl($uid, $this->serving(['run-7' => ['id' => 'run-7', 'status' => 'draft']]), $this->runs($run))->calculate('run-7')->getStatus(), $uid);
		}
	}

	/**
	 * The DGA status is the session user's own, never the request-claimed user's.
	 *
	 * @return void
	 */
	public function testDgaStatusIsTheSessionUsersOwn(): void {
		$rows = [
			'e-boss' => ['id' => 'e-boss', 'nextcloudUserId' => 'boss', 'isDga' => true, 'grossMonthlySalary' => 9000],
			'e-clerk' => ['id' => 'e-clerk', 'nextcloudUserId' => 'clerk', 'isDga' => false],
		];
		$data = $this->ctl('clerk', $this->serving($rows))->dgaStatus()->getData();
		$this->assertSame(['isDga' => false, 'met' => null], $data);
	}
}
