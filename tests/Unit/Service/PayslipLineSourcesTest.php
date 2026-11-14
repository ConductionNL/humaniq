<?php

/**
 * Unit tests for PayslipLineSources.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-mutation-lineage/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Payroll\SickPayResult;
use OCA\Humaniq\Service\PayslipLineSources;
use PHPUnit\Framework\TestCase;

/**
 * The sources read from a folded slip.
 *
 * @spec openspec/changes/payroll-mutation-lineage/specs/payroll-mutation-lineage/spec.md#requirement-each-calculated-payslip-shall-carry-one-line-per-component-with-its-source-req-pml-001
 */
final class PayslipLineSourcesTest extends TestCase {

	/**
	 * Every fold that names a source gives its own line, with signed amounts.
	 *
	 * @return void
	 */
	public function testEveryFoldNamesItsSource(): void {
		$sick = (new \ReflectionClass(SickPayResult::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(SickPayResult::class, 'payableGrossCents'))->setValue($sick, 266000);

		$sources = PayslipLineSources::fromSlip(
			slip: [
				'regularWageCents' => 380000,
				'sickCase' => ['id' => 'sick-1'],
				'sickResult' => $sick,
				'assetAssignment' => ['id' => 'ca-1'],
				'bijtellingCents' => 50000,
				'retroAdjustment' => ['sources' => [['sourceId' => 'adj-1', 'cents' => 10000], ['sourceId' => 'adj-2', 'cents' => -2500]]],
				'leaveSources' => [['sourceId' => 'txn-1', 'cents' => 20000]],
				'loonbeslag' => ['id' => 'lb-1'],
				'loonbeslagDeductionCents' => 12345,
			],
			salarySource: ['component' => 'salary', 'amountCents' => 380000, 'sourceSchema' => 'Employee', 'sourceId' => 'emp-1', 'sourceKind' => 'record-edit']
		);

		self::assertSame(
			[
				['salary', 380000, 'Employee', 'emp-1', 'record-edit'],
				['sickPay', -114000, 'SickLeaveCase', 'sick-1', 'case'],
				['bijtelling', 50000, 'AssetAssignment', 'ca-1', 'assignment'],
				['retroAdjustment', 10000, 'PayrollAdjustment', 'adj-1', 'correction'],
				['retroAdjustment', -2500, 'PayrollAdjustment', 'adj-2', 'correction'],
				['leaveBuySell', 20000, 'LeaveTransaction', 'txn-1', 'leave-transaction'],
				['loonbeslag', -12345, 'Loonbeslag', 'lb-1', 'garnishment'],
			],
			array_map(static fn (array $s): array => [$s['component'], $s['amountCents'], $s['sourceSchema'], $s['sourceId'], $s['sourceKind']], $sources)
		);
	}//end testEveryFoldNamesItsSource()

	/**
	 * An hourly employee's pay is split over the paid timesheets and adds up.
	 *
	 * @return void
	 */
	public function testHourlyPayIsSplitOverTimesheetsAndAddsUp(): void {
		$sources = PayslipLineSources::fromSlip(
			slip: ['hoursPay' => ['hourlyCents' => 100001, 'timeCredits' => [['timesheetId' => 'ts-1', 'hours' => 1.0], ['timesheetId' => 'ts-2', 'hours' => 2.0]]]],
			salarySource: null
		);

		self::assertSame(['ts-1', 'ts-2'], array_column($sources, 'sourceId'));
		self::assertSame([33334, 66667], array_column($sources, 'amountCents'));
		self::assertSame(100001, array_sum(array_column($sources, 'amountCents')));
		self::assertSame([], PayslipLineSources::fromSlip(slip: ['loonbeslag' => ['id' => 'lb-1'], 'loonbeslagDeductionCents' => 0], salarySource: null));
	}//end testHourlyPayIsSplitOverTimesheetsAndAddsUp()
}//end class
