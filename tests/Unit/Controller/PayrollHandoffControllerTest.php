<?php

/**
 * Who may compile a handoff or check its intake, and what an unreadable
 * administration or handoff answers.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\PayrollHandoffController;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\SalaryBureauExchangeService;
use OCA\Humaniq\Service\SettingsService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Design D5: two guarded endpoints, resolving under the caller's RBAC first.
 */
class PayrollHandoffControllerTest extends TestCase {

	/**
	 * A controller for a caller with the given roles over the objects the
	 * caller can read.
	 *
	 * @param bool                                              $hr       Whether the caller is HR.
	 * @param array<string, list<array<string, mixed>>>         $readable Readable rows per schema.
	 * @param SalaryBureauExchangeService|null                        $service  The handoff service.
	 *
	 * @return PayrollHandoffController
	 */
	private function controller(bool $hr, array $readable, ?SalaryBureauExchangeService $service = null): PayrollHandoffController {
		$objects = new class($readable) {

			/**
			 * The schema set last.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, list<array<string, mixed>>> $readable Readable rows per schema.
			 */
			public function __construct(private readonly array $readable) {
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
				$this->schema = (string)$schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				$out = [];
				foreach (($this->readable[$this->schema] ?? []) as $row) {
					if (array_diff_assoc((array)($config['filters'] ?? []), $row) === []) {
						$out[] = $row;
					}
				}

				return $out;
			}//end findAll()

			/**
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, string $register = '', string $schema = ''): ?array {
				if ($id === 'forbidden') {
					throw new \RuntimeException('Forbidden');
				}

				foreach (($this->readable[$schema] ?? []) as $row) {
					if (($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				return null;
			}//end find()

		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$roles = $this->createMock(HumaniqRoles::class);
		$roles->method('isHr')->willReturn($hr);
		$roles->method('isPayroll')->willReturn(false);

		return new PayrollHandoffController(
			$this->createMock(IRequest::class),
			$container,
			$settings,
			($service ?? $this->createMock(SalaryBureauExchangeService::class)),
			$session,
			$roles,
			new NullLogger()
		);
	}//end controller()

	/**
	 * An employee is refused both endpoints before anything resolves.
	 *
	 * @return void
	 */
	public function testAnEmployeeIsRefused(): void {
		$service = $this->createMock(SalaryBureauExchangeService::class);
		$service->expects($this->never())->method('compile');
		$service->expects($this->never())->method('checkIntake');
		$controller = $this->controller(false, ['hrAdministration' => [['administrationId' => 'ADM-006']]], $service);

		$this->assertSame(403, $controller->compile('ADM-006', '2026-05')->getStatus());
		$this->assertSame(403, $controller->checkIntake('ho-1')->getStatus());
	}//end testAnEmployeeIsRefused()

	/**
	 * An administration the caller cannot read is 404; a blank or malformed
	 * input is 400.
	 *
	 * @return void
	 */
	public function testAnUnreadableAdministrationIs404(): void {
		$controller = $this->controller(true, ['hrAdministration' => [['administrationId' => 'ADM-001']]]);

		$this->assertSame(404, $controller->compile('ADM-006', '2026-05')->getStatus());
		$this->assertSame(400, $controller->compile(' ', '2026-05')->getStatus());
		$this->assertSame(400, $controller->compile('ADM-006', 'mei')->getStatus());
	}//end testAnUnreadableAdministrationIs404()

	/**
	 * HR compiles a readable administration's period as itself.
	 *
	 * @return void
	 */
	public function testHrCompilesAPeriod(): void {
		$service = $this->createMock(SalaryBureauExchangeService::class);
		$service->expects($this->once())->method('compile')->with('ADM-006', '2026-05', 'hr-1')->willReturn(['status' => 'compiled', 'handoffId' => 'ho-1', 'mutationCount' => 3]);

		$response = $this->controller(true, ['hrAdministration' => [['administrationId' => 'ADM-006']]], $service)->compile('ADM-006', '2026-05');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(3, $response->getData()['mutationCount']);
	}//end testHrCompilesAPeriod()

	/**
	 * A refusal of the service (an engine administration, a handoff that
	 * already left) is a 409 with its message.
	 *
	 * @return void
	 */
	public function testARefusedCompileIs409(): void {
		$service = $this->createMock(SalaryBureauExchangeService::class);
		$service->method('compile')->willReturn(['status' => 'refused-engine', 'handoffId' => '', 'mutationCount' => 0, 'message' => 'Deze administratie wordt door humaniq verloond; er is geen overdracht.']);

		$response = $this->controller(true, ['hrAdministration' => [['administrationId' => 'ADM-001']]], $service)->compile('ADM-001', '2026-05');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('refused-engine', $response->getData()['status']);
	}//end testARefusedCompileIs409()

	/**
	 * The intake check needs a handoff the caller can read.
	 *
	 * @return void
	 */
	public function testTheIntakeCheckResolvesTheHandoffFirst(): void {
		$service = $this->createMock(SalaryBureauExchangeService::class);
		$service->expects($this->once())->method('checkIntake')->with('ho-1')->willReturn(['blocking' => 1, 'findings' => [['kind' => 'missing-payslip', 'employeeId' => 'emp-b']]]);
		$controller = $this->controller(true, ['PayrollHandoff' => [['id' => 'ho-1', 'status' => 'ontvangen']]], $service);

		$this->assertSame(404, $controller->checkIntake('forbidden')->getStatus());
		$this->assertSame(404, $controller->checkIntake('ho-x')->getStatus());
		$this->assertSame(400, $controller->checkIntake('')->getStatus());
		$response = $controller->checkIntake('ho-1');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['blocking']);
	}//end testTheIntakeCheckResolvesTheHandoffFirst()

}//end class
