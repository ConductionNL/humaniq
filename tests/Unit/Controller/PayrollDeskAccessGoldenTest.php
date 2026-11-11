<?php

/**
 * Golden test for the HR-or-payroll caller check and the RBAC-readable
 * administration check of `PayrollHandoffController::compile()` and
 * `ThirdPartyReportController::assemble()`.
 *
 * Pins the allowed AND refused outcomes of both checks over every branch: no
 * signed-in user, an employee, HR, payroll; OpenRegister unavailable, an
 * administration the caller cannot read, a lookup that fails (with its exact
 * log line), and a readable administration.
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
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\PayrollHandoffController;
use OCA\Humaniq\Controller\ThirdPartyReportController;
use OCA\Humaniq\Service\CallerContext;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\SalaryBureauExchangeService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\ThirdPartyPaymentServices;
use OCA\Humaniq\Service\ThirdPartyReportService;
use OCA\Humaniq\Service\ThirdPartyStatementService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Allowed and refused outcomes of the two payroll-desk endpoints.
 */
class PayrollDeskAccessGoldenTest extends TestCase {

	/**
	 * The rows the fake ObjectService answers for `hrAdministration`, or
	 * null to make the lookup throw.
	 *
	 * @var list<array<string, mixed>>|null
	 */
	private ?array $administrations = [['administrationId' => 'ADM-006']];

	/**
	 * Whether OpenRegister reports itself available.
	 *
	 * @var bool
	 */
	private bool $available = true;

	/**
	 * Every logger warning, as `[message, context]`.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * Both endpoints, as `[name, call]`; each call returns the status and
	 * whether the service behind the guard ran.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function endpoints(): array {
		return ['handoff compile' => ['PayrollHandoffController'], 'third-party assemble' => ['ThirdPartyReportController']];

	}//end endpoints()

	/**
	 * The caller check: only HR or payroll pass, a missing user never does.
	 *
	 * @param string $endpoint The controller under test.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testOnlyHrOrPayrollPassTheCallerCheck(string $endpoint): void {
		$this->assertSame([403, false, null], $this->call($endpoint, null, [false, false]));
		$this->assertSame([403, false, null], $this->call($endpoint, null, [true, true]));
		$this->assertSame([403, false, null], $this->call($endpoint, 'emp-1', [false, false]));
		$this->assertSame([200, true, 'hr-1'], $this->call($endpoint, 'hr-1', [true, false]));
		$this->assertSame([200, true, 'pay-1'], $this->call($endpoint, 'pay-1', [false, true]));
		$this->assertSame([200, true, 'both-1'], $this->call($endpoint, 'both-1', [true, true]));
		$this->assertSame([], $this->warnings);

	}//end testOnlyHrOrPayrollPassTheCallerCheck()

	/**
	 * The administration check: unavailable OpenRegister, an unreadable
	 * administration and a failed lookup are 404 before the service runs.
	 *
	 * @param string $endpoint The controller under test.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testOnlyAReadableAdministrationPassesTheAdministrationCheck(string $endpoint): void {
		$this->available = false;
		$this->assertSame([404, false, null], $this->call($endpoint, 'hr-1', [true, false]));

		$this->available = true;
		$this->administrations = [['administrationId' => 'ADM-001']];
		$this->assertSame([404, false, null], $this->call($endpoint, 'hr-1', [true, false]));
		$this->assertSame([], $this->warnings);

		$this->administrations = null;
		$this->assertSame([404, false, null], $this->call($endpoint, 'hr-1', [true, false]));
		$this->assertSame(
			[[$endpoint . ': an administration lookup failed and is answered as not readable.', ['exceptionClass' => \RuntimeException::class]]],
			$this->warnings
		);

	}//end testOnlyAReadableAdministrationPassesTheAdministrationCheck()

	/**
	 * Call the endpoint for ADM-006 as one caller.
	 *
	 * @param string            $endpoint The controller under test.
	 * @param string|null       $uid      The signed-in user, null for none.
	 * @param array{0: bool, 1: bool} $roles Whether the caller is HR and payroll.
	 *
	 * @return array{0: int, 1: bool, 2: string|null} Status, whether the service ran, and the uid it ran as.
	 */
	private function call(string $endpoint, ?string $uid, array $roles): array {
		$ran = new \ArrayObject();
		$caller = $this->caller($uid, $roles);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturnCallback(fn (): bool => $this->available);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$record = static function (string $administrationId, mixed $period, string $actor) use ($ran): array {
			$ran['uid'] = $actor;
			return [];
		};

		if ($endpoint === 'PayrollHandoffController') {
			$service = $this->createMock(SalaryBureauExchangeService::class);
			$service->method('compile')->willReturnCallback(static fn (string $a, string $p, string $u): array => $record($a, $p, $u) + ['status' => 'compiled']);
			$response = (new PayrollHandoffController($this->createMock(IRequest::class), $settings, $service, $caller))->compile('ADM-006', '2026-05');
		} else {
			$reports = $this->createMock(ThirdPartyReportService::class);
			$reports->method('assemble')->willReturnCallback(static fn (string $a, int $y, string $u): array => ['status' => 'assembled'] + $record($a, $y, $u));
			$payments = new ThirdPartyPaymentServices($reports, $this->createMock(ThirdPartyStatementService::class));
			$response = (new ThirdPartyReportController($this->createMock(IRequest::class), $settings, $payments, $caller))->assemble('ADM-006', '2026');
		}

		return [$response->getStatus(), isset($ran['uid']), ($ran['uid'] ?? null)];

	}//end call()

	/**
	 * The caller context over a fake session, roles, ObjectService and logger.
	 *
	 * @param string|null             $uid   The signed-in user, null for none.
	 * @param array{0: bool, 1: bool} $roles Whether the caller is HR and payroll.
	 *
	 * @return CallerContext
	 */
	private function caller(?string $uid, array $roles): CallerContext {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$humaniqRoles = $this->createMock(HumaniqRoles::class);
		$humaniqRoles->method('isHr')->willReturnCallback(static fn (?string $who): bool => $who !== null && $roles[0]);
		$humaniqRoles->method('isPayroll')->willReturnCallback(static fn (?string $who): bool => $who !== null && $roles[1]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects());
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->warnings[] = [$message, $context];
		});

		return new CallerContext($session, $humaniqRoles, $container, $logger);

	}//end caller()

	/**
	 * A fake ObjectService answering the administrations filter, or throwing.
	 *
	 * @return object
	 */
	private function objects(): object {
		$test = $this;
		return new class($test) {
			/**
			 * @param PayrollDeskAccessGoldenTest $test The test holding the rows.
			 */
			public function __construct(private readonly PayrollDeskAccessGoldenTest $test) {
			}//end __construct()

			/**
			 * @param mixed $register The register.
			 *
			 * @return self
			 */
			public function setRegister(mixed $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param mixed $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(mixed $schema): self {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return list<array<string, mixed>>
			 *
			 * @throws \RuntimeException When the test makes the lookup fail.
			 */
			public function findAll(array $config = []): array {
				return $this->test->administrationsMatching((array)($config['filters'] ?? []));
			}//end findAll()
		};

	}//end objects()

	/**
	 * The administrations matching a filter, throwing when the test makes the lookup fail.
	 *
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws \RuntimeException When the lookup is set to fail.
	 */
	public function administrationsMatching(array $filters): array {
		if ($this->administrations === null) {
			throw new \RuntimeException('lookup failed');
		}

		return array_values(array_filter($this->administrations, static fn (array $row): bool => array_diff_assoc($filters, $row) === []));

	}//end administrationsMatching()

}//end class
