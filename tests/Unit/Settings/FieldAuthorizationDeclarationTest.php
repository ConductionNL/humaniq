<?php

/**
 * Pins the field-level authorization humaniq declares in its register.
 *
 * OpenRegister enforces these blocks. Evaluated against OpenRegister's own
 * PropertyRbacHandler and ConditionMatcher when they were written (HR,
 * payroll, the subject and an administrator read the governed employee,
 * contract and payslip fields, a manager and a colleague do not; HR reads a
 * review's status but not its content); this test keeps the declarations
 * from drifting.
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
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Service\HumaniqRoles;
use PHPUnit\Framework\TestCase;

/**
 * The sensitive fields name the HR and payroll groups and the subject.
 */
class FieldAuthorizationDeclarationTest extends TestCase {

	/**
	 * A schema's properties from a register fragment.
	 *
	 * @param string $file   The fragment file.
	 * @param string $schema The schema.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function properties(string $file, string $schema): array {
		$fragment = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/register.d/' . $file), true);
		return $fragment['components']['schemas'][$schema]['properties'];
	}//end properties()

	/**
	 * REQ-RFA-002: identity, bank and salary fields are read by HR, payroll
	 * and the employee; salary and bank also written by payroll.
	 *
	 * @return void
	 */
	public function testEmployeeFieldsAreReadByHrPayrollAndTheEmployee(): void {
		$props = $this->properties('hr-objects.json', 'Employee');
		$subject = ['group' => 'authenticated', 'match' => ['nextcloudUserId' => '$userId']];
		foreach (['bsn', 'dateOfBirth', 'identityDocumentVerified', 'identityDocumentRetainedUntil', 'iban', 'tenaamstelling', 'grossMonthlySalary', 'gender'] as $field) {
			self::assertSame([HumaniqRoles::HR_GROUP, HumaniqRoles::PAYROLL_GROUP, $subject], $props[$field]['authorization']['read'], $field);
		}

		self::assertSame([HumaniqRoles::HR_GROUP], $props['bsn']['authorization']['update']);
		self::assertSame([HumaniqRoles::HR_GROUP, HumaniqRoles::PAYROLL_GROUP], $props['grossMonthlySalary']['authorization']['update']);
		self::assertArrayNotHasKey('authorization', $props['firstName']);
	}//end testEmployeeFieldsAreReadByHrPayrollAndTheEmployee()

	/**
	 * REQ-RFA-002: the hourly wage and every payslip amount follow the
	 * account stamped on the record.
	 *
	 * @return void
	 */
	public function testContractWageAndPayslipAmountsFollowTheAccount(): void {
		$subject = ['group' => 'authenticated', 'match' => ['userId' => '$userId']];
		$contract = $this->properties('hr-objects.json', 'EmploymentContract');
		self::assertSame([HumaniqRoles::HR_GROUP, HumaniqRoles::PAYROLL_GROUP, $subject], $contract['hourlyWage']['authorization']['read']);
		self::assertArrayHasKey('userId', $contract);

		$payslip = $this->properties('hr-objects.json', 'Payslip');
		foreach ($payslip as $field => $definition) {
			if (($definition['type'] ?? '') === 'number' || $field === 'engineInputSnapshot') {
				self::assertSame([HumaniqRoles::HR_GROUP, HumaniqRoles::PAYROLL_GROUP, $subject], $definition['authorization']['read'], $field);
				self::assertSame([HumaniqRoles::PAYROLL_GROUP], $definition['authorization']['update'], $field);
			}
		}

		self::assertArrayNotHasKey('authorization', $payslip['period']);
	}//end testContractWageAndPayslipAmountsFollowTheAccount()

	/**
	 * REQ-RFA-003: review content is read and written by the employee and
	 * the reviewer only; HR keeps the status and dates.
	 *
	 * @return void
	 */
	public function testReviewContentStaysBetweenEmployeeAndReviewer(): void {
		$props = $this->properties('hr-performance.json', 'PerformanceReview');
		$pair = [
			['group' => 'authenticated', 'match' => ['userId' => '$userId']],
			['group' => 'authenticated', 'match' => ['reviewerUserId' => '$userId']],
		];
		foreach (['rating', 'sterktes', 'ontwikkelpunten', 'afspraken', 'goals'] as $field) {
			self::assertSame(['read' => $pair, 'update' => $pair], $props[$field]['authorization'], $field);
		}

		foreach (['status', 'besprokenOp', 'vastgesteldDoor', 'reviewerUserId'] as $field) {
			self::assertArrayNotHasKey('authorization', $props[$field], $field);
		}
	}//end testReviewContentStaysBetweenEmployeeAndReviewer()

}//end class
