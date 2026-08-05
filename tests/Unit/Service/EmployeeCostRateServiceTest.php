<?php

/**
 * EmployeeCostRateService tests
 *
 * The rate these tests pin reaches a statutory IV3 submission through
 * Shillinq, so they assert the ARITHMETIC against hand-computed figures rather
 * than against whatever the implementation happens to return, and they pin the
 * two failure modes that would be invisible in production: a wage silently
 * standing in for a cost, and an unexplained override.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Hrmq\Service\EmployeeCostRateService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EmployeeCostRateService.
 */
class EmployeeCostRateServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var EmployeeCostRateService
     */
    private EmployeeCostRateService $service;


    /**
     * Build the service.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmployeeCostRateService();

    }//end setUp()


    /**
     * A payslip with 160 worked hours: gross 4000.00 + wnv 700.00 + zvw 244.00
     * = 4944.00 loaded, over 160h = 30.90/h = 3090 cents.
     *
     * @return void
     */
    public function testDerivesLoadedCostFromPayslipHours(): void
    {
        $rate = $this->service->resolve(
            employee: [],
            payslip: [
                'period'                  => '2026-07',
                'grossPay'                => 4000.00,
                'werknemersverzekeringen' => 700.00,
                'zvw'                     => 244.00,
                'hoursWorked'             => 160,
            ]
        );

        $this->assertNotNull($rate);
        $this->assertSame(3090, $rate['centsPerHour']);
        $this->assertSame(EmployeeCostRateService::SOURCE_DERIVED, $rate['source']);
        $this->assertStringContainsString('2026-07', $rate['basis']);

    }//end testDerivesLoadedCostFromPayslipHours()


    /**
     * The employer charges MUST be in the numerator. Costing at the gross wage
     * alone is the defect this service exists to prevent, and the two figures
     * differ by exactly the employer burden — 4000.00/160 = 25.00/h would be
     * the wrong answer, and it is a plausible-looking one.
     *
     * @return void
     */
    public function testEmployerChargesAreIncludedNotJustGross(): void
    {
        $payslip = [
            'grossPay'                => 4000.00,
            'werknemersverzekeringen' => 700.00,
            'zvw'                     => 244.00,
            'hoursWorked'             => 160,
        ];

        $rate = $this->service->resolve(employee: [], payslip: $payslip);

        $grossOnlyCents = (int) round((4000.00 * 100) / 160);
        $this->assertSame(2500, $grossOnlyCents, 'guard: the gross-only rate is 25.00/h');
        $this->assertNotSame(
            $grossOnlyCents,
            $rate['centsPerHour'],
            'the rate must not equal the gross-only figure — that would mean the employer charges were dropped'
        );
        $this->assertGreaterThan($grossOnlyCents, $rate['centsPerHour']);

    }//end testEmployerChargesAreIncludedNotJustGross()


    /**
     * With no hoursWorked on the payslip, contracted hours stand in:
     * 36h/week x 52 / 12 = 156h. 4944.00 / 156 = 31.6923/h -> 3169 cents.
     *
     * @return void
     */
    public function testFallsBackToContractedHoursWhenPayslipHasNone(): void
    {
        $rate = $this->service->resolve(
            employee: [],
            payslip: [
                'grossPay'                => 4000.00,
                'werknemersverzekeringen' => 700.00,
                'zvw'                     => 244.00,
            ],
            hoursPerWeek: 36.0
        );

        $this->assertNotNull($rate);
        $this->assertSame(3169, $rate['centsPerHour']);

    }//end testFallsBackToContractedHoursWhenPayslipHasNone()


    /**
     * A set override wins over the derived value, and carries its reason
     * through as the basis.
     *
     * @return void
     */
    public function testOverrideWinsOverDerivedValue(): void
    {
        $rate = $this->service->resolve(
            employee: [
                'hourlyCostRateOverrideCents'  => 4500,
                'hourlyCostRateOverrideReason' => 'Seconded — billed at the partner rate',
            ],
            payslip: [
                'grossPay'                => 4000.00,
                'werknemersverzekeringen' => 700.00,
                'zvw'                     => 244.00,
                'hoursWorked'             => 160,
            ]
        );

        $this->assertSame(4500, $rate['centsPerHour']);
        $this->assertSame(EmployeeCostRateService::SOURCE_OVERRIDE, $rate['source']);
        $this->assertSame('Seconded — billed at the partner rate', $rate['basis']);

    }//end testOverrideWinsOverDerivedValue()


    /**
     * An override amount with no reason is REFUSED. Neither silently using it
     * nor silently ignoring it is acceptable: one puts an unauditable number
     * into a statutory submission, the other hides a deliberate decision.
     *
     * @return void
     */
    public function testUnexplainedOverrideIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must say why it exists/');

        $this->service->resolve(
            employee: ['hourlyCostRateOverrideCents' => 4500],
            payslip: ['grossPay' => 4000.00, 'hoursWorked' => 160]
        );

    }//end testUnexplainedOverrideIsRefused()


    /**
     * A blank-string reason is as unexplained as a missing one.
     *
     * @return void
     */
    public function testWhitespaceOnlyOverrideReasonIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->resolve(
            employee: [
                'hourlyCostRateOverrideCents'  => 4500,
                'hourlyCostRateOverrideReason' => '   ',
            ]
        );

    }//end testWhitespaceOnlyOverrideReasonIsRefused()


    /**
     * No payslip and no override yields null — an explicit "cannot cost this
     * yet". A zero would book the work as free, which is worse than an
     * absence because it looks like an answer.
     *
     * @return void
     */
    public function testReturnsNullWhenNothingToDeriveFrom(): void
    {
        $this->assertNull($this->service->resolve(employee: []));

    }//end testReturnsNullWhenNothingToDeriveFrom()


    /**
     * Zero hours must not divide — a payslip with no hours and no contract
     * yields null rather than an infinity or a division warning.
     *
     * @return void
     */
    public function testZeroHoursYieldsNullRatherThanDividingByZero(): void
    {
        $rate = $this->service->resolve(
            employee: [],
            payslip: ['grossPay' => 4000.00, 'hoursWorked' => 0],
            hoursPerWeek: 0.0
        );

        $this->assertNull($rate);

    }//end testZeroHoursYieldsNullRatherThanDividingByZero()


    /**
     * A payslip with no gross pay cannot yield a rate.
     *
     * @return void
     */
    public function testZeroGrossYieldsNull(): void
    {
        $this->assertNull(
            $this->service->resolve(employee: [], payslip: ['grossPay' => 0, 'hoursWorked' => 160])
        );

    }//end testZeroGrossYieldsNull()


    /**
     * A negative override is a data error, not a credit.
     *
     * @return void
     */
    public function testNegativeOverrideIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must not be negative/');

        $this->service->resolve(
            employee: [
                'hourlyCostRateOverrideCents'  => -100,
                'hourlyCostRateOverrideReason' => 'typo',
            ]
        );

    }//end testNegativeOverrideIsRefused()


    /**
     * A missing Zvw line is treated as zero rather than voiding the rate:
     * 4000.00 + 700.00 = 4700.00 over 160h = 29.375 -> 2938 cents (half-up).
     *
     * @return void
     */
    public function testMissingEmployerChargeLineIsTreatedAsZero(): void
    {
        $rate = $this->service->resolve(
            employee: [],
            payslip: [
                'grossPay'                => 4000.00,
                'werknemersverzekeringen' => 700.00,
                'hoursWorked'             => 160,
            ]
        );

        $this->assertSame(2938, $rate['centsPerHour']);

    }//end testMissingEmployerChargeLineIsTreatedAsZero()


    /**
     * The employee's gross hourly WAGE is never consulted — passing one that
     * would produce a different, wrong answer must not change the result.
     *
     * @return void
     */
    public function testGrossHourlyWageIsNeverUsedAsACostRate(): void
    {
        $payslip = [
            'grossPay'                => 4000.00,
            'werknemersverzekeringen' => 700.00,
            'zvw'                     => 244.00,
            'hoursWorked'             => 160,
        ];

        $withWage = $this->service->resolve(
            employee: ['hourlyWage' => 25.00, 'grossMonthlySalary' => 4000.00],
            payslip: $payslip
        );
        $without  = $this->service->resolve(employee: [], payslip: $payslip);

        $this->assertSame($without['centsPerHour'], $withWage['centsPerHour']);
        $this->assertSame(3090, $withWage['centsPerHour']);

    }//end testGrossHourlyWageIsNeverUsedAsACostRate()
}//end class
