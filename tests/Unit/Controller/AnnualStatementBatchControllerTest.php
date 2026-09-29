<?php

/**
 * AnnualStatementBatchController test
 *
 * The year batch refuses a caller outside HR and payroll and a year that is
 * not over, and otherwise queues exactly one job and answers the count.
 * Refusals are asserted against the job list, not status codes alone.
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
 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Humaniq\BackgroundJob\JaaropgaafYearJob;
use OCA\Humaniq\Controller\AnnualStatementBatchController;
use OCA\Humaniq\Service\HrDocumentService;
use OCA\Humaniq\Service\HumaniqRoles;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Queueing a finished year's annual statements.
 */
class AnnualStatementBatchControllerTest extends TestCase {

	/**
	 * @var HrDocumentService&MockObject
	 */
	private HrDocumentService $service;

	/**
	 * @var IJobList&MockObject
	 */
	private IJobList $jobList;

	/**
	 * Build the controller on 12 January 2027.
	 *
	 * @param array<string, bool> $groups Group memberships of the caller (admin, humaniq-hr, humaniq-payroll).
	 *
	 * @return AnnualStatementBatchController
	 */
	private function batch(array $groups = []): AnnualStatementBatchController {
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

		return new AnnualStatementBatchController(
			$this->createMock(IRequest::class),
			$this->service,
			$session,
			new HumaniqRoles($groupManager),
			$this->jobList,
			$time,
		);
	}//end batch()

	/**
	 * An employee cannot queue the year batch.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-annual-statement-action/spec.md#REQ-JAO-002
	 */
	public function testAnEmployeeCannotQueueTheYear(): void {
		$controller = $this->batch();
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
		$controller = $this->batch(groups: ['humaniq-hr' => true]);
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
		$controller = $this->batch(groups: ['humaniq-hr' => true]);
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
		$controller = $this->batch(groups: ['humaniq-payroll' => true]);
		$this->service->method('jaaropgaafEmployeeIds')->willReturn(['emp-1']);
		$this->jobList->expects($this->once())->method('add');

		$this->assertSame(Http::STATUS_ACCEPTED, $controller->queueJaaropgaven(year: 2025)->getStatus());
	}//end testPayrollMayQueueTheYear()

}//end class
