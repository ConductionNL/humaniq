<?php

/**
 * Comp Proposal Builder
 *
 * The pure half of a bulk compensation proposal
 * (comp-collective-raise-and-step-increase design.md D2, D5): the collective
 * raise on the contract covering the cycle's effective date, and the next
 * step of a band for a contract whose step falls due in the cycle's period.
 * It reads only what it is given; `CompCollectiveService` loads and saves.
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
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Computes one proposal from a cycle, a contract and a band.
 *
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 */
class CompProposalBuilder {

	/**
	 * A collective raise on the contract covering the cycle's effective date.
	 *
	 * @param array<string, mixed> $cycle The cycle.
	 * @param array<int, array<string, mixed>> $contracts The employee's contracts.
	 * @param int|null $current Current gross monthly salary in cents.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
	 */
	public function raiseProposal(array $cycle, array $contracts, ?int $current): array {
		$effectiveDate = (string)$cycle['effectiveDate'];
		$contract = $this->covering($contracts, $effectiveDate);
		if ($contract === null) {
			return ['skip' => 'no-contract-on-effective-date'];
		}

		if ($current === null || $current <= 0) {
			return ['skip' => 'no-salary'];
		}

		$raise = $this->raiseOf($cycle);
		$proposal = [
			'contractId' => (string)$contract['id'],
			'effectiveDate' => $effectiveDate,
			'adjustmentKind' => 'collective',
		];

		if ($raise['percentage'] !== null) {
			$factor = (1 + ($raise['percentage'] / 100));
			$proposal['proposedSalary'] = (int)round($current * $factor);
			$proposal['rationale'] = 'Collectieve verhoging van ' . $this->number($raise['percentage']) . '% (' . (string)($cycle['name'] ?? '') . ').';
			$hourly = ($contract['hourlyWage'] ?? null);
			if (is_numeric($hourly) === true && (float)$hourly > 0) {
				$proposal['proposedHourlyWage'] = round(((float)$hourly) * $factor, 2);
			}

			return $proposal;
		}

		$proposal['proposedSalary'] = ($current + (int)$raise['amount']);
		$proposal['rationale'] = 'Collectieve verhoging van ' . $this->number($raise['amount'] / 100) . ' euro per maand (' . (string)($cycle['name'] ?? '') . ').';

		return $proposal;
	}//end raiseProposal()

	/**
	 * The contract that carries a band and a step, or null.
	 *
	 * @param array<int, array<string, mixed>> $contracts The employee's contracts.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
	 */
	public function steppedContract(array $contracts): ?array {
		foreach ($contracts as $contract) {
			if (trim((string)($contract['salaryBandId'] ?? '')) !== '' && is_numeric($contract['salaryStep'] ?? null) === true) {
				return $contract;
			}
		}

		return null;
	}//end steppedContract()

	/**
	 * Whether a stepped contract's step falls due in the cycle's period and
	 * the contract still runs on that date.
	 *
	 * @param array<string, mixed> $cycle The cycle.
	 * @param array<string, mixed> $contract The stepped contract.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
	 */
	public function stepDue(array $cycle, array $contract): bool {
		$stepDate = trim((string)($contract['stepDate'] ?? ''));
		return $stepDate !== '' && $this->inPeriod($stepDate, $cycle) === true && $this->covering([$contract], $stepDate) !== null;
	}//end stepDue()

	/**
	 * The next step of the band for a due contract, or ['skip' => 'top-of-band'].
	 *
	 * @param array<string, mixed> $contract The stepped, due contract.
	 * @param array<string, mixed>|null $band Its SalaryBand.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
	 */
	public function stepProposal(array $contract, ?array $band): array {
		$from = (int)$contract['salaryStep'];
		$next = null;
		foreach ($this->stepsOf($band) as $step => $cents) {
			if ($step > $from) {
				$next = [$step, $cents];
				break;
			}
		}

		if ($next === null) {
			return ['skip' => 'top-of-band'];
		}

		return [
			'contractId' => (string)$contract['id'],
			'effectiveDate' => trim((string)$contract['stepDate']),
			'adjustmentKind' => 'step-increase',
			'fromStep' => $from,
			'toStep' => $next[0],
			'proposedSalary' => $next[1],
			'targetBandId' => (string)$contract['salaryBandId'],
			'rationale' => 'Periodiek: van trede ' . $from . ' naar trede ' . $next[0] . ' in ' . (string)($band['title'] ?? 'de schaal') . '.',
		];
	}//end stepProposal()

	/**
	 * The cycle's raise: a percentage wins over an amount; null when neither.
	 *
	 * @param array<string, mixed> $cycle The cycle.
	 *
	 * @return array{percentage: float|null, amount: int|null}|null
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
	 */
	public function raiseOf(array $cycle): ?array {
		$percentage = ($cycle['raisePercentage'] ?? null);
		if (is_numeric($percentage) === true && (float)$percentage !== 0.0) {
			return ['percentage' => (float)$percentage, 'amount' => null];
		}

		$amount = ($cycle['raiseAmountCents'] ?? null);
		if (is_numeric($amount) === true && (int)$amount !== 0) {
			return ['percentage' => null, 'amount' => (int)$amount];
		}

		return null;
	}//end raiseOf()

	/**
	 * Whether a date falls in the cycle's period: 'YYYY' is that year,
	 * 'YYYY-MM' that month, anything else the year of the effective date.
	 *
	 * @param string $date ISO date.
	 * @param array<string, mixed> $cycle The cycle.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
	 */
	private function inPeriod(string $date, array $cycle): bool {
		$period = trim((string)($cycle['period'] ?? ''));
		if (preg_match('/^\d{4}(-\d{2})?$/', $period) !== 1) {
			$period = substr((string)$cycle['effectiveDate'], 0, 4);
		}

		return str_starts_with($date, $period);
	}//end inPeriod()

	/**
	 * The contract covering a date: started on or before it, not ended before
	 * it. The latest-starting one when several do.
	 *
	 * @param array<int, array<string, mixed>> $contracts Contracts.
	 * @param string $onDate ISO date.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
	 */
	public function covering(array $contracts, string $onDate): ?array {
		$found = null;
		foreach ($contracts as $contract) {
			$start = (string)($contract['startDate'] ?? '');
			$end = trim((string)($contract['endDate'] ?? ''));
			if ($start === '' || $start > $onDate || ($end !== '' && $end < $onDate)) {
				continue;
			}

			if ($found === null || $start > (string)$found['startDate']) {
				$found = $contract;
			}
		}

		return $found;
	}//end covering()

	/**
	 * A band's steps as step => cents, ascending.
	 *
	 * @param array<string, mixed>|null $band The SalaryBand.
	 *
	 * @return array<int, int>
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
	 */
	private function stepsOf(?array $band): array {
		$steps = [];
		foreach ((is_array($band['steps'] ?? null) === true ? $band['steps'] : []) as $step) {
			if (is_numeric($step['step'] ?? null) === true && is_numeric($step['monthlySalaryCents'] ?? null) === true) {
				$steps[(int)$step['step']] = (int)$step['monthlySalaryCents'];
			}
		}

		ksort($steps);
		return $steps;
	}//end stepsOf()

	/**
	 * A number without trailing zeros, Dutch decimal comma.
	 *
	 * @param float $value The number.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
	 */
	private function number(float $value): string {
		return str_replace('.', ',', rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'));
	}//end number()

}//end class
