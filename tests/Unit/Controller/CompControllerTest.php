<?php

/**
 * CompController tests.
 *
 * Effectuating a compensation adjustment: only an approved adjustment the caller can read is effectuated; a refused one is a bad request and a failed one a server error.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\CompController;
use OCA\Humaniq\Service\CompAdjustmentService;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The effectuate endpoint refuses what it may not effectuate and reports the outcome.
 *
 * @covers \OCA\Humaniq\Controller\CompController
 */
class CompControllerTest extends TestCase {

	/** @var CompAdjustmentService&MockObject */
	private CompAdjustmentService $adjustments;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var array<int, array<string, string>> */
	private array $finds = [];

	protected function setUp(): void {
		$this->adjustments = $this->createMock(CompAdjustmentService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * The controller over an object service that answers, or throws, for a find.
	 *
	 * @param mixed $found The find answer, or a Throwable to throw.
	 *
	 * @return CompController
	 */
	private function controller(mixed $found): CompController {
		$finds = &$this->finds;
		$objects = new class($found, $finds) {
			/** @var array<int, array<string, string>> */
			private array $finds;

			/**
			 * @param mixed                             $found The answer.
			 * @param array<int, array<string, string>> $finds The record.
			 */
			public function __construct(private mixed $found, array &$finds) {
				$this->finds = &$finds;
			}

			public function find(string $id, string $register, string $schema): mixed {
				$this->finds[] = compact('id', 'register', 'schema');
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return $this->found;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getRegisterSlug')->willReturn('humaniq');

		return new CompController($this->createMock(IRequest::class), $container, $this->adjustments, $settings, $this->logger);
	}

	public function testAMissingIdIsABadRequest(): void {
		$this->adjustments->expects($this->never())->method('effectuateOne');

		$response = $this->controller(['status' => 'approved'])->effectuate(' ');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'adjustmentId is verplicht.'], $response->getData());
	}

	public function testAnAdjustmentThatCannotBeReadIsNotFound(): void {
		$this->adjustments->expects($this->never())->method('effectuateOne');
		$this->logger->expects($this->once())->method('warning')->with(
			'CompController: a comp adjustment lookup failed and is answered as not found.',
			['exceptionClass' => RuntimeException::class]
		);

		$response = $this->controller(new RuntimeException('no access'))->effectuate('ca-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Aanpassing niet gevonden.'], $response->getData());
		self::assertSame(['id' => 'ca-1', 'register' => 'humaniq', 'schema' => 'CompAdjustment'], $this->finds[0]);
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(null)->effectuate('ca-1')->getStatus());
	}

	public function testOnlyAnApprovedAdjustmentIsEffectuated(): void {
		$this->adjustments->expects($this->never())->method('effectuateOne');

		$response = $this->controller(['status' => 'draft'])->effectuate('ca-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'Aanpassing heeft status "draft" — alleen goedgekeurde aanpassingen kunnen worden geëffectueerd.'], $response->getData());
	}

	public function testAnApprovedAdjustmentIsEffectuated(): void {
		$this->adjustments->expects($this->once())->method('effectuateOne')->with('ca-1')->willReturn(['status' => 'effectuated']);

		$response = $this->controller(['status' => 'approved'])->effectuate(' ca-1 ');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['status' => 'effectuated'], $response->getData());
	}

	public function testARefusedOrFailedEffectuationIsReportedAsSuch(): void {
		$this->adjustments->method('effectuateOne')->willReturnOnConsecutiveCalls(
			['status' => 'refused-no-contract'],
			['status' => 'failed'],
			['note' => 'no status'],
		);
		$controller = $this->controller(['status' => 'approved']);

		self::assertSame(Http::STATUS_BAD_REQUEST, $controller->effectuate('ca-1')->getStatus());
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $controller->effectuate('ca-1')->getStatus());
		self::assertSame(Http::STATUS_OK, $controller->effectuate('ca-1')->getStatus());
	}
}
