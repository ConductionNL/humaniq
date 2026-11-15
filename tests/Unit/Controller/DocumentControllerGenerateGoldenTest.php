<?php

/**
 * Golden tests for DocumentController::generate() outside the jaaropgaaf path.
 *
 * Pins the refused AND allowed outcomes of the loonstrook and letter paths:
 * a blank subject id is 400 without a lookup, an unreadable subject is 404
 * and never reaches HrDocumentService, a contract without an employee is
 * 404, and a readable subject is generated for the acting user.
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
 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\DocumentController;
use OCA\Humaniq\Service\CallerContext;
use OCA\Humaniq\Service\HrDocumentService;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * DocumentController::generate() loonstrook and letter paths.
 */
class DocumentControllerGenerateGoldenTest extends TestCase {

	/**
	 * @var HrDocumentService&MockObject
	 */
	private HrDocumentService $service;

	/**
	 * The ids the fake register was asked for, in order.
	 *
	 * @var array<int, string>
	 */
	private array $finds = [];

	/**
	 * Build the controller over a register that answers $row for every id.
	 *
	 * @param array<string, mixed>|null $row The row find() answers, null for unreadable.
	 *
	 * @return DocumentController
	 */
	private function controller(?array $row): DocumentController {
		$store = new class($row, $this->finds) {
			/**
			 * @param array<string, mixed>|null $row The row find() answers.
			 * @param array<int, string> $finds The ids asked for (by reference).
			 */
			public function __construct(
				private ?array $row,
				private array &$finds,
			) {
			}//end __construct()

			/**
			 * Accepts whatever find signature the controller uses.
			 *
			 * @param string $id The object id.
			 * @param mixed ...$rest Register/schema arguments (unused).
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, mixed ...$rest): ?array {
				$this->finds[] = $id;
				return $this->row;
			}//end find()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->service = $this->createMock(HrDocumentService::class);

		return new DocumentController(
			$this->createMock(IRequest::class),
			$this->service,
			$settings,
			new CallerContext($session, $this->createMock(HumaniqRoles::class), $container, $this->createMock(LoggerInterface::class)),
		);
	}//end controller()

	/**
	 * A loonstrook request without a payslipId is 400 and looks nothing up.
	 *
	 * @return void
	 */
	public function testPayslipRequestWithoutAnIdIs400(): void {
		$controller = $this->controller(['id' => 'ps-1']);
		$this->service->expects($this->never())->method('generateLoonstrook');

		$response = $controller->generate(documentType: ' loonstrook ', payslipId: '  ');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'payslipId is verplicht.'], $response->getData());
		self::assertSame([], $this->finds);
	}//end testPayslipRequestWithoutAnIdIs400()

	/**
	 * An unreadable payslip is 404 and never reaches the document service.
	 *
	 * @return void
	 */
	public function testUnreadablePayslipIs404AndNothingIsRendered(): void {
		$controller = $this->controller(null);
		$this->service->expects($this->never())->method('generateLoonstrook');

		$response = $controller->generate(documentType: 'loonstrook', payslipId: ' ps-hidden ');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Loonstrook niet gevonden.'], $response->getData());
		self::assertSame(['ps-hidden'], $this->finds);
	}//end testUnreadablePayslipIs404AndNothingIsRendered()

	/**
	 * A readable payslip is rendered for the acting user.
	 *
	 * @return void
	 */
	public function testReadablePayslipIsRenderedForTheCaller(): void {
		$controller = $this->controller(['id' => 'ps-1', 'employeeId' => 'emp-1']);
		$this->service->expects($this->once())->method('generateLoonstrook')
			->with('ps-1', 'caller')
			->willReturn(['status' => 'generated']);

		$response = $controller->generate(contractId: 'c-ignored', documentType: 'loonstrook', payslipId: ' ps-1 ');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['status' => 'generated'], $response->getData());
		self::assertSame(['ps-1'], $this->finds);
	}//end testReadablePayslipIsRenderedForTheCaller()

	/**
	 * A letter request without a contractId is 400 and looks nothing up.
	 *
	 * @return void
	 */
	public function testLetterRequestWithoutAContractIdIs400(): void {
		$controller = $this->controller(['id' => 'c-1', 'employeeId' => 'emp-1']);
		$this->service->expects($this->never())->method('generate');

		$response = $controller->generate(payslipId: 'ps-ignored');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'contractId is verplicht.'], $response->getData());
		self::assertSame([], $this->finds);
	}//end testLetterRequestWithoutAContractIdIs400()

	/**
	 * An unreadable contract is 404 and never reaches the document service.
	 *
	 * @return void
	 */
	public function testUnreadableContractIs404AndNothingIsRendered(): void {
		$controller = $this->controller(null);
		$this->service->expects($this->never())->method('generate');

		$response = $controller->generate(contractId: ' c-hidden ');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Contract niet gevonden.'], $response->getData());
		self::assertSame(['c-hidden'], $this->finds);
	}//end testUnreadableContractIs404AndNothingIsRendered()

	/**
	 * A readable contract without a linked employee is 404.
	 *
	 * @return void
	 */
	public function testContractWithoutAnEmployeeIs404(): void {
		$controller = $this->controller(['id' => 'c-1', 'employeeId' => '  ']);
		$this->service->expects($this->never())->method('generate');

		$response = $controller->generate(contractId: 'c-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Contract heeft geen gekoppelde medewerker.'], $response->getData());
	}//end testContractWithoutAnEmployeeIs404()

	/**
	 * A readable contract renders the requested (trimmed) letter type for its
	 * own employee and the acting user.
	 *
	 * @return void
	 */
	public function testReadableContractRendersTheRequestedLetter(): void {
		$controller = $this->controller(['id' => 'c-1', 'employeeId' => ' emp-1 ']);
		$this->service->expects($this->once())->method('generate')
			->with('emp-1', 'c-1', 'ontslagbrief', 'caller')
			->willReturn(['status' => 'generated']);

		$response = $controller->generate(contractId: ' c-1 ', documentType: ' ontslagbrief ');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['status' => 'generated'], $response->getData());
		self::assertSame(['c-1'], $this->finds);
	}//end testReadableContractRendersTheRequestedLetter()
}//end class
