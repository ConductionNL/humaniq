<?php

/**
 * CostRateAccess caller-read tests.
 *
 * The reads that run as the caller: the employee, and the contract running
 * in a period, each answered as nothing when OpenRegister is missing, the
 * read fails or the row is not there. Also the system-side contract read,
 * the guards of the manager check and a planning register that cannot be
 * read.
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

use OCA\Humaniq\Service\CostRateAccess;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\Humaniq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reads as the caller answer the row or nothing, never an exception.
 *
 * @covers \OCA\Humaniq\Service\CostRateAccess
 */
class CostRateAccessCallerReadsTest extends TestCase {

	/** @var RecordStoreInterface&MockObject */
	private RecordStoreInterface $gateway;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/**
	 * Calls the object service received.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	protected function setUp(): void {
		$this->gateway = $this->createMock(RecordStoreInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * The access over an object service answering find and findAll, or throwing.
	 *
	 * @param mixed  $find         What find answers, or a Throwable.
	 * @param mixed  $findAll      What findAll answers, or a Throwable.
	 * @param bool   $openRegister Whether OpenRegister is available.
	 * @param string $uid          The caller.
	 *
	 * @return CostRateAccess
	 */
	private function access(mixed $find = null, mixed $findAll = [], bool $openRegister = true, string $uid = ''): CostRateAccess {
		$calls = &$this->calls;
		$objects = new class($find, $findAll, $calls) {
			/** @var array<int, array<int, mixed>> */
			private array $calls;

			/**
			 * @param mixed                         $find    The find answer.
			 * @param mixed                         $findAll The findAll answer.
			 * @param array<int, array<int, mixed>> $calls   The record.
			 */
			public function __construct(private mixed $find, private mixed $findAll, array &$calls) {
				$this->calls = &$calls;
			}

			private function answer(mixed $answer): mixed {
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}

			public function find(string $id, string $register, string $schema): mixed {
				$this->calls[] = ['find', $id, $register, $schema];
				return $this->answer($this->find);
			}

			public function setRegister(string $register): static {
				$this->calls[] = ['setRegister', $register];
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->calls[] = ['setSchema', $schema];
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->calls[] = ['findAll', $config, $_rbac];
				return $this->answer($this->findAll);
			}
		};

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($openRegister);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== '') {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new CostRateAccess($apps, $container, $this->gateway, $settings, $session, $this->logger);
	}

	public function testTheEmployeeIsReadAsTheCaller(): void {
		$entity = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'emp-1', 'name' => 'Jan'];
			}
		};

		self::assertSame(['id' => 'emp-1', 'name' => 'Jan'], $this->access(find: $entity)->employeeForCaller('emp-1'));
		self::assertSame(['find', 'emp-1', 'humaniq', 'Employee'], $this->calls[0]);
		self::assertNull($this->access(find: null)->employeeForCaller('emp-1'));
	}

	public function testAnEmployeeTheCallerCannotReadIsNothing(): void {
		$this->logger->expects($this->exactly(2))->method('info')->with($this->stringStartsWith('humaniq cost rate: employee emp-1 not retrievable: '));

		self::assertNull($this->access(find: new RuntimeException('forbidden'))->employeeForCaller('emp-1'));
		$this->calls = [];
		self::assertNull($this->access(find: ['id' => 'emp-1'], openRegister: false)->employeeForCaller('emp-1'));
		self::assertSame([], $this->calls, 'without OpenRegister nothing is asked');
	}

	public function testTheContractRunningInThePeriodIsReadAsTheCaller(): void {
		$rows = [
			['id' => 'c-old', 'employeeId' => 'emp-1', 'startDate' => '2024-01-01', 'endDate' => '2025-12-31'],
			['id' => 'c-now', 'employeeId' => 'emp-1', 'startDate' => '2026-01-01', 'endDate' => ''],
			'not a row',
		];

		$contract = $this->access(findAll: $rows)->contractForCaller(['id' => 'emp-1'], '2026-03');

		self::assertSame('c-now', $contract['id']);
		self::assertSame(
			[['setRegister', 'humaniq'], ['setSchema', 'EmploymentContract'], ['findAll', ['limit' => 100, 'filters' => ['employeeId' => 'emp-1']], true]],
			$this->calls
		);
	}

	public function testNoContractIsReadForAnEmployeeWithoutIdOrOnAFailure(): void {
		$this->logger->expects($this->once())->method('info')->with('humaniq cost rate: no contract for emp-1: index down');

		self::assertNull($this->access(findAll: [['employeeId' => 'emp-1']])->contractForCaller([], '2026-03'));
		self::assertSame([], $this->calls);
		self::assertNull($this->access(findAll: new RuntimeException('index down'))->contractForCaller(['id' => 'emp-1'], '2026-03'));
		self::assertNull($this->access(findAll: 'not a list')->contractForCaller(['id' => 'emp-1'], '2026-03'));
	}

	public function testTheContractIsReadSystemSideForAPeriod(): void {
		$this->gateway->expects($this->once())->method('findFiltered')->with('EmploymentContract', ['employeeId' => 'emp-2'])->willReturn([
			['id' => 'c-1', 'employeeId' => 'emp-2', 'startDate' => '2026-04-01'],
		]);

		self::assertNull($this->access()->contractFor('emp-2', '2026-03'), 'a contract starting after the period does not run in it');
	}

	public function testTheManagerCheckNeedsACallerAndAnEmployee(): void {
		$this->gateway->expects($this->never())->method('findObjectData');

		self::assertNull($this->access()->employeeManagedByCaller('emp-1'), 'nobody signed in');
		self::assertNull($this->access()->employeeManagedBy('owner', ' '));
	}

	public function testAnUnreadablePlanningRegisterMakesNobodyAManager(): void {
		$this->logger->expects($this->once())->method('info')->with('humaniq: could not read planninq projects: planninq down');
		$this->gateway->expects($this->never())->method('findObjectData');

		self::assertNull($this->access(findAll: new RuntimeException('planninq down'), uid: 'owner')->employeeManagedByCaller('emp-1'));
	}

	public function testAnEmployeeThatCannotBeFoundIsManagedByNobody(): void {
		$this->gateway->method('findObjectData')->with('emp-9', 'Employee')->willReturn(null);
		$this->gateway->expects($this->never())->method('findFiltered');

		self::assertNull($this->access(findAll: [['id' => 'p-1', 'owner' => 'owner', 'members' => []]])->employeeManagedBy('owner', 'emp-9'));
	}
}
