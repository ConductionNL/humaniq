<?php

/**
 * EmployerCostRateController Unit Tests
 *
 * @category Tests
 * @package  OCA\Hrmq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://hrmq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Controller;

use OCA\Hrmq\Controller\EmployerCostRateController;
use OCA\Hrmq\Service\EmployeeCostRateService;
use OCA\Hrmq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
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
 * @covers \OCA\Hrmq\Controller\EmployerCostRateController
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
	 * @param mixed $employee The row ObjectService::find returns, or a Throwable to throw.
	 * @param EmployeeCostRateService|null $rates Optional cost-rate service double.
	 *
	 * @return EmployerCostRateController The controller.
	 */
	private function controller(mixed $employee, ?EmployeeCostRateService $rates = null): EmployerCostRateController {
		$objectService = new class($employee) {
			/**
			 * @param mixed $employee The stubbed row or Throwable.
			 */
			public function __construct(
				private mixed $employee,
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
			 * @return array<int, mixed> No contracts; the override path is used instead.
			 */
			public function findAll(...$args): array {
				return [];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		return new EmployerCostRateController(
			$this->createMock(IRequest::class),
			$container,
			($rates ?? $this->createMock(EmployeeCostRateService::class)),
			$settings,
			$this->createMock(LoggerInterface::class)
		);
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
