<?php

/**
 * PayslipRecalculatorTest: a stored payslip is recalculated from its engine input, or not at all.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll\Loonaangifte
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll\Loonaangifte;

use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\Loonaangifte\PayslipRecalculator;
use OCA\Humaniq\Payroll\PayrollCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The recalculation of a stored payslip.
 */
class PayslipRecalculatorTest extends TestCase {

	/**
	 * The anchor input reproduces its wage tax; no input, or an input the
	 * engine cannot run, gives null.
	 *
	 * @return void
	 */
	public function testRecalculation(): void {
		$recalculator = new PayslipRecalculator(new PayrollCalculator(), new NullLogger());
		$input = (new CalculationInput(grossMonthlySalaryCents: 380000, taxTableColor: 'wit', loonheffingskortingToegepast: true, dateOfBirth: '1990-04-12', period: '2026-02', awfTariff: 'low', aofTariff: 'laag', whkPercentage: 1.52))->toArray();

		$result = $recalculator->recalculate(['period' => '2026-02', 'engineInputSnapshot' => $input]);
		self::assertSame([71883, 380000, 380000], [$result?->loonheffingCents, $result?->taxableWageCents, $result?->premiumWageCents]);
		self::assertNull($recalculator->recalculate(['period' => '2026-02']));
		self::assertNull($recalculator->recalculate(['period' => '1999-02', 'engineInputSnapshot' => array_merge($input, ['jurisdiction' => 'XX', 'period' => '1999-02'])]));
	}//end testRecalculation()

}//end class
