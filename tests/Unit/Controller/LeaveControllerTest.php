<?php

/**
 * LeaveController tests.
 *
 * Settling a leave buy/sell transaction: only an approved transaction the caller can read is settled, and a failed settlement is a server error.
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

use OCA\Humaniq\Controller\LeaveController;
use OCA\Humaniq\Service\LeaveBuySellSettlementService;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The settle endpoint refuses what it may not settle and reports the settlement.
 *
 * @covers \OCA\Humaniq\Controller\LeaveController
 */
class LeaveControllerTest extends TestCase {

	/** @var LeaveBuySellSettlementService&MockObject */
	private LeaveBuySellSettlementService $settlement;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var array<int, array<string, string>> */
	private array $finds = [];

	protected function setUp(): void {
		$this->settlement = $this->createMock(LeaveBuySellSettlementService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * The controller over an object service that answers, or throws, for a find.
	 *
	 * @param mixed $found       The find answer, or a Throwable to throw.
	 * @param bool  $openRegister Whether OpenRegister is available.
	 *
	 * @return LeaveController
	 */
	private function controller(mixed $found, bool $openRegister = true): LeaveController {
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
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($openRegister);
		$settings->method('getRegisterSlug')->willReturn('humaniq');

		return new LeaveController($this->createMock(IRequest::class), $container, $this->settlement, $settings, $this->logger);
	}

	public function testAMissingIdIsABadRequest(): void {
		$this->settlement->expects($this->never())->method('settle');

		foreach ([null, '  '] as $id) {
			$response = $this->controller(['status' => 'approved'])->settle($id);
			self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			self::assertSame(['error' => 'transactionId is verplicht.'], $response->getData());
		}

		self::assertSame([], $this->finds);
	}

	public function testATransactionThatCannotBeReadIsNotFound(): void {
		$this->settlement->expects($this->never())->method('settle');
		$this->logger->expects($this->once())->method('warning')->with(
			'LeaveController: a leave transaction lookup failed and is answered as not found.',
			['exceptionClass' => RuntimeException::class]
		);

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(null)->settle('lt-1')->getStatus());
		$response = $this->controller(new RuntimeException('no access'))->settle('lt-1');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Transactie niet gevonden.'], $response->getData());
		self::assertSame(['id' => 'lt-1', 'register' => 'humaniq', 'schema' => 'LeaveTransaction'], $this->finds[0]);
	}

	public function testWithoutOpenRegisterTheTransactionIsNotFound(): void {
		$this->settlement->expects($this->never())->method('settle');

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(['status' => 'approved'], false)->settle('lt-1')->getStatus());
		self::assertSame([], $this->finds);
	}

	public function testOnlyAnApprovedTransactionIsSettled(): void {
		$this->settlement->expects($this->never())->method('settle');

		$response = $this->controller(['status' => 'submitted'])->settle('lt-1');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'Transactie heeft status "submitted" — alleen goedgekeurde transacties kunnen worden verrekend.'], $response->getData());

		self::assertSame(
			['error' => 'Transactie heeft status "onbekend" — alleen goedgekeurde transacties kunnen worden verrekend.'],
			$this->controller([])->settle('lt-1')->getData()
		);
	}

	public function testAnApprovedTransactionIsSettledByItsTrimmedId(): void {
		$entity = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['status' => 'approved'];
			}
		};
		$this->settlement->expects($this->once())->method('settle')->with('lt-1')->willReturn(['status' => 'settled', 'hours' => 8]);

		$response = $this->controller($entity)->settle(' lt-1 ');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['status' => 'settled', 'hours' => 8], $response->getData());
	}

	public function testAFailedSettlementIsAServerError(): void {
		$this->settlement->method('settle')->willReturn(['status' => 'failed', 'error' => 'payroll locked']);

		$response = $this->controller(['status' => 'approved'])->settle('lt-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['status' => 'failed', 'error' => 'payroll locked'], $response->getData());
	}
}
