<?php

/**
 * Tests for the RepublishLoonrunFlow repair step.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Repair
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Repair;

use OCA\Humaniq\Repair\RepublishLoonrunFlow;
use OCA\Humaniq\Service\FlowGraphKeypaths;
use OCA\Humaniq\Service\LoonrunFlowRepairService;
use OCA\Humaniq\Service\OpenRegisterAvailability;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The step prints the repair's report, and a failing repair warns instead of
 * aborting the upgrade.
 *
 * @covers \OCA\Humaniq\Repair\RepublishLoonrunFlow
 * @uses   \OCA\Humaniq\Service\LoonrunFlowRepairService
 * @uses   \OCA\Humaniq\Service\OpenRegisterAvailability
 */
class RepublishLoonrunFlowTest extends TestCase {

	/**
	 * Build the step over the real repair service and boundary doubles.
	 *
	 * @param bool               $installed Whether OpenRegister is installed.
	 * @param ContainerInterface $container The container the service resolves OpenRegister from.
	 * @param LoggerInterface    $logger    The step's logger.
	 *
	 * @return RepublishLoonrunFlow The step under test.
	 */
	private function step(bool $installed, ContainerInterface $container, LoggerInterface $logger): RepublishLoonrunFlow {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $app): bool => ($app === 'openregister' && $installed)
		);

		$service = new LoonrunFlowRepairService(
			new OpenRegisterAvailability($appManager),
			$container,
			new FlowGraphKeypaths(),
			$this->createMock(LoggerInterface::class)
		);

		return new RepublishLoonrunFlow($service, $logger);

	}//end step()

	/**
	 * Without OpenRegister the repair's skip is reported as one info line,
	 * nothing is looked up and nothing is logged as an error.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheSkipIsReported(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('error');

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')
			->with('Loonrun flow repair: skipped. OpenRegister is not installed or enabled.');
		$output->expects(self::never())->method('warning');

		$step = $this->step(false, $container, $logger);
		$step->run($output);

		self::assertSame(
			'Republish the humaniq Loonrun flow so approvals stop taking the rejected exit',
			$step->getName()
		);

	}//end testWithoutOpenRegisterTheSkipIsReported()

	/**
	 * An instance that never imported the flow reports that, and the flow
	 * lookup is scoped to this app.
	 *
	 * @return void
	 */
	public function testAFlowThatWasNeverImportedIsReportedAsSkipped(): void {
		$mapper = new class {
			/**
			 * @var list<array{0: string, 1: int}>
			 */
			public array $calls = [];

			/**
			 * @param string $app   The owning app.
			 * @param int    $limit The page size.
			 *
			 * @return array<int, object>
			 */
			public function findAllFlows(string $app, int $limit): array {
				$this->calls[] = [$app, $limit];
				return [];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Db\FlowMapper')->willReturn($mapper);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')
			->with('Loonrun flow repair: skipped. The shipped Loonrun flow is not imported on this instance.');
		$output->expects(self::never())->method('warning');

		$this->step(true, $container, $this->createMock(LoggerInterface::class))->run($output);

		self::assertSame([['humaniq', 500]], $mapper->calls);

	}//end testAFlowThatWasNeverImportedIsReportedAsSkipped()

	/**
	 * A repair that throws warns the operator, logs the error and returns:
	 * a broken flow store must not abort the rest of the upgrade.
	 *
	 * @return void
	 */
	public function testAFailingRepairWarnsAndLogsInsteadOfThrowing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('FlowMapper is not registered'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')
			->with('Humaniq: Loonrun flow repair failed', ['exception' => 'FlowMapper is not registered']);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::never())->method('info');
		$output->expects(self::once())->method('warning')
			->with('Could not repair the Loonrun flow: FlowMapper is not registered');

		$this->step(true, $container, $logger)->run($output);

	}//end testAFailingRepairWarnsAndLogsInsteadOfThrowing()

}//end class
