<?php

/**
 * Unit tests for PayrollCheckController: POST /api/payroll/check.
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
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\PayrollCheckController;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\PayrollRunCheckService;
use OCA\Humaniq\Service\SettingsService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Who may check a run, and what an unreadable run answers.
 */
class PayrollCheckControllerTest extends TestCase {

	/**
	 * A controller for a caller with the given roles over the given runs.
	 *
	 * @param bool                              $hr       Whether the caller is HR.
	 * @param bool                              $payroll  Whether the caller is payroll.
	 * @param array<string, array<string, mixed>> $runs   Runs the caller can read, by id.
	 * @param PayrollRunCheckService|null       $check    The check.
	 *
	 * @return PayrollCheckController
	 */
	private function controller(bool $hr, bool $payroll, array $runs, ?PayrollRunCheckService $check = null): PayrollCheckController {
		$objects = new class($runs) {

			/**
			 * @param array<string, array<string, mixed>> $runs Runs by id.
			 */
			public function __construct(private readonly array $runs) {
			}//end __construct()

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

				return ($this->runs[$id] ?? null);
			}//end find()

		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('someone');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$roles = $this->createMock(HumaniqRoles::class);
		$roles->method('isHr')->willReturn($hr);
		$roles->method('isPayroll')->willReturn($payroll);

		return new PayrollCheckController(
			$this->createMock(IRequest::class),
			$container,
			$settings,
			($check ?? $this->createMock(PayrollRunCheckService::class)),
			$session,
			$roles,
			new NullLogger()
		);
	}//end controller()

	/**
	 * An employee without HR or payroll is refused before anything resolves.
	 *
	 * @return void
	 */
	public function testAnEmployeeIsRefused(): void {
		$this->assertSame(403, $this->controller(false, false, ['run-5' => ['id' => 'run-5']])->check('run-5')->getStatus());
	}//end testAnEmployeeIsRefused()

	/**
	 * An unreadable or unknown run is 404 and a blank id 400.
	 *
	 * @return void
	 */
	public function testAnUnreadableRunIs404(): void {
		$this->assertSame(404, $this->controller(true, false, [])->check('forbidden')->getStatus());
		$this->assertSame(404, $this->controller(true, false, [])->check('run-x')->getStatus());
		$this->assertSame(400, $this->controller(true, false, [])->check(' ')->getStatus());
	}//end testAnUnreadableRunIs404()

	/**
	 * HR or payroll checks a readable run and gets the summary.
	 *
	 * @return void
	 */
	public function testPayrollChecksARun(): void {
		$check = $this->createMock(PayrollRunCheckService::class);
		$check->expects($this->once())->method('check')->with('run-5')->willReturn(['blocking' => 1, 'warning' => 2, 'info' => 0]);

		$response = $this->controller(false, true, ['run-5' => ['id' => 'run-5', 'status' => 'draft']], $check)->check('run-5');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(2, $response->getData()['warning']);
	}//end testPayrollChecksARun()

}//end class
