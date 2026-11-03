<?php

/**
 * Payroll filing caller test
 *
 * The wage tax filing, third-party payment and document controllers act for the
 * session user: a forged request identity is refused before any read, and
 * each role reaches its service as itself.
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
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
 */


declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\DocumentController;
use OCA\Humaniq\Controller\LoonaangifteMessageController;
use OCA\Humaniq\Controller\ThirdPartyReportController;
use OCA\Humaniq\Service\HrDocumentService;
use OCA\Humaniq\Service\LoonaangifteCorrectionService;
use OCA\Humaniq\Service\LoonaangifteMessageService;
use OCA\Humaniq\Service\PayrollTaxFilingServices;
use OCA\Humaniq\Service\ThirdPartyPaymentServices;
use OCA\Humaniq\Service\ThirdPartyReportService;
use OCA\Humaniq\Service\ThirdPartyStatementService;
use Psr\Container\ContainerInterface;

/**
 * The wage tax filing, third-party payment and document controllers act for
 * the session user and check that user's own roles.
 */
class PayrollFilingCallerTest extends CallerIdentityTestCase {

	/**
	 * The wage tax filing controller for one session user.
	 *
	 * @param string $uid The session user.
	 * @param ContainerInterface $c The container.
	 * @param LoonaangifteMessageService|null $m The message service.
	 * @param LoonaangifteCorrectionService|null $k The correction service.
	 *
	 * @return LoonaangifteMessageController
	 */
	private function filingCtl(string $uid, ContainerInterface $c, ?LoonaangifteMessageService $m = null, ?LoonaangifteCorrectionService $k = null): LoonaangifteMessageController {
		return new LoonaangifteMessageController($this->request('boss'), $this->settings(), new PayrollTaxFilingServices($m ?? $this->createMock(LoonaangifteMessageService::class), $k ?? $this->createMock(LoonaangifteCorrectionService::class)), $this->context($uid, $c));
	}

	/**
	 * The third-party payment controller for one session user.
	 *
	 * @param string $uid The session user.
	 * @param ContainerInterface $c The container.
	 * @param ThirdPartyReportService|null $r The report service.
	 * @param ThirdPartyStatementService|null $s The statement service.
	 *
	 * @return ThirdPartyReportController
	 */
	private function tpCtl(string $uid, ContainerInterface $c, ?ThirdPartyReportService $r = null, ?ThirdPartyStatementService $s = null): ThirdPartyReportController {
		return new ThirdPartyReportController($this->request('boss'), $this->settings(), new ThirdPartyPaymentServices($r ?? $this->createMock(ThirdPartyReportService::class), $s ?? $this->createMock(ThirdPartyStatementService::class)), $this->context($uid, $c));
	}

	/**
	 * A user without a role is refused before anything is read, whatever the request claims.
	 *
	 * @return void
	 */
	public function testAClerkIsRefusedBeforeAnyReadWhateverTheRequestClaims(): void {
		$m = $this->createMock(LoonaangifteMessageService::class);
		$m->expects($this->never())->method('render');
		$k = $this->createMock(LoonaangifteCorrectionService::class);
		$k->expects($this->never())->method($this->anything());
		$this->assertSame(403, $this->filingCtl('clerk', $this->untouchable(), $m, $k)->render('f-1')->getStatus());
		$this->assertSame(403, $this->filingCtl('clerk', $this->untouchable(), $m, $k)->correction('f-1')->getStatus());

		$r = $this->createMock(ThirdPartyReportService::class);
		$r->expects($this->never())->method($this->anything());
		$s = $this->createMock(ThirdPartyStatementService::class);
		$s->expects($this->never())->method($this->anything());
		$this->assertSame(403, $this->tpCtl('clerk', $this->untouchable(), $r, $s)->assemble('ADM-1', '2026')->getStatus());
		$this->assertSame(403, $this->tpCtl('clerk', $this->untouchable(), $r, $s)->statements('rep-1')->getStatus());
	}

	/**
	 * HR, payroll and the admin each pass the guard and act as themselves.
	 *
	 * @return void
	 */
	public function testEachRoleActsAsTheSessionUser(): void {
		$filing = ['id' => 'f-1', 'period' => '2026-06', 'status' => 'verzonden'];
		$report = ['id' => 'rep-1'];
		foreach (['hr-only', 'pay-only', 'boss'] as $uid) {
			$m = $this->createMock(LoonaangifteMessageService::class);
			$m->expects($this->once())->method('render')->with($filing, $uid)->willReturn(['status' => 'rendered']);
			$this->assertSame(200, $this->filingCtl($uid, $this->serving(['f-1' => $filing]), $m)->render('f-1')->getStatus(), $uid);

			$k = $this->createMock(LoonaangifteCorrectionService::class);
			$k->expects($this->once())->method('open')->with($filing, $uid)->willReturn(['status' => 'opened']);
			$this->assertSame(201, $this->filingCtl($uid, $this->serving(['f-1' => $filing]), null, $k)->correction('f-1')->getStatus(), $uid);

			$r = $this->createMock(ThirdPartyReportService::class);
			$r->expects($this->once())->method('assemble')->with('ADM-1', 2026, $uid)->willReturn(['status' => 'assembled']);
			$this->assertSame(200, $this->tpCtl($uid, $this->serving(['adm' => ['administrationId' => 'ADM-1']]), $r)->assemble('ADM-1', '2026')->getStatus(), $uid);

			$s = $this->createMock(ThirdPartyStatementService::class);
			$s->expects($this->once())->method('generateForReport')->with($report, $uid)->willReturn(['generated' => 1]);
			$this->assertSame(200, $this->tpCtl($uid, $this->serving(['rep-1' => $report]), null, $s)->statements('rep-1')->getStatus(), $uid);
		}
	}

	/**
	 * The document is generated for the session user, never a request-claimed one.
	 *
	 * @return void
	 */
	public function testTheDocumentActorIsTheSessionUser(): void {
		$svc = $this->createMock(HrDocumentService::class);
		$svc->expects($this->once())->method('generateLoonstrook')->with('p-1', 'clerk')->willReturn(['ok' => true]);
		$ctl = new DocumentController($this->request('boss'), $svc, $this->settings(), $this->context('clerk', $this->serving(['p-1' => ['id' => 'p-1']])));
		$this->assertSame(200, $ctl->generate(null, 'loonstrook', 'p-1')->getStatus());
	}
}
