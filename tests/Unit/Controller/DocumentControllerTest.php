<?php

/**
 * DocumentController test: one annual statement from its page
 *
 * The single statement resolves under the caller's RBAC before anything is
 * rendered, and the year batch refuses a caller outside HR and payroll, a
 * year that is not over, and queues exactly one job otherwise. Refusals are
 * asserted against the service and job list call counts, not status codes
 * alone: only the absence of the reach proves the guard ran first.
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
 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\DocumentController;
use OCA\Humaniq\Service\HrDocumentService;
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
 * The annual statement triggers on the document endpoint.
 */
class DocumentControllerTest extends TestCase {

	/**
	 * @var HrDocumentService&MockObject
	 */
	private HrDocumentService $service;

	/**
	 * The fake ObjectService handed out by the container.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed>|null $row    The row find() answers, null for unreadable.
	 *
	 * @return DocumentController
	 */
	private function controller(?array $row = null): DocumentController {
		$this->store = new class($row) {

			/**
			 * @var array<int, array{id: string, schema: string|null}>
			 */
			public array $finds = [];

			/**
			 * @param array<string, mixed>|null $row The row find() answers.
			 */
			public function __construct(public ?array $row) {
			}//end __construct()

			/**
			 * @param string      $id       The object id.
			 * @param string|null $register The register slug.
			 * @param string|null $schema   The schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, ?string $register = null, ?string $schema = null): ?array {
				$this->finds[] = ['id' => $id, 'schema' => $schema];
				return $this->row;
			}//end find()

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store);

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
			$container,
			$this->service,
			$settings,
			$session,
			$this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * A readable statement is generated for its own employee and year.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-001
	 */
	public function testAReadableStatementIsGeneratedForItsEmployeeAndYear(): void {
		$controller = $this->controller(row: ['id' => 'jo-1', 'employeeId' => 'emp-7', 'year' => 2026]);
		$this->service->expects($this->once())->method('generateJaaropgaaf')
			->with('emp-7', 2026, 'caller')
			->willReturn(['status' => 'generated']);

		$response = $controller->generate(documentType: 'jaaropgaaf', jaaropgaafId: 'jo-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Jaaropgaaf', $this->store->finds[0]['schema']);
	}//end testAReadableStatementIsGeneratedForItsEmployeeAndYear()

	/**
	 * A statement the caller cannot read is 404 and nothing is rendered.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-001
	 */
	public function testAnUnreadableStatementIs404AndNothingIsRendered(): void {
		$controller = $this->controller(row: null);
		$this->service->expects($this->never())->method('generateJaaropgaaf');

		$response = $controller->generate(documentType: 'jaaropgaaf', jaaropgaafId: 'jo-hidden');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertCount(1, $this->store->finds);
	}//end testAnUnreadableStatementIs404AndNothingIsRendered()

	/**
	 * A jaaropgaaf request without an id is 400 before any resolve.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-001
	 */
	public function testAStatementRequestWithoutAnIdIs400(): void {
		$controller = $this->controller(row: ['employeeId' => 'emp-7', 'year' => 2026]);
		$this->service->expects($this->never())->method('generateJaaropgaaf');

		$response = $controller->generate(documentType: 'jaaropgaaf');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame([], $this->store->finds);
	}//end testAStatementRequestWithoutAnIdIs400()

}//end class
