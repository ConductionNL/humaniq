<?php

/**
 * Unit tests for PayrollController::proforma().
 *
 * Pins the proforma-payslip endpoint contract (design.md D4): an HR caller
 * whose RBAC can resolve the hrmq payroll register/schema receives the full
 * breakdown, a caller whose RBAC cannot resolve it gets HTTP 404 (unknown and
 * unauthorized collapse to the same status — no capability leak), and
 * malformed input (non-numeric gross) is refused with HTTP 400 before any
 * computation. Drives the controller through a fake ObjectService double (a
 * fake collaborator, not a fake of the logic under test) since the real
 * OpenRegister ObjectService is a sibling-app dependency not available in
 * this standalone suite — the fake's `findAll()` either succeeds (RBAC ok)
 * or throws (RBAC denies/register unresolvable), exactly like
 * `authorizeProformaAccess()` expects.
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
 * @spec openspec/specs/proforma-payslip/spec.md#REQ-PRO-002
 * @spec openspec/specs/proforma-payslip/spec.md#REQ-PRO-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Service\EmployerPremiumSettings;
use OCA\Humaniq\Service\PayrollRunServices;
use OCA\Humaniq\Service\CallerContext;
use OCA\Humaniq\Controller\PayrollController;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\PayrollMutationService;
use OCA\Humaniq\Service\PayrollRunService;
use OCA\Humaniq\Service\ProformaPayslipService;
use OCA\Humaniq\Service\RetroAdjustmentService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\WkrService;
use OCA\Humaniq\Tests\Unit\Support\NextcloudDispatch;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PayrollController::proforma().
 *
 * @spec openspec/specs/proforma-payslip/spec.md#REQ-PRO-002
 * @spec openspec/specs/proforma-payslip/spec.md#REQ-PRO-004
 */
class PayrollControllerProformaTest extends TestCase {

	/**
	 * Every capability probe of the current test: register, schema, query.
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public array $probes = [];

	/**
	 * REQ-PRO-004 "An HR caller reaches the simulation" scenario.
	 *
	 * @return void
	 */
	public function testAuthorizedCallerReceivesTheFullBreakdown(): void {
		$params = ['gross' => 3800, 'table' => 'wit', 'dateOfBirth' => '1990-04-12', 'period' => '2026-02', 'aof' => 'laag', 'whk' => 1.52];

		$response = NextcloudDispatch::call($this->buildController(rbacAllowed: true, params: $params), 'proforma', $params);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(3081.17, $data['nettoPay']);
		$this->assertArrayHasKey('loonheffing', $data);
		$this->assertArrayHasKey('werknemersverzekeringen', $data);

	}//end testAuthorizedCallerReceivesTheFullBreakdown()

	/**
	 * REQ-PRO-004 "A non-HR caller cannot reach the engine" scenario: RBAC
	 * cannot resolve the payroll register -> 404, no calculation performed
	 * (asserted by the response carrying no breakdown fields).
	 *
	 * @return void
	 */
	public function testUnauthorizedCallerReceives404(): void {
		$params = ['gross' => 3800, 'table' => 'wit', 'period' => '2026-02'];

		$response = NextcloudDispatch::call($this->buildController(rbacAllowed: false, params: $params), 'proforma', $params);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertArrayNotHasKey('nettoPay', $response->getData());

	}//end testUnauthorizedCallerReceives404()

	/**
	 * REQ-PRO-002 "Malformed input is refused" scenario: RBAC passes but the
	 * gross is non-numeric -> 400, nothing computed.
	 *
	 * @return void
	 */
	public function testMalformedGrossReturns400(): void {
		$params = ['gross' => 'n/a'];

		$response = NextcloudDispatch::call($this->buildController(rbacAllowed: true, params: $params), 'proforma', $params);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
		$this->assertArrayNotHasKey('nettoPay', $response->getData());

	}//end testMalformedGrossReturns400()

	/**
	 * A string parameter of another type is the dispatcher's bare 400, before
	 * the capability probe runs.
	 *
	 * @return void
	 */
	public function testNonStringTableIsABare400BeforeTheProbe(): void {
		$params = ['gross' => 3800, 'table' => ['wit'], 'period' => '2026-02'];

		$response = NextcloudDispatch::call($this->buildController(rbacAllowed: true, params: $params), 'proforma', $params);

		$this->assertSame(Response::class, $response::class);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame([], $this->probes);

	}//end testNonStringTableIsABare400BeforeTheProbe()

	/**
	 * REQ-PRO-004: the capability probe runs for the session, never for a
	 * userId in the request. A forged userId of a payroll administrator,
	 * offered through getParam() and getParams(), neither opens a register
	 * the session's RBAC refuses nor narrows the probe's query.
	 *
	 * @return void
	 */
	public function testForgedUserIdDoesNotOpenTheProbe(): void {
		$params = ['gross' => 3800, 'table' => 'wit', 'period' => '2026-02', 'userId' => 'admin', 'owner' => 'admin'];

		$response = NextcloudDispatch::call($this->buildController(rbacAllowed: false, params: $params), 'proforma', $params);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([['humaniq', 'PayrollRun', ['limit' => 1]]], $this->probes);

	}//end testForgedUserIdDoesNotOpenTheProbe()

	/**
	 * REQ-PRO-004: with the session's RBAC allowing the probe, a forged
	 * userId changes neither the probe nor the breakdown.
	 *
	 * @return void
	 */
	public function testForgedUserIdLeavesTheAllowedProbeAlone(): void {
		$params = ['gross' => 3800, 'table' => 'wit', 'period' => '2026-02', 'userId' => 'admin'];

		$response = NextcloudDispatch::call($this->buildController(rbacAllowed: true, params: $params), 'proforma', $params);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['humaniq', 'PayrollRun', ['limit' => 1]]], $this->probes);
		$this->assertArrayNotHasKey('userId', $response->getData()['input']);

	}//end testForgedUserIdLeavesTheAllowedProbeAlone()

	/**
	 * Build a `PayrollController` with a fake container-resolved
	 * ObjectService whose `findAll()` either succeeds (RBAC ok) or throws
	 * (RBAC denies / register unresolvable). The session user is `mila`;
	 * only `admin` is an administrator, so a check run for a forged userId
	 * would pass where the session's would not.
	 *
	 * @param bool                 $rbacAllowed Whether the fake ObjectService allows the capability probe.
	 * @param array<string, mixed> $params      The request parameters, for getParam() and getParams().
	 *
	 * @return PayrollController
	 */
	private function buildController(bool $rbacAllowed, array $params): PayrollController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (isset($params[$key]) === true ? $params[$key] : $default)
		);
		$request->method('getParams')->willReturn($params);

		$objectService = new class($rbacAllowed, $this) {

			/**
			 * The register slug of the probe.
			 *
			 * @var string
			 */
			private string $register = '';

			/**
			 * The schema slug of the probe.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param bool                         $allowed Whether findAll() should succeed.
			 * @param PayrollControllerProformaTest $test    The test that records the probes.
			 */
			public function __construct(
				private readonly bool $allowed,
				private readonly PayrollControllerProformaTest $test,
			) {
			}

			/**
			 * @param string $register The register slug; chainable.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->register = $register;
				return $this;
			}

			/**
			 * @param string $schema The schema slug; chainable.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $options The query, recorded.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $options): array {
				$this->test->probes[] = [$this->register, $this->schema, $options];
				if ($this->allowed === false) {
					throw new \RuntimeException('RBAC denied.');
				}

				return [];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);

		$payrollRunService = $this->createMock(PayrollRunService::class);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$proformaService = new ProformaPayslipService(new PayrollCalculator(), new EmployerPremiumSettings($this->createMock(IAppConfig::class)));

		// Not exercised by proforma(); present only to satisfy the merged
		// constructor (payroll-mutation-reports/retro-adjustments/
		// wkr-administration coexist on this controller).
		$payrollMutationService = $this->createMock(PayrollMutationService::class);
		$retroAdjustmentService = $this->createMock(RetroAdjustmentService::class);
		$wkrService = $this->createMock(WkrService::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('mila');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

		$logger = $this->createMock(LoggerInterface::class);

		return new PayrollController($request, new PayrollRunServices($payrollRunService, $payrollMutationService, $proformaService, $retroAdjustmentService), $wkrService, $settings, new CallerContext($userSession, new HumaniqRoles($groupManager), $container, $logger));
	}//end buildController()

}//end class
