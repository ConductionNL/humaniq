<?php

/**
 * Every schema of the assembled humaniq register is covered by an access
 * block: its own, or the register default that grants HR only.
 *
 * A schema without its own block inherits the register's block in
 * OpenRegister, and with neither every signed-in user holds every verb. So
 * the register block must exist and name no wide group, and a schema's own
 * block may open to every signed-in user only through a match rule, apart
 * from the creates of requests an employee submits and the reads of
 * reference data an employee needs to fill one in.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Settings
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

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\RegisterConfigReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The register and its schemas fail closed.
 *
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
 */
class SchemaAccessDeclarationTest extends TestCase {

	/**
	 * The verbs every block declares.
	 *
	 * @var array<int, string>
	 */
	private const VERBS = ['read', 'create', 'update', 'delete'];

	/**
	 * Groups that reach everyone, signed in or not.
	 *
	 * @var array<int, string>
	 */
	private const WIDE = ['authenticated', 'public'];

	/**
	 * Requests an employee submits: anyone signed in may create one, and
	 * the server stamps whose it is.
	 *
	 * @var array<int, string>
	 */
	private const SUBMITTED = [
		'AttendanceRecord', 'CommuteArrangement', 'DsrRequest', 'EmployeeChangeRequest', 'Expense', 'KeyResult',
		'LeaveRequest', 'LeaveTransaction', 'ManagerDeputy', 'Objective', 'Referral', 'SideActivity',
		'Timesheet', 'TimeEntry',
	];

	/**
	 * Reference data every signed-in user reads.
	 *
	 * @var array<int, string>
	 */
	private const REFERENCE = ['LeaveType', 'TimeActivity'];

	/**
	 * The assembled register config.
	 *
	 * @return array<string, mixed>
	 */
	private static function config(): array {
		return ((new RegisterConfigReader(new NullLogger()))->read()['data'] ?? []);
	}//end config()

	/**
	 * The register block grants every verb to HR and to nobody wider.
	 *
	 * @return void
	 */
	public function testTheRegisterDefaultIsHr(): void {
		$authorization = (self::config()['components']['registers']['humaniq']['authorization'] ?? null);

		self::assertIsArray($authorization, 'the humaniq register declares no authorization block');
		foreach (self::VERBS as $verb) {
			self::assertSame([HumaniqRoles::HR_GROUP], ($authorization[$verb] ?? null), $verb);
		}
	}//end testTheRegisterDefaultIsHr()

	/**
	 * Every schema the register lists is defined, and every block it
	 * declares covers every verb and opens to everyone only through a
	 * match, or for the listed creates and reference reads.
	 *
	 * @return void
	 */
	public function testNoSchemaOpensToEveryone(): void {
		$config = self::config();
		$schemas = ($config['components']['schemas'] ?? []);
		$listed = ($config['components']['registers']['humaniq']['schemas'] ?? []);

		self::assertNotSame([], $listed);
		foreach ($listed as $name) {
			self::assertArrayHasKey($name, $schemas, $name . ' is listed on the register but not defined');
			$authorization = ($schemas[$name]['authorization'] ?? null);
			if ($authorization === null) {
				continue;
			}

			foreach (self::VERBS as $verb) {
				self::assertArrayHasKey($verb, $authorization, $name . ' declares no ' . $verb);
				foreach ($authorization[$verb] as $rule) {
					if (is_string($rule) === false || in_array($rule, self::WIDE, true) === false) {
						continue;
					}

					$allowed = ($rule === 'authenticated')
						&& (($verb === 'create' && in_array($name, self::SUBMITTED, true) === true)
						|| ($verb === 'read' && in_array($name, self::REFERENCE, true) === true));
					self::assertTrue($allowed, $name . ' opens ' . $verb . ' to ' . $rule);
				}
			}
		}
	}//end testNoSchemaOpensToEveryone()

	/**
	 * An employee reads their own payslip, leave balance and employee
	 * record, and their own and their team's leave requests.
	 *
	 * @return void
	 */
	public function testEmployeesAndManagersReadTheirOwnRecords(): void {
		$schemas = self::config()['components']['schemas'];

		self::assertContains(['group' => 'authenticated', 'match' => ['userId' => '$userId']], $schemas['Payslip']['authorization']['read']);
		self::assertContains(['group' => 'authenticated', 'match' => ['userId' => '$userId']], $schemas['LeaveBalance']['authorization']['read']);
		self::assertContains(['group' => 'authenticated', 'match' => ['nextcloudUserId' => '$userId']], $schemas['Employee']['authorization']['read']);
		self::assertContains(['group' => 'authenticated', 'match' => ['userId' => '$userId']], $schemas['LeaveRequest']['authorization']['read']);
		self::assertContains(['group' => 'authenticated', 'match' => ['managerUserId' => '$userId']], $schemas['LeaveRequest']['authorization']['read']);
		self::assertSame([HumaniqRoles::HR_GROUP, HumaniqRoles::PAYROLL_GROUP], $schemas['PayrollRun']['authorization']['read']);
	}//end testEmployeesAndManagersReadTheirOwnRecords()

	/**
	 * The manager and the deputies stamped on a team member's employee
	 * record, sickness case, leave trade and pay adjustment read it, and
	 * decide the leave trade; nobody else outside HR gains a verb.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function testTheStampedManagerAndDeputiesReadTheirTeamsRecords(): void {
		$schemas = self::config()['components']['schemas'];
		$manager = ['group' => 'authenticated', 'match' => ['managerUserId' => '$userId']];
		$deputy = ['group' => 'authenticated', 'match' => ['deputyUserIds' => ['$contains' => '$userId']]];

		foreach (['Employee', 'SickLeaveCase', 'LeaveTransaction', 'CompAdjustment'] as $name) {
			$auth = $schemas[$name]['authorization'];
			self::assertContains($manager, $auth['read'], $name);
			self::assertContains($deputy, $auth['read'], $name);
			self::assertArrayHasKey('managerUserId', $schemas[$name]['properties'], $name);
			self::assertArrayHasKey('deputyUserIds', $schemas[$name]['properties'], $name);
			self::assertNotContains($manager, $auth['create'], $name);
			self::assertNotContains($manager, $auth['delete'], $name);
		}

		self::assertContains($manager, $schemas['LeaveTransaction']['authorization']['update']);
		self::assertContains($deputy, $schemas['LeaveTransaction']['authorization']['update']);
		self::assertSame([HumaniqRoles::HR_GROUP], $schemas['SickLeaveCase']['authorization']['update']);
		self::assertSame([HumaniqRoles::HR_GROUP], $schemas['CompAdjustment']['authorization']['update']);
		self::assertSame([HumaniqRoles::HR_GROUP, HumaniqRoles::PAYROLL_GROUP], $schemas['Employee']['authorization']['update']);
	}//end testTheStampedManagerAndDeputiesReadTheirTeamsRecords()
}//end class
