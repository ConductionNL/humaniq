<?php

/**
 * Unit tests for the payroll-to-bank IBAN-presence check (NlNetPayChecks).
 *
 * Pins the `nl-netpay-iban-present` predicate: a payslip whose period has no
 * payable (approved/posted) run is always compliant, an unresolvable
 * employee or one without an IBAN violates on a payable period, and a
 * resolved employee with an IBAN is compliant.
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
 * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-008
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlNetPayChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlNetPayChecks.
 *
 * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-008
 */
class NlNetPayChecksTest extends TestCase
{


    /**
     * The registered Payslip predicates, keyed by rule id.
     *
     * @var array<string, callable>
     */
    private array $checks;


    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->checks = NlNetPayChecks::checks()['Payslip'];

    }//end setUp()


    /**
     * A minimal Payslip fixture; each test overrides the fields it exercises.
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function payslip(array $overrides=[]): array
    {
        return array_merge(
            [
                'employeeId' => 'employee-jansen',
                'period'     => '2026-05',
            ],
            $overrides
        );

    }//end payslip()


    /**
     * A `context['netpay']` fixture.
     *
     * @param array<string, bool> $ibanByEmployeeKey Employee key => IBAN-present map.
     * @param array<int, string>  $payablePeriods    Periods with a payable run.
     *
     * @return array<string, mixed>
     */
    private function context(array $ibanByEmployeeKey=[], array $payablePeriods=['2026-05']): array
    {
        return [
            'netpay' => [
                'ibanByEmployeeKey' => $ibanByEmployeeKey,
                'payablePeriods'    => $payablePeriods,
            ],
        ];

    }//end context()


    /**
     * @return void
     */
    public function testCompliantWhenEmployeeHasIbanOnAPayablePeriod(): void
    {
        $payslip = $this->payslip();
        $context = $this->context(['employee-jansen' => true]);

        $this->assertTrue(($this->checks['nl-netpay-iban-present'])($payslip, $context));

    }//end testCompliantWhenEmployeeHasIbanOnAPayablePeriod()


    /**
     * @return void
     */
    public function testViolatesWhenEmployeeHasNoIbanOnAPayablePeriod(): void
    {
        $payslip = $this->payslip();
        $context = $this->context(['employee-jansen' => false]);

        $this->assertFalse(($this->checks['nl-netpay-iban-present'])($payslip, $context));

    }//end testViolatesWhenEmployeeHasNoIbanOnAPayablePeriod()


    /**
     * @return void
     */
    public function testViolatesWhenEmployeeDoesNotResolveAtAllOnAPayablePeriod(): void
    {
        $payslip = $this->payslip();
        $context = $this->context([]);

        $this->assertFalse(($this->checks['nl-netpay-iban-present'])($payslip, $context));

    }//end testViolatesWhenEmployeeDoesNotResolveAtAllOnAPayablePeriod()


    /**
     * @return void
     */
    public function testCompliantWhenPeriodHasNoPayableRun(): void
    {
        $payslip = $this->payslip(['period' => '2026-06']);
        $context = $this->context(['employee-jansen' => false], ['2026-05']);

        $this->assertTrue(($this->checks['nl-netpay-iban-present'])($payslip, $context));

    }//end testCompliantWhenPeriodHasNoPayableRun()


    /**
     * @return void
     */
    public function testViolatesWhenEmployeeIdIsBlank(): void
    {
        $payslip = $this->payslip(['employeeId' => '']);
        $context = $this->context(['employee-jansen' => true]);

        $this->assertFalse(($this->checks['nl-netpay-iban-present'])($payslip, $context));

    }//end testViolatesWhenEmployeeIdIsBlank()


    /**
     * @return void
     */
    public function testMissingContextDefaultsToCompliantSinceNoPeriodIsPayable(): void
    {
        $payslip = $this->payslip();

        $this->assertTrue(($this->checks['nl-netpay-iban-present'])($payslip, []));

    }//end testMissingContextDefaultsToCompliantSinceNoPeriodIsPayable()


    /**
     * @return void
     */
    public function testResolvesByEmployeeNumberKeyToo(): void
    {
        $payslip = $this->payslip(['employeeId' => 'EMP-NL-0001']);
        $context = $this->context(['EMP-NL-0001' => true]);

        $this->assertTrue(($this->checks['nl-netpay-iban-present'])($payslip, $context));

    }//end testResolvesByEmployeeNumberKeyToo()


}//end class
