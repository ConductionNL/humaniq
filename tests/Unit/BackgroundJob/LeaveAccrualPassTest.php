<?php

/**
 * LeaveAccrualPass test
 *
 * The pass is the accrual job's own summary builder, and the summary is what
 * HR reads after a month closes. Every outcome it records is attributed to
 * whichever employee was most recently begun, so the attribution — not just
 * the counts — is what these tests pin.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\BackgroundJob
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
 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\BackgroundJob;

use OCA\Humaniq\BackgroundJob\LeaveAccrualPass;
use PHPUnit\Framework\TestCase;

/**
 * One accrual pass's state and summary.
 *
 * @covers \OCA\Humaniq\BackgroundJob\LeaveAccrualPass
 */
class LeaveAccrualPassTest extends TestCase {

	/**
	 * A fresh pass carries no month, no lookups and no summary, so a pass
	 * never inherits an earlier month's state.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 */
	public function testAFreshPassCarriesNothing(): void {
		$pass = new LeaveAccrualPass();

		$this->assertSame([], $pass->month);
		$this->assertSame([], $pass->contractsByEmployeeKey);
		$this->assertSame([], $pass->balancesByEmployeeId);
		$this->assertSame([], $pass->summary);
		$this->assertSame('', $pass->employeeLabel(), 'no employee is being processed yet');

	}//end testAFreshPassCarriesNothing()

	/**
	 * `begin()` moves the pass to the next employee, and the label it reports
	 * is the one later outcomes are attributed to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 */
	public function testBeginMovesThePassToTheNextEmployee(): void {
		$pass = new LeaveAccrualPass();

		$pass->begin('Jansen, A.');
		$this->assertSame('Jansen, A.', $pass->employeeLabel());

		$pass->begin('Pietersen, B.');
		$this->assertSame('Pietersen, B.', $pass->employeeLabel());

	}//end testBeginMovesThePassToTheNextEmployee()

	/**
	 * A skip is recorded against the employee being processed, with its reason,
	 * and skips accumulate in the order they happened.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 */
	public function testSkipsAreAttributedToTheCurrentEmployeeAndAccumulate(): void {
		$pass = new LeaveAccrualPass();
		$pass->summary = ['skipped' => []];

		$pass->begin('Jansen, A.');
		$pass->skip('no active contract');
		$pass->begin('Pietersen, B.');
		$pass->skip('hours unknown');

		$this->assertSame(
			[
				[
					'employee' => 'Jansen, A.',
					'reason'   => 'no active contract',
				],
				[
					'employee' => 'Pietersen, B.',
					'reason'   => 'hours unknown',
				],
			],
			$pass->summary['skipped']
		);

	}//end testSkipsAreAttributedToTheCurrentEmployeeAndAccumulate()

	/**
	 * A written balance is recorded under its own outcome key, carrying the
	 * saved balance's id — so `provisioned` and `accrued` stay separable.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 */
	public function testAWrittenBalanceIsRecordedUnderItsOwnOutcome(): void {
		$pass = new LeaveAccrualPass();
		$pass->summary = [
			'provisioned' => [],
			'accrued'     => [],
		];

		$pass->begin('Jansen, A.');
		$pass->written('provisioned', 'lb-1');
		$pass->begin('Pietersen, B.');
		$pass->written('accrued', 'lb-2');

		$this->assertSame(
			[
				[
					'employee'       => 'Jansen, A.',
					'leaveBalanceId' => 'lb-1',
				],
			],
			$pass->summary['provisioned']
		);
		$this->assertSame(
			[
				[
					'employee'       => 'Pietersen, B.',
					'leaveBalanceId' => 'lb-2',
				],
			],
			$pass->summary['accrued']
		);

	}//end testAWrittenBalanceIsRecordedUnderItsOwnOutcome()

	/**
	 * An employee already accrued this month counts as a no-op, which is a
	 * tally rather than a list — the idempotent re-run case.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 */
	public function testAnAlreadyAccruedEmployeeCountsAsANoop(): void {
		$pass = new LeaveAccrualPass();
		$pass->summary = ['noop' => 0];

		$pass->begin('Jansen, A.');
		$pass->noop();
		$pass->begin('Pietersen, B.');
		$pass->noop();

		$this->assertSame(2, $pass->summary['noop']);

	}//end testAnAlreadyAccruedEmployeeCountsAsANoop()

}//end class
