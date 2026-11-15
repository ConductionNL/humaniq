<?php

/**
 * Humaniq TransitionPaymentCalculator
 *
 * The statutory transition payment of BW 7:673, computed over stored inputs
 * (hiring-offboarding-completion D3): one third of a monthly wage per year of
 * service, pro rata for the rest, from the start of the unbroken contract
 * chain (contracts that follow each other with a gap of at most six months
 * are added together, lid 4), capped at the rule's `capEur` or one annual
 * salary when that is higher. The monthly wage is the base salary plus
 * holiday allowance plus the average variable pay over the payslips read.
 * Money is integer cents inside, the PayrollCalculator convention.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A pure calculator: no reads, no writes.
 *
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-004
 */
class TransitionPaymentCalculator {

	public const RULE_ID = 'nl-offboarding-transitievergoeding';

	/**
	 * The longest gap between two contracts that still joins them (BW 7:673 lid 4).
	 */
	private const MAX_GAP_MONTHS = 6;

	/**
	 * How many of the latest payslips the variable pay average reads.
	 */
	private const VARIABLE_MONTHS = 12;

	/**
	 * The holiday allowance rate when no payslip names one (BW 7:634, 8%).
	 */
	private const DEFAULT_HOLIDAY_RATE = 0.08;

	/**
	 * Calculate the payment.
	 *
	 * @param array<string, mixed>                                                                         $employee   The employee: grossMonthlySalary, startDate.
	 * @param array{contracts: array<int, array<string, mixed>>, payslips: array<int, array<string, mixed>>} $history    The employee's contracts (startDate, endDate, hoursPerWeek, hourlyWage) and payslips (period, grossPay, vakantiegeldRate).
	 * @param array<string, mixed>                                                                         $parameters The rule parameters: capEur, dismissalInitiatedReasons.
	 * @param array{endDate: string, reason: string}                                                       $departure  The last working day (`YYYY-MM-DD`) and the departure reason.
	 *
	 * @return array<string, mixed> amountEur and the breakdown, or zero with the reason.
	 *
	 * @throws InvalidArgumentException When the rule parameters carry no cap or the end date is not a date.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-004
	 */
	public function calculate(array $employee, array $history, array $parameters, array $departure): array {
		$capEur = $this->capEur(parameters: $parameters);
		$end = $this->lastWorkingDay(departure: $departure);
		$notDue = $this->notDue(parameters: $parameters, departure: $departure);
		if ($notDue !== null) {
			return $notDue;
		}

		$case = new TransitionPaymentCase();
		$case->employee = $employee;
		$case->contracts = $history['contracts'];
		$case->payslips = $history['payslips'];
		$case->end = $end;

		return $this->breakdown(basis: $this->basis(case: $case), capEur: $capEur);
	}//end calculate()

	/**
	 * The rule's cap in euro.
	 *
	 * @param array<string, mixed> $parameters The rule parameters.
	 *
	 * @return float
	 *
	 * @throws InvalidArgumentException When the rule parameters carry no cap.
	 */
	private function capEur(array $parameters): float {
		$capEur = $parameters['capEur'] ?? null;
		if (is_int($capEur) === false && is_float($capEur) === false) {
			throw new InvalidArgumentException('The transition payment rule carries no cap (capEur); it is not invented here.');
		}

		return (float)$capEur;
	}//end capEur()

	/**
	 * The last working day.
	 *
	 * @param array{endDate: string, reason: string} $departure The last working day and the departure reason.
	 *
	 * @return DateTimeImmutable
	 *
	 * @throws InvalidArgumentException When the end date is not a date.
	 */
	private function lastWorkingDay(array $departure): DateTimeImmutable {
		$end = DateValues::leadingDate(value: $departure['endDate']);
		if ($end === null) {
			throw new InvalidArgumentException('The last working day is not a date.');
		}

		return $end;
	}//end lastWorkingDay()

	/**
	 * The zero answer when the employer did not initiate the departure, or
	 * null when a payment is due.
	 *
	 * @param array<string, mixed>                   $parameters The rule parameters: dismissalInitiatedReasons.
	 * @param array{endDate: string, reason: string} $departure  The departure.
	 *
	 * @return array<string, mixed>|null
	 */
	private function notDue(array $parameters, array $departure): ?array {
		$reasons = array_map('strval', (array)($parameters['dismissalInitiatedReasons'] ?? []));
		if (in_array($departure['reason'], $reasons, true) === true) {
			return null;
		}

		return [
			'amountEur' => 0.00,
			'reason' => 'The departure was not initiated by the employer, so no transition payment is due.',
			'departureReason' => $departure['reason'],
			'ruleId' => self::RULE_ID,
		];
	}//end notDue()

	/**
	 * The service, the wage parts and the last working day the breakdown is
	 * worked out from.
	 *
	 * @param TransitionPaymentCase $case The payment being calculated.
	 *
	 * @return array{service: array{start: string, months: int, days: int}, parts: array{base: int, holidayAllowance: int, variableAverage: int, monthsRead: int}, end: DateTimeImmutable}
	 */
	private function basis(TransitionPaymentCase $case): array {
		return [
			'service' => $this->service(case: $case),
			'parts' => $this->wageParts(case: $case),
			'end' => $case->end,
		];
	}//end basis()

	/**
	 * The amount and its breakdown: one third of a monthly wage per year of
	 * service, capped at the rule's cap or a year's wage, whichever is higher.
	 *
	 * @param array{service: array{start: string, months: int, days: int}, parts: array{base: int, holidayAllowance: int, variableAverage: int, monthsRead: int}, end: DateTimeImmutable} $basis  The service, the wage parts in cents and the last working day.
	 * @param float                                                                                                                                                                     $capEur The rule's cap in euro.
	 *
	 * @return array<string, mixed>
	 */
	private function breakdown(array $basis, float $capEur): array {
		['service' => $service, 'parts' => $parts] = $basis;
		$wageCents = ($parts['base'] + $parts['holidayAllowance'] + $parts['variableAverage']);

		// One third of a monthly wage per year is 1/36 of it per month.
		$amountCents = (int)round($wageCents * ($service['months'] + ($service['days'] * 12 / 365)) / 36);
		$capCents = max((int)round($capEur * 100), (12 * $wageCents));

		return [
			'amountEur' => $this->euro(cents: min($amountCents, $capCents)),
			'serviceStart' => $service['start'],
			'serviceYears' => intdiv($service['months'], 12),
			'serviceRemainderMonths' => ($service['months'] % 12),
			'serviceRemainderDays' => $service['days'],
			'monthlyWage' => $this->euro(cents: $wageCents),
			'monthlyWageParts' => [
				'base' => $this->euro(cents: $parts['base']),
				'holidayAllowance' => $this->euro(cents: $parts['holidayAllowance']),
				'variableAverage' => $this->euro(cents: $parts['variableAverage']),
				'variableMonthsRead' => $parts['monthsRead'],
			],
			'capEur' => $this->euro(cents: $capCents),
			'capApplied' => ($amountCents > $capCents),
			'endDate' => $basis['end']->format('Y-m-d'),
			'ruleId' => self::RULE_ID,
		];
	}//end breakdown()

	/**
	 * The service counted: the months and days of every contract in the chain
	 * that ends on the last working day, gaps not counted.
	 *
	 * @param TransitionPaymentCase $case The payment: the employee, the contracts and the last working day.
	 *
	 * @return array{start: string, months: int, days: int}
	 */
	private function service(TransitionPaymentCase $case): array {
		$spans = $this->contractSpans(case: $case);
		if ($spans === []) {
			$start = (DateValues::leadingDate(value: (string)($case->employee['startDate'] ?? '')) ?? $case->end);
			$spans[] = ['start' => $start, 'end' => $case->end];
		}

		return $this->chainLength(chain: $this->chainToTheEnd(spans: $spans));
	}//end service()

	/**
	 * The span of every contract that starts on or before the last working
	 * day, cut off at that day.
	 *
	 * @param TransitionPaymentCase $case The payment: the contracts and the last working day.
	 *
	 * @return array<int, array{start: DateTimeImmutable, end: DateTimeImmutable}>
	 */
	private function contractSpans(TransitionPaymentCase $case): array {
		$end = $case->end;
		$spans = [];
		foreach ($case->contracts as $contract) {
			$start = DateValues::leadingDate(value: (string)($contract['startDate'] ?? ''));
			if ($start === null || $start > $end) {
				continue;
			}

			$until = (DateValues::leadingDate(value: (string)($contract['endDate'] ?? '')) ?? $end);
			$spans[] = ['start' => $start, 'end' => min($until, $end)];
		}

		return $spans;
	}//end contractSpans()

	/**
	 * The spans of the unbroken chain that ends with the latest one.
	 *
	 * @param array<int, array{start: DateTimeImmutable, end: DateTimeImmutable}> $spans The spans, at least one.
	 *
	 * @return array<int, array{start: DateTimeImmutable, end: DateTimeImmutable}>
	 */
	private function chainToTheEnd(array $spans): array {
		usort($spans, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

		// Walk back from the latest contract while the gap is six months or less.
		$chain = [array_pop($spans)];
		while ($spans !== []) {
			$previous = end($spans);
			if ($chain[0]['start'] > $previous['end']->modify('+1 day')->modify('+' . self::MAX_GAP_MONTHS . ' months')) {
				break;
			}

			array_unshift($chain, array_pop($spans));
		}

		return $chain;
	}//end chainToTheEnd()

	/**
	 * The months and days the chain spans, and where it starts.
	 *
	 * @param array<int, array{start: DateTimeImmutable, end: DateTimeImmutable}> $chain The chain.
	 *
	 * @return array{start: string, months: int, days: int}
	 */
	private function chainLength(array $chain): array {
		$months = 0;
		$days = 0;
		foreach ($chain as $span) {
			$diff = $span['start']->diff($span['end']->modify('+1 day'));
			$months += (($diff->y * 12) + $diff->m);
			$days += $diff->d;
		}

		return ['start' => $chain[0]['start']->format('Y-m-d'), 'months' => $months, 'days' => $days];
	}//end chainLength()

	/**
	 * The monthly wage parts in cents.
	 *
	 * @param TransitionPaymentCase $case The payment: the employee, the contracts and the payslips.
	 *
	 * @return array{base: int, holidayAllowance: int, variableAverage: int, monthsRead: int}
	 */
	private function wageParts(TransitionPaymentCase $case): array {
		$base = $this->baseCents(case: $case);
		$read = $this->latestPayslips(case: $case);

		return [
			'base' => $base,
			'holidayAllowance' => (int)round($base * $this->holidayRate(read: $read)),
			'variableAverage' => $this->variableAverage(read: $read, base: $base),
			'monthsRead' => count($read),
		];
	}//end wageParts()

	/**
	 * The base monthly wage in cents: the salary, or else the hourly wage
	 * times the hours of the contract that starts last.
	 *
	 * @param TransitionPaymentCase $case The payment: the employee and the contracts.
	 *
	 * @return int
	 */
	private function baseCents(TransitionPaymentCase $case): int {
		$base = (int)round((float)($case->employee['grossMonthlySalary'] ?? 0) * 100);
		if ($base !== 0) {
			return $base;
		}

		$latest = [];
		foreach ($case->contracts as $contract) {
			if ((string)($contract['startDate'] ?? '') >= (string)($latest['startDate'] ?? '')) {
				$latest = $contract;
			}
		}

		return (int)round((float)($latest['hourlyWage'] ?? 0) * (float)($latest['hoursPerWeek'] ?? 0) * 52 / 12 * 100);
	}//end baseCents()

	/**
	 * The payslips the variable pay average reads: the latest ones, newest first.
	 *
	 * @param TransitionPaymentCase $case The payment: the payslips.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function latestPayslips(TransitionPaymentCase $case): array {
		$payslips = $case->payslips;
		usort($payslips, static fn (array $a, array $b): int => (string)($b['period'] ?? '') <=> (string)($a['period'] ?? ''));

		return array_slice($payslips, 0, self::VARIABLE_MONTHS);
	}//end latestPayslips()

	/**
	 * The holiday allowance rate: the newest payslip's, or the statutory default.
	 *
	 * @param array<int, array<string, mixed>> $read The payslips read, newest first.
	 *
	 * @return float
	 */
	private function holidayRate(array $read): float {
		$rate = self::DEFAULT_HOLIDAY_RATE;
		if ($read !== [] && is_numeric($read[0]['vakantiegeldRate'] ?? null) === true) {
			$rate = (float)$read[0]['vakantiegeldRate'];
		}

		return $rate;
	}//end holidayRate()

	/**
	 * The average pay above the base over the payslips read, in cents.
	 *
	 * @param array<int, array<string, mixed>> $read The payslips read.
	 * @param int                              $base The base monthly wage in cents.
	 *
	 * @return int
	 */
	private function variableAverage(array $read, int $base): int {
		$variable = 0;
		foreach ($read as $payslip) {
			$variable += max(0, ((int)round((float)($payslip['grossPay'] ?? 0) * 100) - $base));
		}

		return ($read === [] ? 0 : (int)round($variable / count($read)));
	}//end variableAverage()

	/**
	 * Cents as euros.
	 *
	 * @param int $cents The cents.
	 *
	 * @return float
	 */
	private function euro(int $cents): float {
		return round($cents / 100, 2);
	}//end euro()

}//end class
