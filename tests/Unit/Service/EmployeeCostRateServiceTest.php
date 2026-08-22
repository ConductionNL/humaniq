<?php

/**
 * EmployeeCostRateService tests
 *
 * The rate these tests pin reaches a statutory IV3 submission through
 * Shillinq, so they assert the ARITHMETIC against hand-computed figures rather
 * than against whatever the implementation happens to return, and they pin the
 * failure modes that would be invisible in production: a wage silently
 * standing in for a cost, an unexplained override or addition, and a base
 * contaminated by period noise.
 *
 * The proforma calculator is stubbed so the fixtures state the gross-to-net
 * figures outright. That keeps these tests about the COSTING arithmetic —
 * the tax chain itself is already covered by the payroll-core-engine suite,
 * and re-deriving it here would make a change in the tax tables look like a
 * costing regression.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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

namespace OCA\Humaniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Humaniq\Service\EmployeeCostRateService;
use OCA\Humaniq\Service\HourlyCostAdditions;
use OCA\Humaniq\Service\ProformaPayslipService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for EmployeeCostRateService.
 */
class EmployeeCostRateServiceTest extends TestCase {

	/**
	 * A 36h/week contract. 36 x 52 / 12 = 156 hours behind one month.
	 *
	 * @var array<string, mixed>
	 */
	private const CONTRACT = ['hoursPerWeek' => 36.0];

	/**
	 * Costing period.
	 *
	 * @var string
	 */
	private const PERIOD = '2026-07';

	/**
	 * Params the stubbed proforma was last called with.
	 *
	 * @var array<string, mixed>
	 */
	private array $lastProformaParams = [];

	/**
	 * Build the service with a proforma stub returning a fixed breakdown.
	 *
	 * @param array<string, mixed>|null $breakdown Breakdown to return, or null to throw.
	 *
	 * @return EmployeeCostRateService
	 */
	private function makeService(?array $breakdown = null): EmployeeCostRateService {
		$breakdown ??= [
			'grossPay' => 4000.00,
			'werknemersverzekeringen' => 700.00,
			'zvw' => 244.00,
		];

		$proforma = $this->createMock(ProformaPayslipService::class);
		$proforma->method('simulate')->willReturnCallback(
			function (array $params) use ($breakdown): array {
				$this->lastProformaParams = $params;
				if ($breakdown === []) {
					throw new RuntimeException('Belastingtabel niet beschikbaar');
				}

				return $breakdown;
			}
		);

		return new EmployeeCostRateService($proforma, new HourlyCostAdditions(), new NullLogger());
	}//end makeService()

	/**
	 * Loaded cost 4000.00 + 700.00 + 244.00 = 4944.00 over 156 contracted
	 * hours = 31.6923/h -> 3169 cents.
	 *
	 * @return void
	 */
	public function testDerivesLoadedCostFromTheContractProforma(): void {
		$rate = $this->makeService()->resolve(
			employee: ['grossMonthlySalary' => 4000.00],
			contract: self::CONTRACT,
			period: self::PERIOD
		);

		$this->assertNotNull($rate);
		$this->assertSame(3169, $rate['totalCentsPerHour']);
		$this->assertSame(EmployeeCostRateService::SOURCE_CONTRACT, $rate['wageSource']);
		$this->assertStringContainsString(self::PERIOD, $rate['wageBasis']);

	}//end testDerivesLoadedCostFromTheContractProforma()

	/**
	 * The employer charges MUST be in the numerator. Costing at the gross wage
	 * alone is the defect this service exists to prevent, and 4000.00/156 =
	 * 25.64/h is the wrong answer — a plausible-looking one.
	 *
	 * @return void
	 */
	public function testEmployerChargesAreIncludedNotJustGross(): void {
		$rate = $this->makeService()->resolve(
			employee: ['grossMonthlySalary' => 4000.00],
			contract: self::CONTRACT,
			period: self::PERIOD
		);

		$grossOnlyCents = (int)round((4000.00 * 100) / 156);
		$this->assertSame(2564, $grossOnlyCents, 'guard: the gross-only rate is 25.64/h');
		$this->assertNotSame(
			$grossOnlyCents,
			$rate['totalCentsPerHour'],
			'the rate must not equal the gross-only figure — that would mean the employer charges were dropped'
		);
		$this->assertGreaterThan($grossOnlyCents, $rate['totalCentsPerHour']);

	}//end testEmployerChargesAreIncludedNotJustGross()

	/**
	 * THE reason the contract is the basis. The proforma must be asked for a
	 * PLAIN month: no bijzondere beloning and no part-time re-scaling, because
	 * the contract's own hours are already the denominator. A base carrying a
	 * bonus or a one-off would make the same hour cost a different amount
	 * depending on which month it was logged in.
	 *
	 * @return void
	 */
	public function testProformaIsAskedForAPlainMonthWithNoOneOffs(): void {
		$this->makeService()->resolve(
			employee: ['grossMonthlySalary' => 4000.00, 'taxTableColor' => 'wit', 'dateOfBirth' => '1985-03-02'],
			contract: self::CONTRACT,
			period: self::PERIOD
		);

		$this->assertSame(0.0, $this->lastProformaParams['bijzonder'], 'no one-off may enter the cost base');
		$this->assertSame(1.0, $this->lastProformaParams['parttime'], 'the contract hours are the denominator');
		$this->assertSame(4000.00, $this->lastProformaParams['gross']);
		$this->assertSame(self::PERIOD, $this->lastProformaParams['period']);
		$this->assertSame('1985-03-02', $this->lastProformaParams['dateOfBirth']);

	}//end testProformaIsAskedForAPlainMonthWithNoOneOffs()

	/**
	 * A contract-derived base contains no overtime, so it declares itself
	 * unblended and an overtime addition on top is legitimate — which is what
	 * makes "one rate plus a named addition" work at all.
	 *
	 * @return void
	 */
	public function testContractBaseDoesNotBlendOvertimeSoOvertimeCanBeAdded(): void {
		$rate = $this->makeService()->resolve(
			employee: ['grossMonthlySalary' => 4000.00],
			contract: self::CONTRACT,
			period: self::PERIOD,
			extraAdditions: [
				[
					'key' => EmployeeCostRateService::ADDITION_OVERTIME,
					'centsPerHour' => 1200,
					'source' => 'manual',
					'basis' => '150% CAO overtime premium',
				],
			]
		);

		$this->assertFalse($rate['wageBaseBlendsOvertime']);
		$this->assertSame((3169 + 1200), $rate['totalCentsPerHour']);

	}//end testContractBaseDoesNotBlendOvertimeSoOvertimeCanBeAdded()

	/**
	 * The double-count guard itself, exercised directly: a base that DOES
	 * blend overtime refuses an overtime addition. A payslip-style base
	 * divides total pay by total hours, so the premium is already averaged
	 * into every hour and adding it again charges it twice.
	 *
	 * @return void
	 */
	public function testOvertimeAdditionIsRefusedOnABlendedBase(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/charged twice/');

		$this->makeService()->assertAdditionsCompatible(
			additions: [
				[
					'key' => EmployeeCostRateService::ADDITION_OVERTIME,
					'centsPerHour' => 1200,
					'source' => 'manual',
					'basis' => '150% CAO overtime premium',
				],
			],
			wageBaseBlendsOvertime: true
		);

	}//end testOvertimeAdditionIsRefusedOnABlendedBase()

	/**
	 * A non-overtime addition on a blended base is fine — only the overtime
	 * component double-counts.
	 *
	 * @return void
	 */
	public function testNonOvertimeAdditionIsAllowedOnABlendedBase(): void {
		$this->makeService()->assertAdditionsCompatible(
			additions: [
				['key' => 'overhead', 'centsPerHour' => 850, 'source' => 'shillinq', 'basis' => 'Pool/hours'],
			],
			wageBaseBlendsOvertime: true
		);

		$this->expectNotToPerformAssertions();

	}//end testNonOvertimeAdditionIsAllowedOnABlendedBase()

	/**
	 * The total is the wage base PLUS every addition, and the wage base stays
	 * separately visible so a cost can be explained line by line.
	 * 3169 + 850 + 210 = 4229.
	 *
	 * @return void
	 */
	public function testTotalIsWagePlusAdditions(): void {
		$rate = $this->makeService()->resolve(
			employee: [
				'grossMonthlySalary' => 4000.00,
				'hourlyCostAdditions' => [
					[
						'key' => 'overhead',
						'centsPerHour' => 850,
						'source' => 'shillinq',
						'basis' => 'Overhead pool 4900 over billable hours 2026-Q2',
					],
					[
						'key' => 'equipment',
						'centsPerHour' => 210,
						'source' => 'manual',
						'basis' => 'Laptop + phone, amortised over 3 years',
					],
				],
			],
			contract: self::CONTRACT,
			period: self::PERIOD
		);

		$this->assertSame(4229, $rate['totalCentsPerHour']);
		$this->assertSame(3169, $rate['wageCostCents'], 'the wage base stays separately visible');
		$this->assertCount(2, $rate['additions']);
		$this->assertSame('shillinq', $rate['additions'][0]['source']);

	}//end testTotalIsWagePlusAdditions()

	/**
	 * A caller that computes additions (Shillinq, from the ledger) can pass
	 * them in, and they merge with the employee's own stored ones.
	 *
	 * @return void
	 */
	public function testCallerSuppliedAdditionsMergeWithStoredOnes(): void {
		$rate = $this->makeService()->resolve(
			employee: [
				'grossMonthlySalary' => 4000.00,
				'hourlyCostAdditions' => [
					['key' => 'equipment', 'centsPerHour' => 210, 'source' => 'manual', 'basis' => 'Laptop'],
				],
			],
			contract: self::CONTRACT,
			period: self::PERIOD,
			extraAdditions: [
				['key' => 'overhead', 'centsPerHour' => 850, 'source' => 'shillinq', 'basis' => 'Pool/hours'],
			]
		);

		$this->assertCount(2, $rate['additions']);
		$this->assertSame((3169 + 210 + 850), $rate['totalCentsPerHour']);

	}//end testCallerSuppliedAdditionsMergeWithStoredOnes()

	/**
	 * A set override wins over the derived value and carries its reason as the
	 * basis.
	 *
	 * @return void
	 */
	public function testOverrideWinsOverDerivedValue(): void {
		$rate = $this->makeService()->resolve(
			employee: [
				'grossMonthlySalary' => 4000.00,
				'hourlyCostRateOverrideCents' => 4500,
				'hourlyCostRateOverrideReason' => 'Seconded — billed at the partner rate',
			],
			contract: self::CONTRACT,
			period: self::PERIOD
		);

		$this->assertSame(4500, $rate['totalCentsPerHour']);
		$this->assertSame(EmployeeCostRateService::SOURCE_OVERRIDE, $rate['wageSource']);
		$this->assertSame('Seconded — billed at the partner rate', $rate['wageBasis']);

	}//end testOverrideWinsOverDerivedValue()

	/**
	 * An override amount with no reason is REFUSED. Neither silently using it
	 * nor silently ignoring it is acceptable: one puts an unauditable number
	 * into a statutory submission, the other hides a deliberate decision.
	 *
	 * @return void
	 */
	public function testUnexplainedOverrideIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/must say why it exists/');

		$this->makeService()->resolve(
			employee: ['hourlyCostRateOverrideCents' => 4500],
			contract: self::CONTRACT,
			period: self::PERIOD
		);

	}//end testUnexplainedOverrideIsRefused()

	/**
	 * A blank-string reason is as unexplained as a missing one.
	 *
	 * @return void
	 */
	public function testWhitespaceOnlyOverrideReasonIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->makeService()->resolve(
			employee: [
				'hourlyCostRateOverrideCents' => 4500,
				'hourlyCostRateOverrideReason' => '   ',
			]
		);

	}//end testWhitespaceOnlyOverrideReasonIsRefused()

	/**
	 * A negative override is a data error, not a credit.
	 *
	 * @return void
	 */
	public function testNegativeOverrideIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/must not be negative/');

		$this->makeService()->resolve(
			employee: [
				'hourlyCostRateOverrideCents' => -100,
				'hourlyCostRateOverrideReason' => 'typo',
			]
		);

	}//end testNegativeOverrideIsRefused()

	/**
	 * No contract and no override yields null — an explicit "cannot cost this
	 * yet". A zero would book the work as free, which is worse than an absence
	 * because it looks like an answer.
	 *
	 * @return void
	 */
	public function testReturnsNullWithoutAContract(): void {
		$this->assertNull(
			$this->makeService()->resolve(employee: ['grossMonthlySalary' => 4000.00], period: self::PERIOD)
		);

	}//end testReturnsNullWithoutAContract()

	/**
	 * Zero contracted hours must not divide.
	 *
	 * @return void
	 */
	public function testZeroContractedHoursYieldsNull(): void {
		$this->assertNull(
			$this->makeService()->resolve(
				employee: ['grossMonthlySalary' => 4000.00],
				contract: ['hoursPerWeek' => 0],
				period: self::PERIOD
			)
		);

	}//end testZeroContractedHoursYieldsNull()

	/**
	 * A contract the calculator cannot price yields null, not a zero — for
	 * instance a period whose tax tables are not published yet.
	 *
	 * @return void
	 */
	public function testProformaFailureYieldsNullNotZero(): void {
		$this->assertNull(
			$this->makeService(breakdown: [])->resolve(
				employee: ['grossMonthlySalary' => 4000.00],
				contract: self::CONTRACT,
				period: '2099-01'
			)
		);

	}//end testProformaFailureYieldsNullNotZero()

	/**
	 * With no recorded monthly salary the contract's gross hourly wage
	 * reconstitutes one, so an hourly-paid employment still costs out:
	 * 25.00 x 156 = 3900.00 priced by the proforma.
	 *
	 * @return void
	 */
	public function testHourlyWageReconstitutesAMonthlySalaryForPricing(): void {
		$rate = $this->makeService()->resolve(
			employee: [],
			contract: ['hoursPerWeek' => 36.0, 'hourlyWage' => 25.00],
			period: self::PERIOD
		);

		$this->assertNotNull($rate);
		$this->assertSame(3900.00, $this->lastProformaParams['gross']);

	}//end testHourlyWageReconstitutesAMonthlySalaryForPricing()

	/**
	 * The gross hourly WAGE is never used as the cost rate. 25.00/h would be
	 * 2500 cents; the loaded cost is materially higher.
	 *
	 * @return void
	 */
	public function testGrossHourlyWageIsNeverUsedAsACostRate(): void {
		$rate = $this->makeService()->resolve(
			employee: ['grossMonthlySalary' => 4000.00],
			contract: ['hoursPerWeek' => 36.0, 'hourlyWage' => 25.00],
			period: self::PERIOD
		);

		$this->assertNotSame(2500, $rate['totalCentsPerHour']);
		$this->assertSame(3169, $rate['totalCentsPerHour']);

	}//end testGrossHourlyWageIsNeverUsedAsACostRate()

	/**
	 * An addition with no basis is refused, for the same reason an unexplained
	 * override is: "+ EUR 12/h from somewhere" cannot be audited.
	 *
	 * @return void
	 */
	public function testUnexplainedAdditionIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/must carry a basis/');

		$this->makeService()->resolve(
			employee: [
				'grossMonthlySalary' => 4000.00,
				'hourlyCostAdditions' => [['key' => 'overhead', 'centsPerHour' => 850]],
			],
			contract: self::CONTRACT,
			period: self::PERIOD
		);

	}//end testUnexplainedAdditionIsRefused()

	/**
	 * A FIXED addition is the shape most employer overheads actually have —
	 * rent and management fees do not scale with an individual's salary. The
	 * ICK example: EUR 25.00/h on top of the wage base.
	 *
	 * @return void
	 */
	public function testFixedAdditionIsAddedVerbatim(): void {
		$rate = $this->makeService()->resolve(
			employee: ['grossMonthlySalary' => 4000.00],
			contract: self::CONTRACT,
			period: self::PERIOD,
			extraAdditions: [
				[
					'key' => 'overhead',
					'centsPerHour' => 2500,
					'source' => 'manual',
					'basis' => 'Huur en managementfee, ICK-populatie, FY2026-budget',
				],
			]
		);

		$this->assertSame((3169 + 2500), $rate['totalCentsPerHour']);

	}//end testFixedAdditionIsAddedVerbatim()

	/**
	 * A PERCENTAGE addition resolves against the wage base: 25% of 3169 = 792
	 * (792.25, rounded half-up).
	 *
	 * @return void
	 */
	public function testPercentageAdditionResolvesAgainstTheWageBase(): void {
		$rate = $this->makeService()->resolve(
			employee: ['grossMonthlySalary' => 4000.00],
			contract: self::CONTRACT,
			period: self::PERIOD,
			extraAdditions: [
				[
					'key' => EmployeeCostRateService::ADDITION_OVERTIME,
					'percentageOfWage' => 25,
					'source' => 'cao',
					'basis' => 'Overwerktoeslag doordeweeks, cao-voorbeeld',
				],
			]
		);

		$this->assertSame(792, $rate['additions'][0]['centsPerHour']);
		$this->assertSame((3169 + 792), $rate['totalCentsPerHour']);

	}//end testPercentageAdditionResolvesAgainstTheWageBase()

	/**
	 * Percentages resolve against the WAGE BASE, never against the running
	 * total. Compounding would make the result depend on the order the
	 * additions happen to be listed in — so the same set in either order must
	 * produce the same number.
	 *
	 * @return void
	 */
	public function testPercentagesDoNotCompoundOnOtherAdditions(): void {
		$fixed = ['key' => 'overhead', 'centsPerHour' => 2500, 'source' => 'manual', 'basis' => 'Huur'];
		$percentage = [
			'key' => 'toeslag',
			'percentageOfWage' => 25,
			'source' => 'cao',
			'basis' => 'Toeslag',
		];

		$service = $this->makeService();
		$one = $service->resolve(
			employee: ['grossMonthlySalary' => 4000.00],
			contract: self::CONTRACT,
			period: self::PERIOD,
			extraAdditions: [$fixed, $percentage]
		);
		$other = $service->resolve(
			employee: ['grossMonthlySalary' => 4000.00],
			contract: self::CONTRACT,
			period: self::PERIOD,
			extraAdditions: [$percentage, $fixed]
		);

		$this->assertSame($one['totalCentsPerHour'], $other['totalCentsPerHour']);
		$this->assertSame((3169 + 2500 + 792), $one['totalCentsPerHour']);

	}//end testPercentagesDoNotCompoundOnOtherAdditions()

	/**
	 * An addition stating BOTH forms is refused — it has two defensible
	 * readings and no way to choose between them.
	 *
	 * @return void
	 */
	public function testAdditionStatingBothAmountFormsIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/two readings/');

		$this->makeService()->resolve(
			employee: ['grossMonthlySalary' => 4000.00],
			contract: self::CONTRACT,
			period: self::PERIOD,
			extraAdditions: [
				[
					'key' => 'overhead',
					'centsPerHour' => 2500,
					'percentageOfWage' => 25,
					'source' => 'manual',
					'basis' => 'Beide vormen, moet geweigerd worden',
				],
			]
		);

	}//end testAdditionStatingBothAmountFormsIsRefused()

	/**
	 * Additions alone are never a cost rate — an hour carrying overhead but no
	 * wage is not an hour anyone worked.
	 *
	 * @return void
	 */
	public function testAdditionsWithoutAWageBaseYieldNull(): void {
		$this->assertNull(
			$this->makeService()->resolve(
				employee: [
					'hourlyCostAdditions' => [
						['key' => 'overhead', 'centsPerHour' => 850, 'source' => 'shillinq', 'basis' => 'Pool'],
					],
				]
			)
		);

	}//end testAdditionsWithoutAWageBaseYieldNull()
}//end class
