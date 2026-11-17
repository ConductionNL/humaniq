<?php

/**
 * Unit tests for PayslipLineCauses.
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
 * @spec openspec/changes/payroll-mutation-lineage/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\PayslipLineCauses;
use PHPUnit\Framework\TestCase;

/**
 * What differs between two payslips' lines.
 *
 * @spec openspec/changes/payroll-mutation-lineage/specs/payroll-mutation-lineage/spec.md#requirement-corrections-and-mutation-reports-shall-point-at-payslip-lines-req-pml-003
 */
final class PayslipLineCausesTest extends TestCase {

	/**
	 * A line.
	 *
	 * @param string      $component The component.
	 * @param float       $amount    The amount.
	 * @param string      $schema    The source schema.
	 * @param string      $id        The source id.
	 * @param string|null $kind      The source kind.
	 *
	 * @return array<string, mixed>
	 */
	private static function line(string $component, float $amount, string $schema, string $id, ?string $kind = null): array {
		return ['component' => $component, 'amount' => $amount, 'sourceSchema' => $schema, 'sourceId' => $id, 'sourceKind' => $kind];
	}//end line()

	/**
	 * A new raise is the one cause: the salary line now names the raise.
	 *
	 * @return void
	 */
	public function testANewRaiseIsTheCause(): void {
		$causes = PayslipLineCauses::between(
			fromLines: [self::line('salary', 3800.00, 'Employee', 'emp-1', 'record-edit'), self::line('bijtelling', 300.00, 'AssetAssignment', 'aa-1', 'assignment')],
			toLines: [self::line('salary', 3876.00, 'CompAdjustment', 'raise-1', 'raise'), self::line('bijtelling', 300.00, 'AssetAssignment', 'aa-1', 'assignment')]
		);

		self::assertSame(
			[
				['change' => 'new', 'component' => 'salary', 'sourceSchema' => 'CompAdjustment', 'sourceId' => 'raise-1', 'sourceKind' => 'raise', 'amountBefore' => null, 'amountAfter' => 3876.0],
				['change' => 'gone', 'component' => 'salary', 'sourceSchema' => 'Employee', 'sourceId' => 'emp-1', 'sourceKind' => 'record-edit', 'amountBefore' => 3800.0, 'amountAfter' => null],
			],
			$causes
		);
	}//end testANewRaiseIsTheCause()

	/**
	 * The same source with another amount is changed; equal lines are no cause.
	 *
	 * @return void
	 */
	public function testAnAmountChangeOnTheSameSourceIsChanged(): void {
		$causes = PayslipLineCauses::between(
			fromLines: [self::line('loonbeslag', -200.00, 'Loonbeslag', 'lb-1'), self::line('salary', 3800.00, 'Employee', 'emp-1')],
			toLines: [self::line('loonbeslag', -250.10, 'Loonbeslag', 'lb-1'), self::line('salary', 3800.00, 'Employee', 'emp-1')]
		);

		self::assertCount(1, $causes);
		self::assertSame('changed', $causes[0]['change']);
		self::assertSame(-200.0, $causes[0]['amountBefore']);
		self::assertSame(-250.1, $causes[0]['amountAfter']);
	}//end testAnAmountChangeOnTheSameSourceIsChanged()

	/**
	 * A payslip without lines leaves the causes unknown, never empty.
	 *
	 * @return void
	 */
	public function testAnOldPayslipWithoutLinesYieldsNull(): void {
		self::assertNull(PayslipLineCauses::between(fromLines: [], toLines: [self::line('salary', 3800.00, 'Employee', 'emp-1')]));
		self::assertNull(PayslipLineCauses::between(fromLines: [self::line('salary', 3800.00, 'Employee', 'emp-1')], toLines: []));
		self::assertSame([], PayslipLineCauses::between(fromLines: [self::line('salary', 3800.00, 'Employee', 'emp-1')], toLines: [self::line('salary', 3800.00, 'Employee', 'emp-1')]));
	}//end testAnOldPayslipWithoutLinesYieldsNull()
}//end class
