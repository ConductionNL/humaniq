<?php

/**
 * Reservation Payout Run Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a payroll run pays out of the reserves (payroll-reservation-payouts
 * D3 to D8). Per employee it answers: the annual wage that selects the
 * special rate; the holiday allowance and end-of-year reserve due in this
 * period, on the administration's schedule or because the employee leaves;
 * the individual choice budget accrued on this payslip; and the approved
 * spend requests settling now. Money taxed at the special rate becomes the
 * payslip's special payment, an exempt goal is paid on net with a WKR row,
 * extra leave moves no money. After the payslips are saved the run writes
 * one ReservationPayout per payout, upserted, so a recalculated draft never
 * leaves two.
 *
 * Nothing is taxed with a guessed percentage: while the special-rate table
 * is unverified, special-rate money is held and the employee is listed with
 * `special-rate-unverified` (D3). The reserves stay open for a later run.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Payroll\TaxTables;

/**
 * Due payouts, IKB accrual and settlement inside one payroll run.
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ReservationPeriods is pure period arithmetic over strings.
 */
class ReservationPayoutRunService {

	/**
	 * The individual choice budget kind on a ReservationPayout.
	 *
	 * @var string
	 */
	public const KIND_IKB = 'ikb';

	/**
	 * Why special-rate money was held.
	 *
	 * @var string
	 */
	public const HELD_UNVERIFIED = 'special-rate-unverified';

	/**
	 * The administration's schedule defaults (D5).
	 *
	 * @var array<string, mixed>
	 */
	public const DEFAULT_SCHEDULE = [
		'holidayAllowancePayout' => 'yearly',
		'holidayAllowanceMonth' => 5,
		'endOfYearMonth' => 12,
		'ikbPayOutRemainder' => false,
	];

	/**
	 * @param RecordStoreInterface    $records The register rows.
	 * @param InternalWriteMarker     $marker  Marks the run's writes as humaniq's own.
	 * @param ReservationPayoutService $math   The annual wage and open-balance arithmetic.
	 * @param AllowanceRateResolver   $rates   The contract-first, confirmed-CAO-second rates.
	 * @param IkbSpendFold            $ikb     The individual choice budget's payout lines.
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 */
	public function __construct(
		private readonly RecordStoreInterface $records,
		private readonly InternalWriteMarker $marker,
		private readonly ReservationPayoutService $math = new ReservationPayoutService(),
		private readonly AllowanceRateResolver $rates = new AllowanceRateResolver(),
		private readonly IkbSpendFold $ikb = new IkbSpendFold(),
	) {
	}//end __construct()

	/**
	 * Everything the fold reads, once per run. The run's own payslips and
	 * payouts are left out of the history, so a recalculation counts from the
	 * same state as the first calculation did.
	 *
	 * @param string $period           The run's period, `YYYY-MM`.
	 * @param string $administrationId The run's administration.
	 * @param string $runId            The run.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 */
	public function inputs(string $period, string $administrationId, string $runId): array {
		$inputs = [
			'period' => $period,
			'runId' => $runId,
			'schedule' => ReservationPeriods::schedule(rows: $this->rows('hrAdministration'), administrationId: $administrationId, defaults: self::DEFAULT_SCHEDULE),
			'payslips' => [],
			'payouts' => [],
			'ownPayouts' => [],
			'requests' => [],
			'budgets' => [],
			'goals' => [],
			'wkrByReference' => [],
		];

		$inputs = $this->withHistory(inputs: $inputs, runId: $runId);

		return $this->withIkbInputs(inputs: $inputs, period: $period);
	}//end inputs()

	/**
	 * Add the employees' earlier payslips and payouts, and this run's own
	 * payouts by key for the upsert.
	 *
	 * @param array<string, mixed> $inputs The inputs so far.
	 * @param string               $runId  The run.
	 *
	 * @return array<string, mixed>
	 */
	private function withHistory(array $inputs, string $runId): array {
		foreach ($this->rows('Payslip') as $payslip) {
			if ((string)($payslip['payrollRunId'] ?? '') !== $runId) {
				$inputs['payslips'][(string)($payslip['employeeId'] ?? '')][] = $payslip;
			}
		}

		foreach ($this->rows('ReservationPayout') as $payout) {
			if ((string)($payout['payrollRunId'] ?? '') === $runId) {
				$inputs['ownPayouts'][ReservationPeriods::payoutKey(row: $payout)] = self::idOf(row: $payout);
				continue;
			}

			$inputs['payouts'][(string)($payout['employeeId'] ?? '')][] = $payout;
		}

		return $inputs;
	}//end withHistory()

	/**
	 * Add the approved IKB requests settling in the period, the budgets, the
	 * goals and the IKB WKR rows already written.
	 *
	 * @param array<string, mixed> $inputs The inputs so far.
	 * @param string               $period The period.
	 *
	 * @return array<string, mixed>
	 */
	private function withIkbInputs(array $inputs, string $period): array {
		foreach ($this->rows('IkbSpendRequest') as $request) {
			if ((string)($request['status'] ?? '') === 'approved' && (string)($request['settlementPeriod'] ?? '') === $period) {
				$inputs['requests'][(string)($request['employeeId'] ?? '')][] = $request;
			}
		}

		foreach ($this->rows('IkbBudget') as $budget) {
			$inputs['budgets'][(string)($budget['employeeId'] ?? '') . '|' . (int)($budget['year'] ?? 0)] = $budget;
		}

		foreach ($this->rows('IkbGoal') as $goal) {
			$inputs['goals'][self::idOf(row: $goal)] = $goal;
		}

		foreach ($this->rows('WkrDeclaration') as $row) {
			$reference = (string)($row['sourceReference'] ?? '');
			if (str_starts_with($reference, 'ikb:') === true) {
				$inputs['wkrByReference'][$reference] = self::idOf(row: $row);
			}
		}

		return $inputs;
	}//end withIkbInputs()


	/**
	 * What one employee's payslip pays and accrues out of the reserves.
	 *
	 * @param array<string, mixed> $inputs From inputs().
	 * @param array<string, mixed> $slip   The run's slip: `employee`, `employeeId`, `contract`, `taxTableColor`, `regularWageCents` and the folded `grossCents`.
	 * @param TaxTables            $tables The run's tables.
	 *
	 * @return array{specialCents: int, annualWageReferenceCents: int, endOfYearRate: float, ikbAccruedCents: int, untaxedCents: int, lines: list<array<string, mixed>>, wkr: list<array<string, mixed>>, held: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-individual-choice-budget-shall-accrue-and-be-spent-by-approved-request-req-rsp-004
	 */
	public function foldFor(array $inputs, array $slip, TaxTables $tables): array {
		$period = (string)$inputs['period'];
		$employeeId = (string)$slip['employeeId'];
		$contract = (array)($slip['contract'] ?? []);
		$grossCents = (int)($slip['grossCents'] ?? 0);
		$payslips = ($inputs['payslips'][$employeeId] ?? []);
		$payouts = ($inputs['payouts'][$employeeId] ?? []);
		$holidayRate = $tables->vakantiebijslagRate();
		$endOfYearRate = (float)($this->rates->rate($contract, 'endOfYearRate', 'eindejaarsuitkering') ?? 0.0);
		$ikbRate = (float)($this->rates->rate($contract, 'ikbRate', 'ikb') ?? 0.0);
		$leaving = ReservationPeriods::leavesIn(employee: (array)$slip['employee'], period: $period);

		$fold = [
			'specialCents' => 0,
			'annualWageReferenceCents' => 0,
			'endOfYearRate' => $endOfYearRate,
			'ikbAccruedCents' => (int)round($grossCents * $ikbRate / 100),
			'untaxedCents' => 0,
			'lines' => [],
			'wkr' => [],
			'held' => [],
		];

		$reserves = [
			ReservationPayoutService::KIND_HOLIDAY_ALLOWANCE => (int)round($grossCents * $holidayRate / 100),
			ReservationPayoutService::KIND_END_OF_YEAR => (int)round($grossCents * $endOfYearRate / 100),
		];
		foreach ($reserves as $kind => $currentCents) {
			$line = $this->reserveLine(kind: $kind, schedule: $inputs['schedule'], period: $period, leaving: $leaving, history: ['payslips' => $payslips, 'payouts' => $payouts], currentCents: $currentCents);
			if ($line !== null) {
				$fold['lines'][] = $line;
			}
		}

		$fold = $this->ikb->withIkb(fold: $fold, inputs: $inputs, slip: $slip, leaving: $leaving);

		return $this->settled(fold: $fold, slip: array_merge($slip, ['period' => $period]), tables: $tables, history: $payslips);
	}//end foldFor()

	/**
	 * Write the run's payouts and WKR rows after the payslips are saved, and
	 * remove this run's payouts that a recalculation no longer pays.
	 *
	 * @param array<string, mixed>             $inputs           From inputs().
	 * @param list<array<string, mixed>>       $paid             Per payslip: `employeeId`, `userId`, `payslipId` and the fold.
	 * @param string                           $administrationId The run's administration.
	 *
	 * @return int The payouts written.
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 */
	public function write(array $inputs, array $paid, string $administrationId): int {
		$stale = $inputs['ownPayouts'];
		$written = 0;
		foreach ($paid as $slip) {
			foreach ($slip['fold']['lines'] as $line) {
				$row = array_merge($line, [
					'employeeId' => $slip['employeeId'],
					'userId' => ($slip['userId'] ?? null),
					'administrationId' => $administrationId,
					'period' => $inputs['period'],
					'payrollRunId' => $inputs['runId'],
					'payslipId' => $slip['payslipId'],
					'status' => 'calculated',
				]);
				$key = ReservationPeriods::payoutKey(row: $row);
				$this->save(payload: $row, schema: 'ReservationPayout', uuid: ($inputs['ownPayouts'][$key] ?? null));
				unset($stale[$key]);
				++$written;
			}

			foreach ($slip['fold']['wkr'] as $wkr) {
				$this->save(payload: array_merge($wkr, ['administrationId' => $administrationId]), schema: 'WkrDeclaration', uuid: ($inputs['wkrByReference'][(string)$wkr['sourceReference']] ?? null));
			}
		}

		foreach ($stale as $uuid) {
			$this->marker->runInternal(fn () => $this->records->delete((string)$uuid, 'ReservationPayout'));
		}

		return $written;
	}//end write()

	/**
	 * The payout of one reserve due this period, or null.
	 *
	 * Yearly: the basis ending in the payout month, or ending now for a
	 * leaver. Per period: this period's own reserve. Counted from the month
	 * after the last payout of the kind, so an early payout on leaving is not
	 * paid again in the payout month (D4, D5, D6).
	 *
	 * @param string                $kind         The reserve kind.
	 * @param array<string, mixed>  $schedule     The administration's schedule.
	 * @param string                $period       The period.
	 * @param bool                  $leaving      Whether the employee leaves this period.
	 * @param array<string, array>  $history      The employee's earlier `payslips` and `payouts`.
	 * @param int                   $currentCents This period's own reserve.
	 *
	 * @return array<string, mixed>|null
	 */
	private function reserveLine(string $kind, array $schedule, string $period, bool $leaving, array $history, int $currentCents): ?array {
		$perPeriod = ($kind === ReservationPayoutService::KIND_HOLIDAY_ALLOWANCE && $schedule['holidayAllowancePayout'] === 'per-period');
		$payoutMonth = ReservationPeriods::payoutMonth(kind: $kind, schedule: $schedule);
		$inMonth = ((int)substr($period, 5, 2) === $payoutMonth);
		if ($perPeriod === true) {
			return ($currentCents > 0 ? self::line(kind: $kind, reason: 'per-period', basis: ['from' => $period, 'to' => $period], amount: $currentCents) : null);
		}

		if ($inMonth === false && $leaving === false) {
			return null;
		}

		$scheduled = $this->math->basisPeriod(kind: $kind, period: ($kind === ReservationPayoutService::KIND_END_OF_YEAR ? $period : ReservationPeriods::scheduledEnd(period: $period, month: $payoutMonth, leaving: $leaving)));
		$from = ReservationPeriods::later(a: $scheduled['from'], b: ReservationPeriods::afterLastPayout(kind: $kind, payouts: $history['payouts']));
		$open = $this->math->openBalance(kind: $kind, basis: ['from' => $from, 'to' => ReservationPeriods::previous(period: $period)], payslips: $history['payslips'], payouts: $history['payouts']);
		$amount = ($currentCents + $open);

		return ($amount > 0 ? self::line(kind: $kind, reason: ($inMonth === true ? 'scheduled' : 'leaving'), basis: ['from' => $from, 'to' => $period], amount: $amount) : null);
	}//end reserveLine()

	/**
	 * A payout line taxed at the special rate.
	 *
	 * @param string                          $kind   The reserve kind.
	 * @param string                          $reason Why it is paid now.
	 * @param array{from: string, to: string} $basis  The basis period.
	 * @param int                             $amount The amount in cents.
	 *
	 * @return array<string, mixed>
	 */
	private static function line(string $kind, string $reason, array $basis, int $amount): array {
		return ['kind' => $kind, 'reason' => $reason, 'basisFrom' => $basis['from'], 'basisTo' => $basis['to'], 'amount' => $amount, 'specialRate' => true];
	}//end line()

	/**
	 * Sum the money into the special payment and the untaxed net amount, or
	 * hold the special-rate money while the table is unverified (D3).
	 *
	 * @param array<string, mixed>             $fold    The fold so far.
	 * @param array<string, mixed>             $slip    The run's slip.
	 * @param TaxTables                        $tables  The run's tables.
	 * @param list<array<string, mixed>>       $history The employee's earlier payslips.
	 *
	 * @return array<string, mixed>
	 */
	private function settled(array $fold, array $slip, TaxTables $tables, array $history): array {
		$special = 0;
		foreach ($fold['lines'] as $line) {
			$special += ($line['specialRate'] === true ? (int)$line['amount'] : 0);
		}

		if ($special > 0 && $this->rateUsable(tables: $tables, colour: (string)$slip['taxTableColor']) === false) {
			$fold['held'][] = ['employeeId' => (string)$slip['employeeId'], 'reason' => self::HELD_UNVERIFIED, 'amountCents' => $special];
			$fold['lines'] = array_values(array_filter($fold['lines'], static fn (array $line): bool => $line['specialRate'] === false));
			$special = 0;
		}

		$untaxed = 0;
		foreach ($fold['lines'] as $line) {
			$untaxed += ($line['specialRate'] === false ? (int)$line['amount'] : 0);
		}

		$fold['specialCents'] = $special;
		$fold['untaxedCents'] = $untaxed;
		if ($special > 0) {
			$fold['annualWageReferenceCents'] = $this->math->annualWageReference(payslips: $history, period: (string)$slip['period'], regularWageCents: (int)$slip['regularWageCents'], holidayRatePercent: $tables->vakantiebijslagRate());
		}

		foreach ($fold['lines'] as $index => $line) {
			unset($fold['lines'][$index]['specialRate']);
		}

		$fold['lines'] = array_values($fold['lines']);

		return $fold;
	}//end settled()

	/**
	 * Whether the tables' special-rate leaf of the colour may tax a payment.
	 *
	 * @param TaxTables $tables The tables.
	 * @param string    $colour The table colour.
	 *
	 * @return bool
	 */
	private function rateUsable(TaxTables $tables, string $colour): bool {
		try {
			return $tables->bijzondereBeloningen(colour: ($colour === 'groen' ? 'groen' : 'wit'), aow: false, withKorting: true)['usable'];
		} catch (\Throwable) {
			return false;
		}
	}//end rateUsable()

	/**
	 * Save a row as humaniq's own write.
	 *
	 * @param array<string, mixed> $payload The row.
	 * @param string               $schema  The schema.
	 * @param string|null          $uuid    The uuid, null creates.
	 *
	 * @return void
	 */
	private function save(array $payload, string $schema, ?string $uuid): void {
		unset($payload['id'], $payload['@self']);
		$this->marker->runInternal(fn () => $this->records->save($payload, $schema, $uuid));
	}//end save()

	/**
	 * Every row of a schema; none when the register does not carry it yet.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $schema): array {
		try {
			return array_values($this->records->loadAll($schema));
		} catch (\Throwable) {
			return [];
		}
	}//end rows()

	/**
	 * A row's uuid.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
	}//end idOf()
}//end class
