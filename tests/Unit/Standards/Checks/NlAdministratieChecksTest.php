<?php

/**
 * Unit tests for NlAdministratieChecks.
 *
 * Pins the multi-administratie nl-administratie-scope-consistency predicate
 * (design.md D6): the Payslip-via-PayrollRun path, the shared
 * Employee-anchored path (exercised via Timesheet — the registration is
 * identical across every Employee-anchored schema this change denormalizes
 * onto), the mismatch violation, and every vacuous hop (absent own value,
 * absent parent reference, unresolvable parent, parent with no
 * administrationId of its own).
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Standards\Checks
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
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlAdministratieChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlAdministratieChecks.
 *
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-007
 */
class NlAdministratieChecksTest extends TestCase {

	private const RULE_ID = 'nl-administratie-scope-consistency';

	/**
	 * The registered Payslip predicates, keyed by rule id.
	 *
	 * @var array<string, callable>
	 */
	private array $payslipChecks;

	/**
	 * The registered Timesheet predicates, keyed by rule id (shared across
	 * every Employee-anchored schema — one registration suffices).
	 *
	 * @var array<string, callable>
	 */
	private array $timesheetChecks;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$checks = NlAdministratieChecks::checks();
		$this->payslipChecks = $checks['Payslip'];
		$this->timesheetChecks = $checks['Timesheet'];

	}//end setUp()

	/**
	 * Every Employee-anchored schema this change denormalizes onto is
	 * actually registered (no phantom-registered rule id).
	 *
	 * @return void
	 */
	public function testEveryEmployeeAnchoredSchemaIsRegistered(): void {
		$checks = NlAdministratieChecks::checks();

		foreach (
			[
				'EmploymentContract',
				'Timesheet',
				'Expense',
				'LeaveRequest',
				'LeaveBalance',
				'SickLeaveCase',
				'Onboarding',
				'OrgAssignment',
				'AttendanceRecord',
				'AssetAssignment',
				'PerformanceReview',
			] as $type
		) {
			$this->assertArrayHasKey($type, $checks);
			$this->assertArrayHasKey(self::RULE_ID, $checks[$type]);
		}

	}//end testEveryEmployeeAnchoredSchemaIsRegistered()

	/**
	 * REQ-MULTI-007 "A mismatched child administratie is flagged" scenario
	 * (Payslip/PayrollRun variant): both sides non-empty and differing ->
	 * violation.
	 *
	 * @return void
	 */
	public function testPayslipMismatchAgainstItsPayrollRunViolates(): void {
		$payslip = ['payrollRunId' => 'payrollrun-2026-05', 'administrationId' => 'ADM-001'];
		$context = ['payroll' => ['runsById' => ['payrollrun-2026-05' => ['administrationId' => 'ADM-002']]]];

		$this->assertFalse(($this->payslipChecks[self::RULE_ID])($payslip, $context));

	}//end testPayslipMismatchAgainstItsPayrollRunViolates()

	/**
	 * The consistent path: both sides equal -> satisfied.
	 *
	 * @return void
	 */
	public function testPayslipMatchingItsPayrollRunIsSatisfied(): void {
		$payslip = ['payrollRunId' => 'payrollrun-2026-05', 'administrationId' => 'ADM-001'];
		$context = ['payroll' => ['runsById' => ['payrollrun-2026-05' => ['administrationId' => 'ADM-001']]]];

		$this->assertTrue(($this->payslipChecks[self::RULE_ID])($payslip, $context));

	}//end testPayslipMatchingItsPayrollRunIsSatisfied()

	/**
	 * REQ-MULTI-007 "An un-backfilled record is vacuous" scenario: no own
	 * administrationId yet -> passes regardless of the parent.
	 *
	 * @return void
	 */
	public function testPayslipWithNoOwnAdministrationIdIsVacuous(): void {
		$payslip = ['payrollRunId' => 'payrollrun-2026-05'];
		$context = ['payroll' => ['runsById' => ['payrollrun-2026-05' => ['administrationId' => 'ADM-002']]]];

		$this->assertTrue(($this->payslipChecks[self::RULE_ID])($payslip, $context));

	}//end testPayslipWithNoOwnAdministrationIdIsVacuous()

	/**
	 * A hand-entered payslip (no payrollRunId) is vacuous.
	 *
	 * @return void
	 */
	public function testPayslipWithNoPayrollRunIdIsVacuous(): void {
		$payslip = ['administrationId' => 'ADM-001'];

		$this->assertTrue(($this->payslipChecks[self::RULE_ID])($payslip, ['payroll' => ['runsById' => []]]));

	}//end testPayslipWithNoPayrollRunIdIsVacuous()

	/**
	 * An unresolvable payrollRunId (not yet loaded / dangling) is vacuous.
	 *
	 * @return void
	 */
	public function testPayslipWithUnresolvableRunIsVacuous(): void {
		$payslip = ['payrollRunId' => 'no-such-run', 'administrationId' => 'ADM-001'];

		$this->assertTrue(($this->payslipChecks[self::RULE_ID])($payslip, ['payroll' => ['runsById' => []]]));

	}//end testPayslipWithUnresolvableRunIsVacuous()

	/**
	 * A resolvable run with no administrationId of its own is vacuous.
	 *
	 * @return void
	 */
	public function testPayslipWhoseRunHasNoAdministrationIdIsVacuous(): void {
		$payslip = ['payrollRunId' => 'payrollrun-2026-05', 'administrationId' => 'ADM-001'];
		$context = ['payroll' => ['runsById' => ['payrollrun-2026-05' => []]]];

		$this->assertTrue(($this->payslipChecks[self::RULE_ID])($payslip, $context));

	}//end testPayslipWhoseRunHasNoAdministrationIdIsVacuous()

	/**
	 * The Employee-anchored path (Timesheet stands in for every schema
	 * sharing the registration): consistent stamp -> satisfied.
	 *
	 * @return void
	 */
	public function testEmployeeAnchoredRecordMatchingItsEmployeeIsSatisfied(): void {
		$timesheet = ['employeeId' => 'employee-jansen', 'administrationId' => 'ADM-001'];
		$context = ['related' => ['Employee' => ['byId' => ['employee-jansen' => ['administrationId' => 'ADM-001']]]]];

		$this->assertTrue(($this->timesheetChecks[self::RULE_ID])($timesheet, $context));

	}//end testEmployeeAnchoredRecordMatchingItsEmployeeIsSatisfied()

	/**
	 * REQ-MULTI-007 "A mismatched child administratie is flagged" scenario
	 * (Employee-anchored variant): both sides non-empty and differing ->
	 * violation.
	 *
	 * @return void
	 */
	public function testEmployeeAnchoredRecordMismatchingItsEmployeeViolates(): void {
		$timesheet = ['employeeId' => 'employee-jansen', 'administrationId' => 'ADM-002'];
		$context = ['related' => ['Employee' => ['byId' => ['employee-jansen' => ['administrationId' => 'ADM-001']]]]];

		$this->assertFalse(($this->timesheetChecks[self::RULE_ID])($timesheet, $context));

	}//end testEmployeeAnchoredRecordMismatchingItsEmployeeViolates()

	/**
	 * REQ-MULTI-001 "The addition is non-breaking" companion: an
	 * un-backfilled record (no own administrationId) is vacuous.
	 *
	 * @return void
	 */
	public function testEmployeeAnchoredRecordWithNoOwnAdministrationIdIsVacuous(): void {
		$timesheet = ['employeeId' => 'employee-jansen'];
		$context = ['related' => ['Employee' => ['byId' => ['employee-jansen' => ['administrationId' => 'ADM-002']]]]];

		$this->assertTrue(($this->timesheetChecks[self::RULE_ID])($timesheet, $context));

	}//end testEmployeeAnchoredRecordWithNoOwnAdministrationIdIsVacuous()

	/**
	 * An empty employeeId is vacuous.
	 *
	 * @return void
	 */
	public function testEmptyEmployeeIdIsVacuous(): void {
		$timesheet = ['employeeId' => '', 'administrationId' => 'ADM-001'];

		$this->assertTrue(($this->timesheetChecks[self::RULE_ID])($timesheet, ['related' => ['Employee' => ['byId' => []]]]));

	}//end testEmptyEmployeeIdIsVacuous()

	/**
	 * An unresolvable employeeId (dangling reference, or the Employee index
	 * not yet populated) is vacuous.
	 *
	 * @return void
	 */
	public function testUnresolvableEmployeeIsVacuous(): void {
		$timesheet = ['employeeId' => 'no-such-employee', 'administrationId' => 'ADM-001'];

		$this->assertTrue(($this->timesheetChecks[self::RULE_ID])($timesheet, ['related' => ['Employee' => ['byId' => []]]]));

	}//end testUnresolvableEmployeeIsVacuous()

	/**
	 * A resolvable Employee with no administrationId of its own is vacuous.
	 *
	 * @return void
	 */
	public function testEmployeeWithNoAdministrationIdIsVacuous(): void {
		$timesheet = ['employeeId' => 'employee-jansen', 'administrationId' => 'ADM-001'];
		$context = ['related' => ['Employee' => ['byId' => ['employee-jansen' => []]]]];

		$this->assertTrue(($this->timesheetChecks[self::RULE_ID])($timesheet, $context));

	}//end testEmployeeWithNoAdministrationIdIsVacuous()

	/**
	 * A missing context (schema not yet imported) degrades to vacuous, never
	 * a fatal error.
	 *
	 * @return void
	 */
	public function testMissingContextDegradesToVacuous(): void {
		$timesheet = ['employeeId' => 'employee-jansen', 'administrationId' => 'ADM-001'];

		$this->assertTrue(($this->timesheetChecks[self::RULE_ID])($timesheet, []));
		$this->assertTrue(($this->payslipChecks[self::RULE_ID])(['payrollRunId' => 'run-1', 'administrationId' => 'ADM-001'], []));

	}//end testMissingContextDegradesToVacuous()

}//end class
