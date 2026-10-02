<?php

/**
 * LoonaangifteMessageControllerTest: making the wage tax return message is for HR and payroll, on a filing the caller can read.
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\LoonaangifteMessageController;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\LoonaangifteCorrectionService;
use OCA\Humaniq\Service\LoonaangifteMessageService;
use OCA\Humaniq\Service\SettingsService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The make-the-message endpoint, resolving the filing under the caller's RBAC first.
 */
class LoonaangifteMessageControllerTest extends TestCase {

	/**
	 * The controller over a fake object store holding what the caller may read.
	 *
	 * @param bool                                          $payroll  Whether the caller is in payroll.
	 * @param array<string, list<array<string, mixed>>>     $readable The rows per schema the caller may read.
	 * @param LoonaangifteMessageService|null               $service  The service.
	 * @param LoonaangifteCorrectionService|null            $corrections The correction service.
	 *
	 * @return LoonaangifteMessageController
	 */
	private function controller(bool $payroll, array $readable, ?LoonaangifteMessageService $service = null, ?LoonaangifteCorrectionService $corrections = null): LoonaangifteMessageController {
		$objects = new class($readable) {

			/**
			 * The fake store.
			 *
			 * @param array<string, list<array<string, mixed>>> $readable The rows.
			 */
			public function __construct(private readonly array $readable) {
			}//end __construct()

			/**
			 * Find one row.
			 *
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
		$roles->method('isPayroll')->willReturn($payroll);

		return new LoonaangifteMessageController(
			$this->createMock(IRequest::class),
			$container,
			$settings,
			($service ?? $this->createMock(LoonaangifteMessageService::class)),
			($corrections ?? $this->createMock(LoonaangifteCorrectionService::class)),
			$session,
			$roles,
			new NullLogger()
		);
	}//end controller()

	/**
	 * An employee is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnEmployeeIsRefused(): void {
		$service = $this->createMock(LoonaangifteMessageService::class);
		$service->expects($this->never())->method('render');
		$controller = $this->controller(false, ['LoonaangifteFiling' => [['id' => 'f-1']]], $service);

		$this->assertSame(403, $controller->render('f-1')->getStatus());
	}//end testAnEmployeeIsRefused()

	/**
	 * A filing the caller cannot read is 404; an empty id is 400.
	 *
	 * @return void
	 */
	public function testAnUnreadableFilingIs404(): void {
		$controller = $this->controller(true, ['LoonaangifteFiling' => [['id' => 'f-1']]]);

		$this->assertSame(404, $controller->render('forbidden')->getStatus());
		$this->assertSame(404, $controller->render('f-x')->getStatus());
		$this->assertSame(400, $controller->render(' ')->getStatus());
	}//end testAnUnreadableFilingIs404()

	/**
	 * Payroll renders a readable filing: 200 when rendered, 409 when blocked
	 * or refused, with the outcome as the body.
	 *
	 * @return void
	 */
	public function testPayrollRendersAFiling(): void {
		$filing = ['id' => 'f-1', 'period' => '2026-06', 'status' => 'concept'];
		$service = $this->createMock(LoonaangifteMessageService::class);
		$service->expects($this->exactly(2))->method('render')->with($filing, 'payroll-1')->willReturnOnConsecutiveCalls(
			['status' => 'rendered', 'filingId' => 'f-1', 'blockingFindings' => 0, 'warningFindings' => 0],
			['status' => 'blocked', 'filingId' => 'f-1', 'blockingFindings' => 1, 'warningFindings' => 0]
		);
		$controller = $this->controller(true, ['LoonaangifteFiling' => [$filing]], $service);

		$response = $controller->render('f-1');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('rendered', $response->getData()['status']);
		$this->assertSame(409, $controller->render('f-1')->getStatus());
	}//end testPayrollRendersAFiling()

	/**
	 * Correct opens a correction (201), returns the open one (200), refuses a
	 * filing that is not sent (409); an employee is refused, an unreadable
	 * filing is 404. Make message on a correction makes the correction.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-001
	 */
	public function testCorrectAndMakeACorrection(): void {
		$sent = ['id' => 'f-1', 'status' => 'verzonden'];
		$correction = ['id' => 'c-1', 'filingType' => 'correctie', 'status' => 'concept'];
		$corrections = $this->createMock(LoonaangifteCorrectionService::class);
		$corrections->method('open')->with($sent, 'payroll-1')->willReturnOnConsecutiveCalls(
			['status' => 'opened', 'filingId' => 'c-1'],
			['status' => 'exists', 'filingId' => 'c-1'],
			['status' => 'refused-not-sent', 'filingId' => 'f-1']
		);
		$corrections->expects($this->once())->method('render')->with($correction, 'payroll-1')->willReturn(['status' => 'prepared', 'filingId' => 'c-1']);
		$messages = $this->createMock(LoonaangifteMessageService::class);
		$messages->expects($this->never())->method('render');
		$controller = $this->controller(true, ['LoonaangifteFiling' => [$sent, $correction]], $messages, $corrections);

		$this->assertSame([201, 200, 409], [$controller->correction('f-1')->getStatus(), $controller->correction('f-1')->getStatus(), $controller->correction('f-1')->getStatus()]);
		$this->assertSame(404, $controller->correction('f-x')->getStatus());
		$this->assertSame(200, $controller->render('c-1')->getStatus());
		$this->assertSame(403, $this->controller(false, ['LoonaangifteFiling' => [$sent]])->correction('f-1')->getStatus());
	}//end testCorrectAndMakeACorrection()

}//end class
