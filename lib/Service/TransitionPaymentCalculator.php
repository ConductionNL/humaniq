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
use Exception;
use InvalidArgumentException;

/**
 * A pure calculator: no reads, no writes.
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
	 * @param array<string, mixed>             $employee   The employee: grossMonthlySalary, startDate.
	 * @param array<int, array<string, mixed>> $contracts  The employee's contracts: startDate, endDate, hoursPerWeek, hourlyWage.
	 * @param array<int, array<string, mixed>> $payslips   The employee's payslips: period, grossPay, vakantiegeldRate.
	 * @param array<string, mixed>             $parameters The rule parameters: capEur, dismissalInitiatedReasons.
	 * @param string                           $endDate    The last working day, `YYYY-MM-DD`.
	 * @param string                           $reason     The departure reason.
	 *
	 * @return array<string, mixed> amountEur and the breakdown, or zero with the reason.
	 *
	 * @throws InvalidArgumentException When the rule parameters carry no cap or the end date is not a date.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-004
	 */
	public function calculate(array $employee, array $contracts, array $payslips, array $parameters, string $endDate, string $reason): array {
		$capEur = $parameters['capEur'] ?? null;
		if (is_int($capEur) === false && is_float($capEur) === false) {
			throw new InvalidArgumentException('The transition payment rule carries no cap (capEur); it is not invented here.');
		}

		$end = $this->date(value: $endDate);
		if ($end === null) {
			throw new InvalidArgumentException('The last working day is not a date.');
		}

		$reasons = array_map('strval', (array)($parameters['dismissalInitiatedReasons'] ?? []));
		if (in_array($reason, $reasons, true) === false) {
			return [
				'amountEur' => 0.00,
				'reason' => 'The departure was not initiated by the employer, so no transition payment is due.',
				'departureReason' => $reason,
				'ruleId' => self::RULE_ID,
			];
		}

		$service = $this->service(employee: $employee, contracts: $contracts, end: $end);
		$parts = $this->wageParts(employee: $employee, contracts: $contracts, payslips: $payslips);
		$wageCents = ($parts['base'] + $parts['holidayAllowance'] + $parts['variableAverage']);

		// One third of a monthly wage per year is 1/36 of it per month.
		$amountCents = (int)round($wageCents * ($service['months'] + ($service['days'] * 12 / 365)) / 36);
		$capCents = max((int)round((float)$capEur * 100), (12 * $wageCents));
		$capApplied = ($amountCents > $capCents);

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
			'capApplied' => $capApplied,
			'endDate' => $end->format('Y-m-d'),
			'ruleId' => self::RULE_ID,
		];
	}//end calculate()

	/**
	 * The service counted: the months and days of every contract in the chain
	 * that ends on the last working day, gaps not counted.
	 *
	 * @param array<string, mixed>             $employee  The employee.
	 * @param array<int, array<string, mixed>> $contracts The contracts.
	 * @param DateTimeImmutable                $end       The last working day.
	 *
	 * @return array{start: string, months: int, days: int}
	 */
	private function service(array $employee, array $contracts, DateTimeImmutable $end): array {
		$spans = [];
		foreach ($contracts as $contract) {
			$start = $this->date(value: (string)($contract['startDate'] ?? ''));
			if ($start === null || $start > $end) {
				continue;
			}

			$until = ($this->date(value: (string)($contract['endDate'] ?? '')) ?? $end);
			$spans[] = ['start' => $start, 'end' => min($until, $end)];
		}

		if ($spans === []) {
			$start = ($this->date(value: (string)($employee['startDate'] ?? '')) ?? $end);
			$spans[] = ['start' => $start, 'end' => $end];
		}

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

		$months = 0;
		$days = 0;
		foreach ($chain as $span) {
			$diff = $span['start']->diff($span['end']->modify('+1 day'));
			$months += (($diff->y * 12) + $diff->m);
			$days += $diff->d;
		}

		return ['start' => $chain[0]['start']->format('Y-m-d'), 'months' => $months, 'days' => $days];
	}//end service()

	/**
	 * The monthly wage parts in cents.
	 *
	 * @param array<string, mixed>             $employee  The employee.
	 * @param array<int, array<string, mixed>> $contracts The contracts.
	 * @param array<int, array<string, mixed>> $payslips  The payslips.
	 *
	 * @return array{base: int, holidayAllowance: int, variableAverage: int, monthsRead: int}
	 */
	private function wageParts(array $employee, array $contracts, array $payslips): array {
		$base = (int)round((float)($employee['grossMonthlySalary'] ?? 0) * 100);
		if ($base === 0) {
			$latest = [];
			foreach ($contracts as $contract) {
				if ((string)($contract['startDate'] ?? '') >= (string)($latest['startDate'] ?? '')) {
					$latest = $contract;
				}
			}

			$base = (int)round((float)($latest['hourlyWage'] ?? 0) * (float)($latest['hoursPerWeek'] ?? 0) * 52 / 12 * 100);
		}

		usort($payslips, static fn (array $a, array $b): int => (string)($b['period'] ?? '') <=> (string)($a['period'] ?? ''));
		$read = array_slice($payslips, 0, self::VARIABLE_MONTHS);

		$rate = self::DEFAULT_HOLIDAY_RATE;
		if ($read !== [] && is_numeric($read[0]['vakantiegeldRate'] ?? null) === true) {
			$rate = (float)$read[0]['vakantiegeldRate'];
		}

		$variable = 0;
		foreach ($read as $payslip) {
			$variable += max(0, ((int)round((float)($payslip['grossPay'] ?? 0) * 100) - $base));
		}

		return [
			'base' => $base,
			'holidayAllowance' => (int)round($base * $rate),
			'variableAverage' => ($read === [] ? 0 : (int)round($variable / count($read))),
			'monthsRead' => count($read),
		];
	}//end wageParts()

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

	/**
	 * A Y-m-d date, or null.
	 *
	 * @param string $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function date(string $value): ?DateTimeImmutable {
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
			return null;
		}

		try {
			return new DateTimeImmutable(substr($value, 0, 10));
		} catch (Exception) {
			return null;
		}
	}//end date()
}//end class
