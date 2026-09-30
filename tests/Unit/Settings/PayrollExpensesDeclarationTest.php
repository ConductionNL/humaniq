<?php

/**
 * The register declarations payroll-expenses-and-allowances writes against.
 *
 * Every payload the run writes (payslip fields, run total, WKR row, claim
 * stamp) is validated with Opis against the register's own fragments, and the
 * RecurringAllowance lifecycle is read from the fragment and checked against
 * the real NoSelfApprovalGuard.
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
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Lifecycle\NoSelfApprovalGuard;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The fields and the lifecycle the change relies on.
 */
class PayrollExpensesDeclarationTest extends TestCase {

	/**
	 * The payslip fields the run writes are valid Payslip properties.
	 *
	 * @return void
	 */
	public function testThePayslipFieldsTheRunWritesAreDeclared(): void {
		$payslip = RegisterSchemaValidator::schema('Payslip');
		foreach (['reimbursements', 'reimbursedExpenseIds', 'allowancesTaxed', 'allowancesUntaxed', 'allowanceLines'] as $field) {
			$this->assertArrayHasKey($field, $payslip['properties'], $field);
		}

		$fields = [
			'reimbursements' => 27.40,
			'reimbursedExpenseIds' => ['0127394a-be27-48b4-a592-b6a41774b221'],
			'allowancesTaxed' => 20.00,
			'allowancesUntaxed' => 19.60,
			'allowanceLines' => [
				['allowanceId' => 'alw-1', 'kind' => 'thuiswerk', 'status' => 'paid', 'taxed' => 0.0, 'untaxed' => 19.60, 'wkrCategory' => 'gericht-vrijgesteld'],
				['allowanceId' => 'alw-2', 'kind' => 'telefoon', 'status' => 'norm-unverified', 'taxed' => 0.0, 'untaxed' => 0.0, 'wkrCategory' => null],
			],
		];
		$this->assertSame([], RegisterSchemaValidator::errorsAgainst(['type' => 'object', 'properties' => array_intersect_key($payslip['properties'], $fields)], $fields));
	}//end testThePayslipFieldsTheRunWritesAreDeclared()

	/**
	 * The run total, the claim stamps and a WKR row are valid.
	 *
	 * @return void
	 */
	public function testTheRunTotalClaimStampAndWkrRowAreValid(): void {
		$run = RegisterSchemaValidator::schema('PayrollRun');
		$this->assertArrayHasKey('totalReimbursements', $run['properties']);

		$expense = RegisterSchemaValidator::schema('Expense');
		$this->assertSame(['payroll', 'direct'], $expense['properties']['reimbursementRoute']['enum']);
		$stamp = ['reimbursementRoute' => 'payroll', 'payrollRunId' => 'run-5', 'paidInPeriod' => '2026-05'];
		$this->assertSame([], RegisterSchemaValidator::errorsAgainst(['type' => 'object', 'properties' => array_intersect_key($expense['properties'], $stamp)], $stamp));

		$wkr = ['administrationId' => 'ADM-001', 'year' => 2026, 'date' => '2026-05-31', 'description' => 'Thuiswerkvergoeding 2026-05', 'amount' => 19.60, 'wkrCategory' => 'gericht-vrijgesteld', 'employeeId' => '0127394a-be27-48b4-a592-b6a41774b221', 'sourceReference' => 'allowance:alw-1:2026-05'];
		$this->assertSame([], RegisterSchemaValidator::errors('WkrDeclaration', $wkr));
	}//end testTheRunTotalClaimStampAndWkrRowAreValid()

	/**
	 * An allowance is activated through a guarded transition, and the guard
	 * refuses the person who drafted it.
	 *
	 * @return void
	 */
	public function testActivationIsRefusedToTheDrafter(): void {
		$schema = RegisterSchemaValidator::schema('RecurringAllowance');
		$lifecycle = $schema['configuration']['x-openregister-lifecycle'];
		$this->assertSame('draft', $lifecycle['initial']);
		$this->assertSame(['draft'], $lifecycle['transitions']['activate']['from']);
		$this->assertSame('active', $lifecycle['transitions']['activate']['to']);
		$this->assertSame(NoSelfApprovalGuard::class, $lifecycle['transitions']['activate']['requires']);
		$this->assertSame('ended', $lifecycle['transitions']['end']['to']);

		$allowance = ['employeeId' => 'emp-1', 'proposedBy' => 'hr-demo', 'userId' => 'pjansen', 'status' => 'draft'];
		$guard = new NoSelfApprovalGuard();
		$this->assertFalse($guard->check($allowance, 'activate', 'hr-demo')->isAllowed());
		$this->assertFalse($guard->check($allowance, 'activate', 'pjansen')->isAllowed());
		$this->assertTrue($guard->check($allowance, 'activate', 'hr-second')->isAllowed());
	}//end testActivationIsRefusedToTheDrafter()

	/**
	 * The home-working norm is a sourced, verified leaf.
	 *
	 * @return void
	 */
	public function testTheHomeWorkingNormIsSourced(): void {
		$tables = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Standards/tables/nl-2026.json'), true);
		$leaf = $tables['parameters']['wkr']['thuiswerkNormPerDag'];

		$this->assertSame(2.45, $leaf['value']);
		$this->assertTrue($leaf['verified']);
		$this->assertStringContainsString('Belastingdienst', $leaf['source']);
	}//end testTheHomeWorkingNormIsSourced()

}//end class
