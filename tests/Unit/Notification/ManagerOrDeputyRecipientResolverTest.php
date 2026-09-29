<?php

/**
 * Unit tests for ManagerOrDeputyRecipientResolver (self-service-approvals-inbox D3): a submit notification reaches the manager and a deputy whose period holds today, not a deputy whose period ended; and the four submitted rules name the resolver.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Notification
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
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Notification;

use OCA\Humaniq\Notification\ManagerOrDeputyRecipientResolver;
use OCA\Humaniq\Tests\Unit\Support\ApprovalsFixture;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

class ManagerOrDeputyRecipientResolverTest extends TestCase {

	use ApprovalsFixture;

	protected function setUp(): void {
		parent::setUp();
		$this->seedTeam();
		$this->store->state->objects['ManagerDeputy'] = [];
		$today = gmdate('Y-m-d');
		$this->store->seed('ManagerDeputy', 'dep-now', ['managerUserId' => 'mila', 'deputyUserId' => 'dirk', 'from' => $today, 'until' => $today]);
		$this->store->seed('ManagerDeputy', 'dep-past', ['managerUserId' => 'mila', 'deputyUserId' => 'eva', 'from' => '2020-07-14', 'until' => '2020-08-01']);
	}//end setUp()

	public function testTheActiveDeputyIsNotifiedAndTheExpiredOneIsNot(): void {
		$recipients = $this->resolve(['employeeId' => 'emp-sam', 'userId' => 'sam', 'managerUserId' => 'mila', 'status' => 'submitted']);

		self::assertSame(['mila', 'dirk'], $recipients);
	}//end testTheActiveDeputyIsNotifiedAndTheExpiredOneIsNot()

	public function testALeaveTradeWithoutAManagerReachesTheOrgChartManagerAndDeputy(): void {
		self::assertSame(['mila', 'dirk'], $this->resolve(['employeeId' => 'emp-noor', 'status' => 'submitted']));
	}//end testALeaveTradeWithoutAManagerReachesTheOrgChartManagerAndDeputy()

	public function testTheFourSubmittedRulesUseTheResolver(): void {
		$rules = [
			['hr-leave.json', 'LeaveRequest', 'leave-submitted'],
			['hr-leave.json', 'LeaveTransaction', 'leave-transaction-submitted'],
			['hr-expense.json', 'Expense', 'expense-submitted'],
			['hr-timesheet.json', 'Timesheet', 'timesheet-submitted'],
		];
		foreach ($rules as [$file, $schema, $key]) {
			$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/' . $file), true);
			$rule = $fragment['components']['schemas'][$schema]['configuration']['x-openregister-notifications'][$key];
			self::assertSame(['type' => 'transition', 'action' => 'submit'], $rule['trigger'], $key);
			self::assertSame([['kind' => 'expression', 'resolver' => ManagerOrDeputyRecipientResolver::class]], $rule['recipients'], $key);
			self::assertArrayHasKey('submit', $fragment['components']['schemas'][$schema]['configuration']['x-openregister-lifecycle']['transitions'], $key);
		}
	}//end testTheFourSubmittedRulesUseTheResolver()

	private function resolve(array $request): array {
		$entity = new ObjectEntity();
		$entity->setUuid('req-1');
		$entity->setObject($request);

		return (new ManagerOrDeputyRecipientResolver($this->deputies()))->resolve($entity, ['action' => 'submit']);
	}//end resolve()

}//end class
