<?php

/**
 * Unit tests for ReservationPayoutRunService.
 *
 * The scenarios of payroll-reservation-payouts: the yearly May payout of June
 * to May, a leaver taking the reserve since the last payout, a thirteenth
 * month in December, the per-period schedule, the IKB payout, exempt goal and
 * remainder, and the refusal to pay special-rate money while the table is
 * unverified. The run's writes are upserted so a recalculation leaves one
 * payout and removes what it no longer pays.
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
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\ReservationPayoutRunService;
use OCA\Humaniq\Tests\Unit\Support\PackFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Due payouts, held payouts, IKB lines and the upsert.
 *
 * @covers \OCA\Humaniq\Service\ReservationPayoutRunService
 * @covers \OCA\Humaniq\Service\ReservationPeriods
 * @covers \OCA\Humaniq\Service\IkbSpendFold
 * @covers \OCA\Humaniq\Service\AllowanceRateResolver
 * @uses \OCA\Humaniq\Service\ReservationPayoutService
 * @uses \OCA\Humaniq\Service\InternalWriteMarker
 * @uses \OCA\Humaniq\Payroll\TaxTables
 */
class ReservationPayoutRunServiceTest extends TestCase {

	/**
	 * Every save: payload, schema, uuid, whether it was humaniq's own write.
	 *
	 * @var list<array{payload: array<string, mixed>, schema: string, uuid: ?string, internal: bool}>
	 */
	private array $saves = [];

	/**
	 * Every delete: uuid and schema.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $deletes = [];

	/**
	 * A service over the given rows.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows Rows by schema.
	 *
	 * @return ReservationPayoutRunService
	 */
	private function service(array $rows): ReservationPayoutRunService {
		$marker = new InternalWriteMarker();
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('loadAll')->willReturnCallback(static fn (string $schema): array => ($rows[$schema] ?? []));
		$gateway->method('save')->willReturnCallback(function (array $payload, string $schema, ?string $uuid = null) use ($marker): object {
			$this->saves[] = ['payload' => $payload, 'schema' => $schema, 'uuid' => $uuid, 'internal' => $marker->isInternal()];
			return new \stdClass();
		});
		$gateway->method('delete')->willReturnCallback(function (string $uuid, string $schema): void {
			$this->deletes[] = [$uuid, $schema];
		});

		return new ReservationPayoutRunService($gateway, $marker);
	}//end service()

	/**
	 * The 2026 tables with the special-rate leaves verified or as shipped.
	 *
	 * @param bool $verified Whether the special-rate leaves are verified.
	 *
	 * @return TaxTables
	 */
	private function tables(bool $verified = true): TaxTables {
		$raw = PackFixtures::tables('nl-2026');
		foreach (['wit', 'groen'] as $colour) {
			$raw['parameters']['bijzondereBeloningen'][$colour]['verified'] = $verified;
		}

		return TaxTables::fromDocument($raw, 'test');
	}//end tables()

	/**
	 * Earlier payslips of emp-1, one per period, reserving the amounts given.
	 *
	 * @param list<string>          $periods The periods.
	 * @param array<string, float>  $fields  Reserve fields and euro amounts on each.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function payslips(array $periods, array $fields): array {
		$out = [];
		foreach ($periods as $period) {
			$out[] = array_merge(['id' => 'ps-' . $period, 'employeeId' => 'emp-1', 'payrollRunId' => 'run-' . $period, 'period' => $period, 'grossPay' => 3800.0], $fields);
		}

		return $out;
	}//end payslips()

	/**
	 * The periods from one month to another, inclusive.
	 *
	 * @param string $from The first.
	 * @param string $to   The last.
	 *
	 * @return list<string>
	 */
	private static function periods(string $from, string $to): array {
		$out = [];
		$at = new \DateTimeImmutable($from . '-01');
		while ($at->format('Y-m') <= $to) {
			$out[] = $at->format('Y-m');
			$at = $at->modify('+1 month');
		}

		return $out;
	}//end periods()

	/**
	 * The run's slip of emp-1.
	 *
	 * @param array<string, mixed> $employee Employee overrides.
	 * @param array<string, mixed> $contract The contract.
	 * @param int                  $gross    The folded gross in cents.
	 *
	 * @return array<string, mixed>
	 */
	private function slip(array $employee = [], array $contract = [], int $gross = 380000): array {
		return [
			'employee' => array_merge(['id' => 'emp-1', 'startDate' => '2020-01-01'], $employee),
			'employeeId' => 'emp-1',
			'contract' => $contract,
			'taxTableColor' => 'wit',
			'regularWageCents' => $gross,
			'grossCents' => $gross,
		];
	}//end slip()

	/**
	 * May pays the reserve of June to May as one special payment, judged
	 * against the wage of the full prior year.
	 *
	 * @return void
	 */
	public function testTheYearlyMayPayoutPaysJuneToMay(): void {
		$history = $this->payslips(self::periods('2025-01', '2026-04'), ['vakantiegeldReserved' => 304.0]);
		$service = $this->service(['Payslip' => $history]);
		$inputs = $service->inputs(period: '2026-05', administrationId: 'ADM-001', runId: 'run-may');

		$fold = $service->foldFor(inputs: $inputs, slip: $this->slip(), tables: $this->tables());

		self::assertSame(364800, $fold['specialCents']);
		self::assertSame(4560000, $fold['annualWageReferenceCents'], 'twelve 2025 payslips of 3800.00');
		self::assertSame([['kind' => 'vakantiegeld', 'reason' => 'scheduled', 'basisFrom' => '2025-06', 'basisTo' => '2026-05', 'amount' => 364800]], $fold['lines']);
		self::assertSame([], $fold['held']);
	}//end testTheYearlyMayPayoutPaysJuneToMay()

	/**
	 * Outside the payout month nothing is paid.
	 *
	 * @return void
	 */
	public function testNothingIsPaidOutsideThePayoutMonth(): void {
		$service = $this->service(['Payslip' => $this->payslips(self::periods('2026-01', '2026-03'), ['vakantiegeldReserved' => 304.0])]);

		$fold = $service->foldFor(inputs: $service->inputs('2026-04', 'ADM-001', 'run-apr'), slip: $this->slip(), tables: $this->tables());

		self::assertSame(0, $fold['specialCents']);
		self::assertSame([], $fold['lines']);
		self::assertSame(0, $fold['annualWageReferenceCents']);
	}//end testNothingIsPaidOutsideThePayoutMonth()

	/**
	 * A leaver in September takes the four months since the May payout, and
	 * the May payout is not paid again.
	 *
	 * @return void
	 */
	public function testALeaverTakesTheReserveSinceTheLastPayout(): void {
		$service = $this->service([
			'Payslip' => $this->payslips(self::periods('2025-06', '2026-08'), ['vakantiegeldReserved' => 304.0]),
			'ReservationPayout' => [['id' => 'rp-may', 'employeeId' => 'emp-1', 'kind' => 'vakantiegeld', 'basisFrom' => '2025-06', 'basisTo' => '2026-05', 'amount' => 364800, 'payrollRunId' => 'run-2026-05', 'status' => 'paid']],
		]);

		$fold = $service->foldFor(inputs: $service->inputs('2026-09', 'ADM-001', 'run-sep'), slip: $this->slip(employee: ['endDate' => '2026-09-30']), tables: $this->tables());

		self::assertSame([['kind' => 'vakantiegeld', 'reason' => 'leaving', 'basisFrom' => '2026-06', 'basisTo' => '2026-09', 'amount' => 121600]], $fold['lines']);
		self::assertSame(121600, $fold['specialCents']);
	}//end testALeaverTakesTheReserveSinceTheLastPayout()

	/**
	 * A thirteenth month of 8.33% on 3000.00 pays 2998.80 in December.
	 *
	 * @return void
	 */
	public function testAThirteenthMonthIsPaidInDecember(): void {
		$service = $this->service(['Payslip' => $this->payslips(self::periods('2026-01', '2026-11'), ['eindejaarsuitkeringReserved' => 249.90, 'grossPay' => 3000.0])]);

		$fold = $service->foldFor(inputs: $service->inputs('2026-12', 'ADM-001', 'run-dec'), slip: $this->slip(contract: ['endOfYearRate' => 8.33], gross: 300000), tables: $this->tables());

		self::assertSame(8.33, $fold['endOfYearRate']);
		self::assertSame([['kind' => 'eindejaarsuitkering', 'reason' => 'scheduled', 'basisFrom' => '2026-01', 'basisTo' => '2026-12', 'amount' => 299880]], $fold['lines']);
	}//end testAThirteenthMonthIsPaidInDecember()

	/**
	 * A contract under a placeholder agreement without its own rate reserves nothing.
	 *
	 * @return void
	 */
	public function testAPlaceholderAgreementGivesNoEndOfYearRate(): void {
		$service = $this->service([]);

		$fold = $service->foldFor(inputs: $service->inputs('2026-03', 'ADM-001', 'run-mar'), slip: $this->slip(contract: ['cao' => 'cao-gemeenten']), tables: $this->tables());

		self::assertSame(0.0, $fold['endOfYearRate']);
		self::assertSame(0, $fold['ikbAccruedCents'], 'the Gemeenten IKB figure is a placeholder too');
	}//end testAPlaceholderAgreementGivesNoEndOfYearRate()

	/**
	 * Paying per period pays this period's own reserve, every period.
	 *
	 * @return void
	 */
	public function testThePerPeriodSchedulePaysEachPeriodsReserve(): void {
		$service = $this->service(['hrAdministration' => [['administrationId' => 'ADM-001', 'holidayAllowancePayout' => 'per-period']]]);

		$fold = $service->foldFor(inputs: $service->inputs('2026-03', 'ADM-001', 'run-mar'), slip: $this->slip(), tables: $this->tables());

		self::assertSame([['kind' => 'vakantiegeld', 'reason' => 'per-period', 'basisFrom' => '2026-03', 'basisTo' => '2026-03', 'amount' => 30400]], $fold['lines']);
	}//end testThePerPeriodSchedulePaysEachPeriodsReserve()

	/**
	 * While the special-rate table is unverified the payout is held, the
	 * employee is listed with the reason, and nothing is taxed.
	 *
	 * @return void
	 */
	public function testAnUnverifiedTableHoldsTheSpecialPayment(): void {
		$service = $this->service(['Payslip' => $this->payslips(self::periods('2025-06', '2026-04'), ['vakantiegeldReserved' => 304.0])]);

		$fold = $service->foldFor(inputs: $service->inputs('2026-05', 'ADM-001', 'run-may'), slip: $this->slip(), tables: $this->tables(verified: false));

		self::assertSame(0, $fold['specialCents']);
		self::assertSame([], $fold['lines']);
		self::assertSame([['employeeId' => 'emp-1', 'reason' => 'special-rate-unverified', 'amountCents' => 364800]], $fold['held']);
	}//end testAnUnverifiedTableHoldsTheSpecialPayment()

	/**
	 * An approved IKB payout of 800.00 is a special payment; an exempt goal is
	 * paid untaxed with one WKR row; a leave request moves no money.
	 *
	 * @return void
	 */
	public function testIkbRequestsSettleAsSpecialUntaxedOrNoMoney(): void {
		$service = $this->service([
			'IkbSpendRequest' => [
				['id' => 'req-pay', 'employeeId' => 'emp-1', 'year' => 2026, 'goal' => 'payout', 'amount' => 800.0, 'status' => 'approved', 'settlementPeriod' => '2026-06'],
				['id' => 'req-fee', 'employeeId' => 'emp-1', 'year' => 2026, 'goal' => 'goal', 'ikbGoalId' => 'goal-union', 'amount' => 120.0, 'status' => 'approved', 'settlementPeriod' => '2026-06'],
				['id' => 'req-leave', 'employeeId' => 'emp-1', 'year' => 2026, 'goal' => 'leave', 'amount' => 480.0, 'hours' => 16, 'status' => 'approved', 'settlementPeriod' => '2026-06'],
				['id' => 'req-later', 'employeeId' => 'emp-1', 'year' => 2026, 'goal' => 'payout', 'amount' => 50.0, 'status' => 'approved', 'settlementPeriod' => '2026-07'],
			],
			'IkbGoal' => [['id' => 'goal-union', 'name' => 'Vakbondscontributie', 'taxTreatment' => 'exempt', 'wkrCategory' => 'gericht-vrijgesteld']],
		]);

		$fold = $service->foldFor(inputs: $service->inputs('2026-06', 'ADM-001', 'run-jun'), slip: $this->slip(contract: ['ikbRate' => 16.5]), tables: $this->tables());

		self::assertSame(80000, $fold['specialCents']);
		self::assertSame(12000, $fold['untaxedCents']);
		self::assertSame(62700, $fold['ikbAccruedCents'], '16.5% of 3800.00');
		self::assertSame(['req-pay', 'req-fee'], array_column($fold['lines'], 'ikbSpendRequestId'));
		self::assertCount(1, $fold['wkr']);
		self::assertSame(['year' => 2026, 'date' => '2026-06-01', 'description' => 'Keuzebudget: Vakbondscontributie', 'amount' => 120.0, 'wkrCategory' => 'gericht-vrijgesteld', 'employeeId' => 'emp-1', 'sourceReference' => 'ikb:req-fee'], $fold['wkr'][0]);
	}//end testIkbRequestsSettleAsSpecialUntaxedOrNoMoney()

	/**
	 * In December the remainder is paid when the administration says so.
	 *
	 * @return void
	 */
	public function testTheDecemberRemainderIsPaidWhenTheAdministrationSaysSo(): void {
		$rows = [
			'hrAdministration' => [['administrationId' => 'ADM-001', 'ikbPayOutRemainder' => true]],
			'IkbBudget' => [['id' => 'b-1', 'employeeId' => 'emp-1', 'year' => 2026, 'accrued' => 1500.0, 'spent' => 480.0, 'balance' => 1020.0]],
		];
		$service = $this->service($rows);

		$fold = $service->foldFor(inputs: $service->inputs('2026-12', 'ADM-001', 'run-dec'), slip: $this->slip(contract: ['ikbRate' => 10]), tables: $this->tables());

		self::assertSame([['kind' => 'ikb', 'reason' => 'ikb-remainder', 'basisFrom' => '2026-01', 'basisTo' => '2026-12', 'amount' => 140000]], $fold['lines'], '1020.00 plus this payslip\'s 380.00');
	}//end testTheDecemberRemainderIsPaidWhenTheAdministrationSaysSo()

	/**
	 * The run's payouts are upserted on their key and humaniq's own writes;
	 * one this run no longer pays is deleted; the run's own earlier payslip
	 * is not counted again on a recalculation.
	 *
	 * @return void
	 */
	public function testARecalculationUpsertsAndRemovesStalePayouts(): void {
		$history = $this->payslips(self::periods('2025-06', '2026-04'), ['vakantiegeldReserved' => 304.0]);
		$history[] = ['id' => 'ps-own', 'employeeId' => 'emp-1', 'payrollRunId' => 'run-may', 'period' => '2026-05', 'vakantiegeldReserved' => 304.0];
		$service = $this->service([
			'Payslip' => $history,
			'ReservationPayout' => [
				['id' => 'rp-own', 'employeeId' => 'emp-1', 'kind' => 'vakantiegeld', 'reason' => 'scheduled', 'payrollRunId' => 'run-may'],
				['id' => 'rp-stale', 'employeeId' => 'emp-1', 'kind' => 'eindejaarsuitkering', 'reason' => 'scheduled', 'payrollRunId' => 'run-may'],
			],
		]);
		$inputs = $service->inputs('2026-05', 'ADM-001', 'run-may');
		$fold = $service->foldFor(inputs: $inputs, slip: $this->slip(), tables: $this->tables());

		$written = $service->write(inputs: $inputs, paid: [['employeeId' => 'emp-1', 'userId' => 'jansen', 'payslipId' => 'ps-own', 'fold' => $fold]], administrationId: 'ADM-001');

		self::assertSame(364800, $fold['specialCents'], 'the run\'s own May payslip is not counted twice');
		self::assertSame(1, $written);
		self::assertSame('rp-own', $this->saves[0]['uuid']);
		self::assertSame('ReservationPayout', $this->saves[0]['schema']);
		self::assertTrue($this->saves[0]['internal']);
		self::assertEquals(['status' => 'calculated', 'payslipId' => 'ps-own', 'payrollRunId' => 'run-may', 'period' => '2026-05', 'userId' => 'jansen'], array_intersect_key($this->saves[0]['payload'], array_flip(['status', 'payslipId', 'payrollRunId', 'period', 'userId'])));
		self::assertSame([['rp-stale', 'ReservationPayout']], $this->deletes);
	}//end testARecalculationUpsertsAndRemovesStalePayouts()
}//end class
