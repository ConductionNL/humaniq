<?php

/**
 * DocumentController test: the annual statement triggers
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
 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Humaniq\BackgroundJob\JaaropgaafYearJob;
use OCA\Humaniq\Controller\DocumentController;
use OCA\Humaniq\Service\HrDocumentService;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
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
	 * @var IJobList&MockObject
	 */
	private IJobList $jobList;

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
	 * @param array<string, bool>       $groups Group memberships of the caller (admin, humaniq-hr, humaniq-payroll).
	 *
	 * @return DocumentController
	 */
	private function controller(?array $row = null, array $groups = []): DocumentController {
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

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($groups['admin'] ?? false);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => $groups[$gid] ?? false
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2027-01-12T09:00:00Z'));

		$this->service = $this->createMock(HrDocumentService::class);
		$this->jobList = $this->createMock(IJobList::class);

		return new DocumentController(
			$this->createMock(IRequest::class),
			$container,
			$this->service,
			$settings,
			$session,
			new HumaniqRoles($groupManager),
			$this->jobList,
			$time,
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

	/**
	 * An employee cannot queue the year batch.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
	 */
	public function testAnEmployeeCannotQueueTheYear(): void {
		$controller = $this->controller();
		$this->jobList->expects($this->never())->method('add');

		$response = $controller->queueJaaropgaven();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAnEmployeeCannotQueueTheYear()

	/**
	 * The current year is refused because it is not over.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
	 */
	public function testTheCurrentYearIsRefused(): void {
		$controller = $this->controller(groups: ['humaniq-hr' => true]);
		$this->jobList->expects($this->never())->method('add');

		$response = $controller->queueJaaropgaven(year: 2027);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testTheCurrentYearIsRefused()

	/**
	 * HR queues last year by default and is told how many employees it covers.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
	 */
	public function testHrQueuesLastYearWithTheCount(): void {
		$controller = $this->controller(groups: ['humaniq-hr' => true]);
		$this->service->method('jaaropgaafEmployeeIds')->with(2026)->willReturn(array_map(static fn (int $i): string => 'emp-' . $i, range(1, 14)));
		$this->jobList->expects($this->once())->method('add')
			->with(JaaropgaafYearJob::class, ['year' => 2026, 'userId' => 'caller']);

		$response = $controller->queueJaaropgaven();

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(['year' => 2026, 'queued' => 14], $response->getData());
	}//end testHrQueuesLastYearWithTheCount()

	/**
	 * Payroll may queue the year too.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
	 */
	public function testPayrollMayQueueTheYear(): void {
		$controller = $this->controller(groups: ['humaniq-payroll' => true]);
		$this->service->method('jaaropgaafEmployeeIds')->willReturn(['emp-1']);
		$this->jobList->expects($this->once())->method('add');

		$this->assertSame(Http::STATUS_ACCEPTED, $controller->queueJaaropgaven(year: 2025)->getStatus());
	}//end testPayrollMayQueueTheYear()

}//end class
