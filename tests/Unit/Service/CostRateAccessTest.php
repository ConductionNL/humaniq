<?php

/**
 * CostRateAccess Unit Tests
 *
 * @category Tests
 * @package  OCA\Humaniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://humaniq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CostRateAccess;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A project manager is recognised from planninq's project owner and members,
 * or from the employee's own time entries, and nobody else is.
 *
 * @covers \OCA\Humaniq\Service\CostRateAccess
 */
class CostRateAccessTest extends TestCase {

	/**
	 * The employee in these tests.
	 *
	 * @var array<string, mixed>
	 */
	private const EMPLOYEE = ['id' => 'emp-1', 'nextcloudUserId' => 'jansen', 'grossMonthlySalary' => 4200];

	/**
	 * Build the access service.
	 *
	 * @param array<int, array<string, mixed>> $projects    Planninq projects the ObjectService double holds.
	 * @param array<int, array<string, mixed>> $timeEntries TimeEntry rows of the employee.
	 * @param bool                             $planninq    Whether planninq is installed.
	 *
	 * @return CostRateAccess
	 */
	private function access(array $projects, array $timeEntries = [], bool $planninq = true): CostRateAccess {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(fn (string $app): bool => $app === 'planninq' && $planninq);

		$objects = new class($projects) {
			/**
			 * The register and schema last set.
			 *
			 * @var array<int, string|null>
			 */
			public array $context = [null, null];

			/**
			 * @param array<int, array<string, mixed>> $projects The rows.
			 */
			public function __construct(
				private array $projects,
			) {
			}

			/**
			 * @param string $register The register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->context[0] = $register;
				return $this;
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->context[1] = $schema;
				return $this;
			}

			/**
			 * The real OpenRegister signature: a config array, then the two flags.
			 *
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         Apply RBAC.
			 * @param bool                 $_multitenancy Apply multitenancy.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array {
				if ($this->context !== ['planninq', 'project'] || $_rbac === true) {
					return [];
				}

				return $this->projects;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('findObjectData')->willReturnCallback(
			fn (string $id, string $schema): ?array => ($id === 'emp-1' && $schema === 'Employee') ? self::EMPLOYEE : null
		);
		$gateway->method('findFiltered')->willReturnCallback(
			fn (string $schema, array $filters): array => ($schema === 'TimeEntry' && $filters === ['employeeId' => 'emp-1']) ? $timeEntries : []
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pm');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		return new CostRateAccess($apps, $container, $gateway, $settings, $session, $this->createMock(LoggerInterface::class));
	}

	/**
	 * The owner of a project the employee is a member of manages the employee.
	 *
	 * @return void
	 */
	public function testTheOwnerOfAProjectWithTheEmployeeAsMemberManagesThem(): void {
		$employee = $this->access([['id' => 'p-1', 'owner' => 'pm', 'members' => ['jansen']]])->employeeManagedByCaller('emp-1');

		self::assertSame('emp-1', $employee['id'] ?? null);
	}

	/**
	 * A time entry of the employee on the manager's project is enough.
	 *
	 * @return void
	 */
	public function testATimeEntryOnTheProjectMakesTheOwnerTheManager(): void {
		$access = $this->access(
			[['id' => 'p-2', 'owner' => 'pm', 'members' => []]],
			[['employeeId' => 'emp-1', 'projectId' => 'p-2']]
		);

		self::assertNotNull($access->employeeManagedBy('pm', 'emp-1'));
	}

	/**
	 * Owning a project the employee does not work on gives nothing.
	 *
	 * @return void
	 */
	public function testAProjectTheEmployeeDoesNotWorkOnGivesNothing(): void {
		$access = $this->access(
			[['id' => 'p-3', 'owner' => 'pm', 'members' => ['someone-else']]],
			[['employeeId' => 'emp-1', 'projectId' => 'p-other']]
		);

		self::assertNull($access->employeeManagedBy('pm', 'emp-1'));
	}

	/**
	 * A member who does not own the project is not its manager, whatever the filter returned.
	 *
	 * @return void
	 */
	public function testAMemberOfSomeoneElsesProjectIsNotItsManager(): void {
		$access = $this->access([['id' => 'p-4', 'owner' => 'boss', 'members' => ['jansen', 'pm']]]);

		self::assertNull($access->employeeManagedBy('pm', 'emp-1'));
	}

	/**
	 * Without planninq nobody is a project manager.
	 *
	 * @return void
	 */
	public function testWithoutPlanninqNobodyIsAProjectManager(): void {
		$access = $this->access([['id' => 'p-1', 'owner' => 'pm', 'members' => ['jansen']]], [], false);

		self::assertNull($access->employeeManagedBy('pm', 'emp-1'));
	}

	/**
	 * The contract that runs in the period is chosen; an ended one and another employee's are not.
	 *
	 * @return void
	 */
	public function testTheContractRunningInThePeriodIsPicked(): void {
		$rows = [
			['id' => 'c-old', 'employeeId' => 'emp-1', 'startDate' => '2020-01-01', 'endDate' => '2025-12-31'],
			['id' => 'c-other', 'employeeId' => 'emp-2', 'startDate' => '2026-01-01'],
			['id' => 'c-now', 'employeeId' => 'emp-1', 'startDate' => '2026-01-01', 'endDate' => null],
			['id' => 'c-later', 'employeeId' => 'emp-1', 'startDate' => '2026-10-01'],
		];

		$picked = $this->access([])->pickActive($rows, 'emp-1', '2026-09');

		self::assertSame('c-now', $picked['id'] ?? null);
		self::assertNull($this->access([])->pickActive($rows, 'emp-1', '2019-06'));
	}
}
