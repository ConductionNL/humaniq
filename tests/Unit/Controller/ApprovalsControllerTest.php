<?php

/**
 * Unit tests for ApprovalsController (self-service-approvals-inbox D1): the caller's own inbox, open or decided, and refusals for a bad state or kind and for a caller without a session.
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
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\ApprovalsController;
use OCA\Humaniq\Tests\Unit\Support\ApprovalsFixture;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class ApprovalsControllerTest extends TestCase {

	use ApprovalsFixture;

	protected function setUp(): void {
		parent::setUp();
		$this->seedTeam();
		$today = gmdate('Y-m-d');
		$this->request('LeaveRequest', 'lv-1', ['employeeId' => 'emp-sam', 'userId' => 'sam', 'managerUserId' => 'mila', 'status' => 'submitted', 'submittedAt' => $today . 'T08:00:00Z']);
		$this->request('Expense', 'ex-1', ['employeeId' => 'emp-sam', 'userId' => 'sam', 'managerUserId' => 'mila', 'status' => 'approved', 'submittedAt' => $today . 'T07:00:00Z', 'approvedBy' => 'mila', 'approvedAt' => $today . 'T09:00:00Z']);
	}//end setUp()

	public function testTheOpenInboxIsTheCallersOwn(): void {
		$response = $this->controller('mila')->index('open');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['lv-1'], array_column($response->getData()['requests'], 'id'));
		self::assertSame([], $this->controller('sam')->index('open')->getData()['requests']);
	}//end testTheOpenInboxIsTheCallersOwn()

	public function testTheDecidedViewListsTheCallersDecisions(): void {
		self::assertSame(['ex-1'], array_column($this->controller('mila')->index('decided')->getData()['requests'], 'id'));
		self::assertSame([], $this->controller('mila')->index('decided', 'leave')->getData()['requests']);
	}//end testTheDecidedViewListsTheCallersDecisions()

	public function testABadStateOrKindIsRefused(): void {
		self::assertSame(400, $this->controller('mila')->index('everything')->getStatus());
		self::assertSame(400, $this->controller('mila')->index('open', 'payslip')->getStatus());
	}//end testABadStateOrKindIsRefused()

	public function testACallerWithoutASessionIsRefused(): void {
		self::assertSame(401, $this->controller(null)->index('open')->getStatus());
	}//end testACallerWithoutASessionIsRefused()

	private function controller(?string $uid): ApprovalsController {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new ApprovalsController(request: $this->createMock(IRequest::class), inbox: $this->inbox(), userSession: $session);
	}//end controller()

}//end class
