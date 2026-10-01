<?php

/**
 * Who may assemble a third-party payments report or generate a payee's
 * statement, and what an unreadable administration or payee answers.
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
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\ThirdPartyReportController;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\ThirdPartyReportService;
use OCA\Humaniq\Service\ThirdPartyStatementService;
use OCA\Humaniq\Service\SettingsService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Two guarded endpoints (assemble a report, generate a statement), resolving under the caller's RBAC first.
 */
class ThirdPartyReportControllerTest extends TestCase {

	/**
	 * A controller for a caller with the given roles over the objects the
	 * caller can read.
	 *
	 * @param bool                                              $hr       Whether the caller is HR.
	 * @param array<string, list<array<string, mixed>>>         $readable Readable rows per schema.
	 * @param ThirdPartyReportService|null    $service    The report service.
	 * @param ThirdPartyStatementService|null $statements The statement service.
	 *
	 * @return ThirdPartyReportController
	 */
	private function controller(bool $hr, array $readable, ?ThirdPartyReportService $service = null, ?ThirdPartyStatementService $statements = null): ThirdPartyReportController {
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
		$user->method('getUID')->willReturn('payroll-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$roles = $this->createMock(HumaniqRoles::class);
		$roles->method('isHr')->willReturn(false);
		$roles->method('isPayroll')->willReturn($hr);

		return new ThirdPartyReportController(
			$this->createMock(IRequest::class),
			$container,
			$settings,
			($service ?? $this->createMock(ThirdPartyReportService::class)),
			($statements ?? $this->createMock(ThirdPartyStatementService::class)),
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
		$service = $this->createMock(ThirdPartyReportService::class);
		$service->expects($this->never())->method('assemble');
		$statements = $this->createMock(ThirdPartyStatementService::class);
		$statements->expects($this->never())->method('generate');
		$controller = $this->controller(false, ['hrAdministration' => [['administrationId' => 'ADM-001']]], $service, $statements);

		$this->assertSame(403, $controller->assemble('ADM-001', '2026')->getStatus());
		$this->assertSame(403, $controller->statement('payee-1', '2026')->getStatus());
	}//end testAnEmployeeIsRefused()

	/**
	 * An administration the caller cannot read is 404; a blank
	 * administration or a year that is not a year is 400.
	 *
	 * @return void
	 */
	public function testAnUnreadableAdministrationIs404(): void {
		$controller = $this->controller(true, ['hrAdministration' => [['administrationId' => 'ADM-001']]]);

		$this->assertSame(404, $controller->assemble('ADM-009', '2026')->getStatus());
		$this->assertSame(400, $controller->assemble(' ', '2026')->getStatus());
		$this->assertSame(400, $controller->assemble('ADM-001', 'vorig jaar')->getStatus());
		$this->assertSame(400, $controller->assemble('ADM-001', '1899')->getStatus());
	}//end testAnUnreadableAdministrationIs404()

	/**
	 * Payroll assembles a readable administration's year as itself; a
	 * refusal (a report that is already ready) is a 409.
	 *
	 * @return void
	 */
	public function testPayrollAssemblesAYear(): void {
		$service = $this->createMock(ThirdPartyReportService::class);
		$service->expects($this->exactly(2))->method('assemble')->with('ADM-001', 2026, 'payroll-1')->willReturnOnConsecutiveCalls(
			['status' => 'assembled', 'reportId' => 'r-1', 'lineCount' => 2, 'totalAmount' => 1226, 'blockingFindings' => 0, 'warningFindings' => 0],
			['status' => 'refused-not-concept', 'reportId' => 'r-1', 'message' => 'Dit overzicht is al klaargezet of verzonden; heropen het eerst.']
		);
		$controller = $this->controller(true, ['hrAdministration' => [['administrationId' => 'ADM-001']]], $service);

		$response = $controller->assemble('ADM-001', '2026');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(2, $response->getData()['lineCount']);
		$this->assertSame(409, $controller->assemble('ADM-001', '2026')->getStatus());
	}//end testPayrollAssemblesAYear()

	/**
	 * The statement needs a payee the caller can read; a failed or skipped
	 * generation is a 409 with its message.
	 *
	 * @return void
	 */
	public function testTheStatementResolvesThePayeeFirst(): void {
		$statements = $this->createMock(ThirdPartyStatementService::class);
		$statements->expects($this->exactly(2))->method('generate')->with('payee-1', 2026, 'payroll-1')->willReturnOnConsecutiveCalls(
			['status' => 'generated', 'message' => 'Jaaropgaaf gegenereerd en opgeslagen.'],
			['status' => 'skipped-no-filinq', 'message' => 'filinq is niet beschikbaar.']
		);
		$controller = $this->controller(true, ['ThirdPartyPayee' => [['id' => 'payee-1', 'lastName' => 'Dijk']]], null, $statements);

		$this->assertSame(404, $controller->statement('forbidden', '2026')->getStatus());
		$this->assertSame(404, $controller->statement('payee-x', '2026')->getStatus());
		$this->assertSame(400, $controller->statement('', '2026')->getStatus());
		$this->assertSame(400, $controller->statement('payee-1', '')->getStatus());
		$this->assertSame(200, $controller->statement('payee-1', '2026')->getStatus());
		$this->assertSame(409, $controller->statement('payee-1', '2026')->getStatus());
	}//end testTheStatementResolvesThePayeeFirst()

}//end class
