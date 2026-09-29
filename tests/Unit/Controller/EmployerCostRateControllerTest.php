<?php

/**
 * EmployerCostRateController Unit Tests
 *
 * @category Tests
 * @package  OCA\Humaniq\Tests\Unit\Controller
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

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\EmployerCostRateController;
use OCA\Humaniq\Service\CostRateAccess;
use OCA\Humaniq\Service\EmployeeCostRateService;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The cost-rate endpoint answers, refuses and — above all — does not leak.
 *
 * The assertion that matters most here is the IDOR one. This endpoint returns
 * a figure derived from an employee's salary, so an id the caller may not read
 * must be indistinguishable from an id that does not exist. That is ADR-005
 * Rule 3, and it is the shape gate-7 exists to catch: `#[NoAdminRequired]` on
 * a method with no per-object guard.
 *
 * @covers \OCA\Humaniq\Controller\EmployerCostRateController
 */
class EmployerCostRateControllerTest extends TestCase {

	/**
	 * A resolved cost rate, in the shape EmployeeCostRateService returns.
	 *
	 * @var array<string, mixed>
	 */
	private const RATE = [
		'totalCentsPerHour' => 6250,
		'wageCostCents' => 4500,
		'wageSource' => 'contract-proforma',
		'wageBasis' => 'monthly salary / contracted hours',
		'wageBaseBlendsOvertime' => false,
		'additions' => [
			['key' => 'overhead', 'centsPerHour' => 1750, 'source' => 'shillinq', 'basis' => 'GL overhead pool / billable hours'],
		],
	];

	/**
	 * Build the controller with a stubbed ObjectService.
	 *
	 * The ObjectService double declares OpenRegister's real `findAll(array $config, bool $_rbac, bool $_multitenancy)`
	 * signature: a double that accepted any arguments hid a lookup that could never run.
	 *
	 * @param mixed $employee The row ObjectService::find returns, or a Throwable to throw.
	 * @param EmployeeCostRateService|null $rates Optional cost-rate service double.
	 * @param array<int, array<string, mixed>> $contracts EmploymentContract rows the caller may read.
	 * @param CostRateAccess|null $access Optional access double (project-manager path).
	 *
	 * @return EmployerCostRateController The controller.
	 */
	private function controller(mixed $employee, ?EmployeeCostRateService $rates = null, array $contracts = [], ?CostRateAccess $access = null): EmployerCostRateController {
		$objectService = new class($employee, $contracts) {
			/**
			 * The schema last set.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param mixed $employee The stubbed row or Throwable.
			 * @param array<int, array<string, mixed>> $contracts The contract rows.
			 */
			public function __construct(
				private mixed $employee,
				private array $contracts,
			) {
			}

			/**
			 * @return mixed The stubbed employee.
			 *
			 * @throws \Throwable When the stub is a Throwable.
			 */
			public function find(...$args): mixed {
				if ($this->employee instanceof \Throwable) {
					throw $this->employee;
				}

				return $this->employee;
			}

			/**
			 * @param string $register The register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 * @param bool $_rbac Apply RBAC.
			 * @param bool $_multitenancy Apply multitenancy.
			 *
			 * @return array<int, array<string, mixed>> The contracts, for the EmploymentContract schema.
			 */
			public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array {
				return $this->schema === 'EmploymentContract' ? $this->contracts : [];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pm');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new EmployerCostRateController(
			$this->createMock(IRequest::class),
			$container,
			($rates ?? $this->createMock(EmployeeCostRateService::class)),
			$settings,
			($access ?? $this->access(null)),
			$session,
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * An access double: the contract choice is real, the project-manager reads are stubbed.
	 *
	 * @param array<string, mixed>|null $managed The employee a project manager is given, or null.
	 * @param array<string, mixed>|null $contract The contract the system read returns.
	 *
	 * @return CostRateAccess
	 */
	private function access(?array $managed, ?array $contract = null): CostRateAccess {
		$access = $this->getMockBuilder(CostRateAccess::class)
			->disableOriginalConstructor()
			->onlyMethods(['employeeManagedBy', 'contractFor'])
			->getMock();
		$access->method('employeeManagedBy')->willReturn($managed);
		$access->method('contractFor')->willReturn($contract);

		return $access;
	}

	/**
	 * The active contract the caller may read is found and handed to the service.
	 *
	 * @return void
	 */
	public function testTheActiveContractIsFoundAndCostedOn(): void {
		$contract = ['id' => 'c-1', 'employeeId' => 'emp-1', 'startDate' => '2026-01-01', 'hoursPerWeek' => 36];
		$rates = $this->createMock(EmployeeCostRateService::class);
		$rates->expects(self::once())
			->method('resolve')
			->with(self::anything(), self::identicalTo($contract), self::identicalTo('2026-08'), self::anything())
			->willReturn(self::RATE);

		$res = $this->controller(['id' => 'emp-1'], $rates, [['id' => 'c-0', 'employeeId' => 'emp-1', 'startDate' => '2020-01-01', 'endDate' => '2024-12-31'], $contract])
			->show(employeeId: 'emp-1', period: '2026-08');

		self::assertSame(Http::STATUS_OK, $res->getStatus());
	}

	/**
	 * A project manager whose salary view is empty gets the derived rate and nothing of the salary.
	 *
	 * @return void
	 */
	public function testAProjectManagerGetsTheDerivedRateWithoutTheSalary(): void {
		$full = ['id' => 'emp-1', 'grossMonthlySalary' => 4200];
		$contract = ['id' => 'c-1', 'employeeId' => 'emp-1', 'hoursPerWeek' => 36];
		$rates = $this->createMock(EmployeeCostRateService::class);
		$rates->method('resolve')->willReturnCallback(
			fn (array $employee, ?array $c = null): ?array => ($c === $contract && ($employee['grossMonthlySalary'] ?? null) === 4200) ? self::RATE : null
		);

		// The caller reads the employee with the salary stripped and no contract.
		$res = $this->controller(['id' => 'emp-1'], $rates, [], $this->access($full, $contract))
			->show(employeeId: 'emp-1', period: '2026-08');
		$body = $res->getData();

		self::assertSame(Http::STATUS_OK, $res->getStatus());
		self::assertSame('project-manager', $body['access']);
		self::assertSame(6250, $body['totalCentsPerHour']);
		self::assertSame(4500, $body['wageCostCents']);
		foreach (['grossMonthlySalary', 'wageBasis', 'wageSource', 'contract', 'hoursPerWeek'] as $hidden) {
			self::assertArrayNotHasKey($hidden, $body, $hidden . ' must not reach a project manager');
		}
		self::assertStringNotContainsString('4200', json_encode($body));
	}

	/**
	 * A project manager of an employee they cannot read at all still gets the rate.
	 *
	 * @return void
	 */
	public function testAProjectManagerGetsTheRateOfAnEmployeeOutsideTheirRead(): void {
		$rates = $this->createMock(EmployeeCostRateService::class);
		$rates->method('resolve')->willReturn(self::RATE);

		$res = $this->controller(new \RuntimeException('forbidden'), $rates, [], $this->access(['id' => 'emp-1'], ['id' => 'c-1']))
			->show(employeeId: 'emp-1', period: '2026-08');

		self::assertSame(Http::STATUS_OK, $res->getStatus());
		self::assertSame('project-manager', $res->getData()['access']);
	}

	/**
	 * A caller who manages no project of the employee keeps the 409.
	 *
	 * @return void
	 */
	public function testSomeoneWhoManagesNoProjectOfTheEmployeeKeepsTheConflict(): void {
		$rates = $this->createMock(EmployeeCostRateService::class);
		$rates->expects(self::once())->method('resolve')->willReturn(null);

		$res = $this->controller(['id' => 'emp-1'], $rates, [], $this->access(null, ['id' => 'c-1']))->show(employeeId: 'emp-1');

		self::assertSame(Http::STATUS_CONFLICT, $res->getStatus());
		self::assertArrayNotHasKey('totalCentsPerHour', $res->getData());
	}

	/**
	 * A resolvable employee yields the rate, echoing the identifying context.
	 *
	 * @return void
	 */
	public function testResolvesTheRateForAnAuthorisedEmployee(): void {
		$rates = $this->createMock(EmployeeCostRateService::class);
		$rates->method('resolve')->willReturn(self::RATE);

		$res = $this->controller(['id' => 'emp-1'], $rates)->show(employeeId: 'emp-1', period: '2026-08');
		$body = $res->getData();

		self::assertSame(Http::STATUS_OK, $res->getStatus());
		self::assertSame(6250, $body['totalCentsPerHour']);
		self::assertSame('emp-1', $body['employeeId']);
		self::assertSame('2026-08', $body['period']);
		self::assertSame('EUR', $body['currency'], 'a bare cents figure with no currency is not a money value');
	}

	/**
	 * An employee the caller cannot read is a 404, not a 403 — and carries no figure.
	 *
	 * This is the IDOR assertion. A 403 would confirm the id exists, and any
	 * body field leaking a wage would defeat the guard entirely, so both are
	 * checked.
	 *
	 * @return void
	 */
	public function testAnUnauthorisedEmployeeIsIndistinguishableFromAMissingOne(): void {
		$rates = $this->createMock(EmployeeCostRateService::class);
		$rates->expects(self::never())->method('resolve');

		// ObjectService throws for an id outside the caller's RBAC.
		$res = $this->controller(new \RuntimeException('forbidden'), $rates)->show(employeeId: 'emp-secret');

		self::assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
		self::assertStringNotContainsString(
			'forbidden',
			json_encode($res->getData()),
			'the refusal must not echo why the lookup failed'
		);
		foreach (['totalCentsPerHour', 'wageCostCents'] as $leak) {
			self::assertArrayNotHasKey($leak, $res->getData(), $leak . ' must never appear on a refusal');
		}
	}

	/**
	 * A missing employee is a 404 and the rate service is never reached.
	 *
	 * @return void
	 */
	public function testAMissingEmployeeIsNotCosted(): void {
		$rates = $this->createMock(EmployeeCostRateService::class);
		$rates->expects(self::never())->method('resolve');

		$res = $this->controller(null, $rates)->show(employeeId: 'nope');

		self::assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
	}

	/**
	 * An empty employeeId is refused before any lookup.
	 *
	 * @return void
	 */
	public function testAnEmptyEmployeeIdIsRefused(): void {
		$res = $this->controller(['id' => 'emp-1'])->show(employeeId: '  ');

		self::assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
	}

	/**
	 * No wage base is a 409, not a 404 and not a zero.
	 *
	 * The employee exists; what is missing is anything to cost the hour from.
	 * Returning 0 would be the dangerous answer — it reads as a free hour.
	 *
	 * @return void
	 */
	public function testNoWageBaseIsAConflictRatherThanAZeroRate(): void {
		$rates = $this->createMock(EmployeeCostRateService::class);
		$rates->method('resolve')->willReturn(null);

		$res = $this->controller(['id' => 'emp-1'], $rates)->show(employeeId: 'emp-1');

		self::assertSame(Http::STATUS_CONFLICT, $res->getStatus());
		self::assertArrayNotHasKey('totalCentsPerHour', $res->getData());
	}

	/**
	 * An indefensible composition is a 400, carrying the service's reason.
	 *
	 * The service throws InvalidArgumentException for an override with no
	 * reason, an addition with no basis, or overtime stacked on an
	 * overtime-blended base. Those are caller errors and must not surface as
	 * a 500.
	 *
	 * @return void
	 */
	public function testAnIndefensibleAdditionIsABadRequest(): void {
		$rates = $this->createMock(EmployeeCostRateService::class);
		$rates->method('resolve')->willThrowException(
			new \InvalidArgumentException('addition "overhead" has no basis')
		);

		$res = $this->controller(['id' => 'emp-1'], $rates)->show(
			employeeId: 'emp-1',
			additions: [['key' => 'overhead', 'centsPerHour' => 100]]
		);

		self::assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		self::assertStringContainsString('no basis', (string)$res->getData()['error']);
	}

	/**
	 * Caller-supplied additions reach the service unaltered.
	 *
	 * The whole point of the endpoint is that Shillinq contributes the
	 * ledger-derived half, so silently dropping them would produce a
	 * confidently wrong — and always too low — cost.
	 *
	 * @return void
	 */
	public function testCallerAdditionsArePassedThrough(): void {
		$sent = [['key' => 'overhead', 'centsPerHour' => 1750, 'source' => 'shillinq', 'basis' => 'GL pool']];

		$rates = $this->createMock(EmployeeCostRateService::class);
		$rates->expects(self::once())
			->method('resolve')
			->with(
				self::anything(),
				self::anything(),
				self::anything(),
				self::identicalTo($sent)
			)
			->willReturn(self::RATE);

		$this->controller(['id' => 'emp-1'], $rates)->show(employeeId: 'emp-1', additions: $sent);
	}
}
