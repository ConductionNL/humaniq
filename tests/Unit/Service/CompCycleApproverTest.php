<?php

/**
 * CompCycleApprover test
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CompCycleApprover;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\Humaniq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Approving a cycle without OpenRegister fails every proposed adjustment.
 *
 * @covers \OCA\Humaniq\Service\CompCycleApprover
 * @uses   \OCA\Humaniq\Service\SettingsService
 * @uses   \OCA\Humaniq\Service\CycleApproval
 */
class CompCycleApproverTest extends TestCase {

	/**
	 * Without OpenRegister no transition runs and the adjustment is reported failed.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterEveryProposalFails(): void {
		$gateway = $this->createMock(RecordStoreInterface::class);
		$gateway->method('findFiltered')->willReturn([['id' => 'adj-1', 'employeeId' => 'emp-1', 'status' => 'proposed', 'proposedBy' => 'hr']]);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $app !== 'openregister');
		$logger = $this->createMock(LoggerInterface::class);
		$message = 'humaniq requires the OpenRegister app, which is not installed on this instance.';
		$logger->expects($this->once())->method('warning')->with('CompCycleApprover: goedkeuren van adj-1 geweigerd: ' . $message);

		$approver = new CompCycleApprover($gateway, $container, $this->availability($apps), $logger);

		self::assertSame(
			['status' => 'ok', 'cycleId' => 'cycle-1', 'approved' => 0, 'refusedSelfApproval' => 0, 'failed' => 1, 'rows' => [['adjustmentId' => 'adj-1', 'employeeId' => 'emp-1', 'status' => 'failed', 'message' => $message]]],
			$approver->approveCycle('cycle-1', 'director')
		);
	}//end testWithoutOpenRegisterEveryProposalFails()

	/**
	 * What the approver asks whether OpenRegister is there.
	 *
	 * @param IAppManager $apps The app manager.
	 *
	 * @return SettingsService
	 */
	private function availability(IAppManager $apps): SettingsService {
		return new SettingsService($this->createMock(IAppConfig::class), $apps, $this->createMock(ContainerInterface::class), new NullLogger());
	}//end availability()

}//end class
