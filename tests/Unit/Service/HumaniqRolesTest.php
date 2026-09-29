<?php

/**
 * Tests for HumaniqRoles.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HumaniqRoles;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * An administrator holds every role, a group member only their own.
 */
class HumaniqRolesTest extends TestCase {

	/**
	 * Build the roles over a fixed membership.
	 *
	 * @param list<string>                $admins  Administrator uids.
	 * @param array<string, list<string>> $members Group id to member uids.
	 *
	 * @return HumaniqRoles
	 */
	private function roles(array $admins, array $members): HumaniqRoles {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array($uid, $admins, true));
		$groupManager->method('isInGroup')->willReturnCallback(fn (string $uid, string $gid): bool => in_array($uid, ($members[$gid] ?? []), true));
		return new HumaniqRoles($groupManager);
	}//end roles()

	/**
	 * The four kinds of user answer as the design says.
	 *
	 * @return void
	 */
	public function testEachRoleAnswersForItsOwnGroupAndAnAdministratorForAll(): void {
		$roles = $this->roles(['admin'], ['humaniq-hr' => ['hr-demo'], 'humaniq-payroll' => ['payroll-demo']]);

		$this->assertTrue($roles->isHr('admin'));
		$this->assertTrue($roles->isPayroll('admin'));
		$this->assertTrue($roles->isHr('hr-demo'));
		$this->assertFalse($roles->isPayroll('hr-demo'));
		$this->assertFalse($roles->isHr('payroll-demo'));
		$this->assertTrue($roles->isPayroll('payroll-demo'));
		$this->assertFalse($roles->isHr('employee-demo'));
		$this->assertFalse($roles->isPayroll('employee-demo'));
	}//end testEachRoleAnswersForItsOwnGroupAndAnAdministratorForAll()

	/**
	 * No user, no role.
	 *
	 * @return void
	 */
	public function testNoUserHoldsNoRole(): void {
		$roles = $this->roles([''], ['humaniq-hr' => ['']]);

		$this->assertFalse($roles->isHr(null));
		$this->assertFalse($roles->isHr(''));
		$this->assertFalse($roles->isPayroll(null));
	}//end testNoUserHoldsNoRole()

	/**
	 * The group ids are the ones the register's property authorization names.
	 *
	 * @return void
	 */
	public function testTheGroupIdsAreTheOnesTheRegisterNames(): void {
		$this->assertSame('humaniq-hr', HumaniqRoles::HR_GROUP);
		$this->assertSame('humaniq-payroll', HumaniqRoles::PAYROLL_GROUP);
	}//end testTheGroupIdsAreTheOnesTheRegisterNames()

}//end class
