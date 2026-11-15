<?php

/**
 * Data-subject rights session admin test
 *
 * The data-subject-rights endpoints open only for a session administrator: HR
 * and payroll members and users without a role are refused on all four
 * endpoints before anything is read.
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-004
 */


declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\AvgDsrController;
use OCA\Humaniq\Service\AvgDsrService;
use Psr\Container\ContainerInterface;

/**
 * Only a session administrator runs data-subject requests.
 */
class AvgDsrSessionAdminTest extends CallerIdentityTestCase {

	/**
	 * The controller for one session user.
	 *
	 * @param string $uid The session user.
	 * @param ContainerInterface $c The container.
	 * @param AvgDsrService $svc The DSR service.
	 *
	 * @return AvgDsrController
	 */
	private function ctl(string $uid, ContainerInterface $c, AvgDsrService $svc): AvgDsrController {
		return new AvgDsrController($this->request('boss'), $svc, $this->settings(), $this->context($uid, $c));
	}

	/**
	 * The four endpoints, by name.
	 *
	 * @return array<string, callable>
	 */
	private static function calls(): array {
		return [
			'export' => fn ($x) => $x->export('emp-1', 'inzage', 'dsr-1'),
			'erasePreview' => fn ($x) => $x->erasePreview('emp-1', 'dsr-1'),
			'eraseConfirm' => fn ($x) => $x->eraseConfirm('emp-1', 'dsr-1'),
			'rectify' => fn ($x) => $x->rectify('emp-1', ['firstName' => 'X'], 'dsr-1'),
		];
	}

	/**
	 * Non-admin session users (incl. HR and payroll members) are refused before any read, whatever the request claims.
	 *
	 * @return void
	 */
	public function testOnlyTheSessionAdminPasses(): void {
		foreach (['clerk', 'hr-only', 'pay-only'] as $uid) {
			foreach (self::calls() as $name => $call) {
				$svc = $this->createMock(AvgDsrService::class);
				foreach (['exportForSubject', 'previewErasure', 'eraseSubject', 'rectifySubjectObject'] as $m) {
					$svc->expects($this->never())->method($m);
				}
				$this->assertSame(403, $call($this->ctl($uid, $this->untouchable(), $svc))->getStatus(), "$uid $name");
			}
		}
	}

	/**
	 * The session admin passes the gate and reaches the service for the asked employee.
	 *
	 * @return void
	 */
	public function testTheSessionAdminReachesTheService(): void {
		$svc = $this->createMock(AvgDsrService::class);
		$svc->method('exportForSubject')->with('emp-1')->willReturn(['ok' => true]);
		$svc->method('previewErasure')->willReturn(['ok' => true]);
		$svc->method('eraseSubject')->willReturn(['status' => 'erased']);
		$svc->method('rectifySubjectObject')->willReturn(['ok' => true]);
		foreach (self::calls() as $name => $call) {
			$this->assertSame(200, $call($this->ctl('boss', $this->serving(['emp-1' => ['id' => 'emp-1']]), $svc))->getStatus(), $name);
		}
	}
}
