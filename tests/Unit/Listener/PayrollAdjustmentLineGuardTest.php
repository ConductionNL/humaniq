<?php

/**
 * Unit tests for PayrollAdjustmentLineGuard.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-mutation-lineage/tasks.md#task-2.4
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\PayrollAdjustmentLineGuard;
use PHPUnit\Framework\TestCase;

/**
 * A correction may only name a line of its own original payslip.
 *
 * @spec openspec/changes/payroll-mutation-lineage/specs/payroll-mutation-lineage/spec.md#requirement-corrections-and-mutation-reports-shall-point-at-payslip-lines-req-pml-003
 */
final class PayrollAdjustmentLineGuardTest extends TestCase {

	/**
	 * A line of another employee's payslip is refused.
	 *
	 * @return void
	 */
	public function testALineOfAnotherPayslipIsRefused(): void {
		$refusal = PayrollAdjustmentLineGuard::refusal(
			adjustment: ['originalPayslipId' => 'slip-may-emp-1', 'correctsPayslipLineId' => 'line-9'],
			line: ['id' => 'line-9', 'payslipId' => 'slip-may-emp-2']
		);

		self::assertSame('Een correctie kan alleen een regel van haar eigen oorspronkelijke loonstrook corrigeren.', $refusal);
	}//end testALineOfAnotherPayslipIsRefused()

	/**
	 * A line of the correction's own payslip, or no line at all, is accepted.
	 *
	 * @return void
	 */
	public function testAnOwnLineOrNoLineIsAccepted(): void {
		self::assertNull(PayrollAdjustmentLineGuard::refusal(
			adjustment: ['originalPayslipId' => 'slip-may-emp-1', 'correctsPayslipLineId' => 'line-1'],
			line: ['id' => 'line-1', 'payslipId' => 'slip-may-emp-1']
		));
		self::assertNull(PayrollAdjustmentLineGuard::refusal(adjustment: ['originalPayslipId' => 'slip-may-emp-1'], line: null));
		self::assertNull(PayrollAdjustmentLineGuard::refusal(adjustment: ['originalPayslipId' => 'slip-may-emp-1', 'correctsPayslipLineId' => '  '], line: null));
	}//end testAnOwnLineOrNoLineIsAccepted()

	/**
	 * A line that does not exist, or a correction without an original payslip, is refused.
	 *
	 * @return void
	 */
	public function testAMissingLineOrPayslipIsRefused(): void {
		self::assertSame(
			'De gekozen loonstrookregel bestaat niet.',
			PayrollAdjustmentLineGuard::refusal(adjustment: ['originalPayslipId' => 'slip-1', 'correctsPayslipLineId' => 'gone'], line: null)
		);
		self::assertNotNull(PayrollAdjustmentLineGuard::refusal(
			adjustment: ['correctsPayslipLineId' => 'line-1'],
			line: ['id' => 'line-1', 'payslipId' => 'slip-1']
		));
	}//end testAMissingLineOrPayslipIsRefused()
}//end class
