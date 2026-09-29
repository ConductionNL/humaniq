<?php

/**
 * Pay transparency service
 *
 * The gender pay gap the EU Pay Transparency Directive (2023/970) asks an
 * employer to report: per employee the hourly pay actually received in a
 * year (gross pay over hours paid, from the payslips), then the mean and
 * median gap between women and men overall and per category of equal work
 * (`Normfunctie.payCategory`), the gap in variable pay and the share of each
 * gender receiving it, and the gender split per pay quartile. A group with
 * fewer women or men than the threshold is reported as too small, with no
 * figure, so nobody can be recognised (reporting-pay-transparency D1-D3).
 *
 * Pure arithmetic over rows the caller loads: no store access here.
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
 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Computes the gender pay gap indicators for one administration and year.
 *
 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
 */
class PayTransparencyService {

	/**
	 * The category of an employee whose function carries no pay category.
	 *
	 * @var string
	 */
	public const UNCATEGORISED = '';

	/**
	 * Weeks in a month, for hours derived from a contract's hours per week.
	 *
	 * @var float
	 */
	private const WEEKS_PER_MONTH = (52.0 / 12.0);

	/**
	 * Constructor.
	 *
	 * @param Percentile $percentile Median arithmetic.
	 */
	public function __construct(
		private readonly Percentile $percentile,
	) {
	}//end __construct()

	/**
	 * The report for one year.
	 *
	 * @param array{employees: array<int, array<string, mixed>>, contracts: array<int, array<string, mixed>>, normfuncties: array<int, array<string, mixed>>, payslips: array<int, array<string, mixed>>} $rows The administration's rows.
	 * @param int $year      The year.
	 * @param int $threshold The fewest women and the fewest men a group needs to be reported.
	 *
	 * @return array<string, mixed> {year, threshold, counted, women, men, otherOrUnknown, overall, categories[], quartiles[]}
	 *
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
	 */
	public function report(array $rows, int $year, int $threshold): array {
		$people = $this->people(rows: $rows, year: $year);
		$byGender = $this->countByGender(people: $people);
		$overall = $this->indicators(people: $people, threshold: $threshold);

		$categories = [];
		foreach ($this->groupByCategory(people: $people) as $category => $members) {
			$categories[] = array_merge(['category' => (string)$category], $this->indicators(people: $members, threshold: $threshold));
		}

		usort($categories, static fn (array $a, array $b): int => strcmp($a['category'] === '' ? "\u{10FFFF}" : $a['category'], $b['category'] === '' ? "\u{10FFFF}" : $b['category']));

		return [
			'year' => $year,
			'threshold' => $threshold,
			'counted' => count($people),
			'women' => $byGender['woman'],
			'men' => $byGender['man'],
			'otherOrUnknown' => (count($people) - $byGender['woman'] - $byGender['man']),
			'overall' => $overall,
			'categories' => $categories,
			'quartiles' => $overall['tooSmall'] === true ? [] : $this->quartiles(people: $people),
		];
	}//end report()

	/**
	 * Per employee with pay in the year: gender, category, hourly pay and variable pay.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows The administration's rows.
	 * @param int                                             $year The year.
	 *
	 * @return array<int, array{gender: string, category: string, hourly: float, variable: float}>
	 */
	private function people(array $rows, int $year): array {
		$categoryOf = [];
		foreach (($rows['normfuncties'] ?? []) as $function) {
			$categoryOf[(string)($function['id'] ?? '')] = trim((string)($function['payCategory'] ?? ''));
		}

		$slipsOf = [];
		foreach (($rows['payslips'] ?? []) as $slip) {
			if (str_starts_with((string)($slip['period'] ?? ''), $year . '-') === true) {
				$slipsOf[(string)($slip['employeeId'] ?? '')][] = $slip;
			}
		}

		$people = [];
		foreach (($rows['employees'] ?? []) as $employee) {
			$employeeId = (string)($employee['id'] ?? '');
			$slips = ($slipsOf[$employeeId] ?? []);
			if ($employeeId === '' || $slips === []) {
				continue;
			}

			$contract = $this->contractInYear(contracts: ($rows['contracts'] ?? []), employeeId: $employeeId, year: $year);
			$pay = $this->pay(slips: $slips, contract: $contract, monthlySalary: (float)($employee['grossMonthlySalary'] ?? 0));
			if ($pay === null) {
				continue;
			}

			$people[] = [
				'gender' => (string)($employee['gender'] ?? ''),
				'category' => ($categoryOf[(string)($contract['normfunctieId'] ?? '')] ?? self::UNCATEGORISED),
				'hourly' => $pay['hourly'],
				'variable' => $pay['variable'],
			];
		}//end foreach

		return $people;
	}//end people()

	/**
	 * Hourly and variable pay from a year's payslips; null without hours.
	 *
	 * Hours are the payslips' hoursWorked, or the contract's hours per week
	 * times the weeks of a month for each payslip without them. Variable pay
	 * is what a payslip pays above the monthly salary.
	 *
	 * @param array<int, array<string, mixed>> $slips         The year's payslips.
	 * @param array<string, mixed>|null        $contract      The contract in the year.
	 * @param float                            $monthlySalary The employee's gross monthly salary.
	 *
	 * @return array{hourly: float, variable: float}|null
	 */
	private function pay(array $slips, ?array $contract, float $monthlySalary): ?array {
		$gross = 0.0;
		$hours = 0.0;
		$variable = 0.0;
		$weekly = (float)($contract['hoursPerWeek'] ?? 0);
		foreach ($slips as $slip) {
			$slipGross = (float)($slip['grossPay'] ?? 0);
			$slipHours = (float)($slip['hoursWorked'] ?? 0);
			$gross += $slipGross;
			$hours += $slipHours > 0 ? $slipHours : ($weekly * self::WEEKS_PER_MONTH);
			if ($monthlySalary > 0 && $slipGross > $monthlySalary) {
				$variable += ($slipGross - $monthlySalary);
			}
		}

		if ($hours <= 0 || $gross <= 0) {
			return null;
		}

		return ['hourly' => ($gross / $hours), 'variable' => $variable];
	}//end pay()

	/**
	 * The employee's latest contract that runs at some point in the year.
	 *
	 * @param array<int, array<string, mixed>> $contracts  All contracts.
	 * @param string                           $employeeId The employee.
	 * @param int                              $year       The year.
	 *
	 * @return array<string, mixed>|null
	 */
	private function contractInYear(array $contracts, string $employeeId, int $year): ?array {
		$found = null;
		foreach ($contracts as $contract) {
			$start = (string)($contract['startDate'] ?? '');
			$end = (string)($contract['endDate'] ?? '');
			$runs = $start <= $year . '-12-31' && ($end === '' || $end >= $year . '-01-01');
			if ((string)($contract['employeeId'] ?? '') !== $employeeId || $runs === false) {
				continue;
			}

			if ($found === null || $start > (string)($found['startDate'] ?? '')) {
				$found = $contract;
			}
		}

		return $found;
	}//end contractInYear()

	/**
	 * The gap indicators of a group, or tooSmall without figures.
	 *
	 * @param array<int, array{gender: string, category: string, hourly: float, variable: float}> $people    The group.
	 * @param int                                                                                 $threshold The fewest women and men.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-002
	 */
	private function indicators(array $people, int $threshold): array {
		$women = array_values(array_filter($people, static fn (array $p): bool => $p['gender'] === 'woman'));
		$men = array_values(array_filter($people, static fn (array $p): bool => $p['gender'] === 'man'));
		if (count($women) < $threshold || count($men) < $threshold) {
			return ['tooSmall' => true];
		}

		$womenPaid = array_values(array_filter($women, static fn (array $p): bool => $p['variable'] > 0));
		$menPaid = array_values(array_filter($men, static fn (array $p): bool => $p['variable'] > 0));

		return [
			'tooSmall' => false,
			'women' => count($women),
			'men' => count($men),
			'meanGap' => $this->gap(men: $this->mean(values: array_column($men, 'hourly')), women: $this->mean(values: array_column($women, 'hourly'))),
			'medianGap' => $this->gap(men: $this->median(values: array_column($men, 'hourly')), women: $this->median(values: array_column($women, 'hourly'))),
			'variableMeanGap' => $this->gap(men: $this->mean(values: array_column($menPaid, 'variable')), women: $this->mean(values: array_column($womenPaid, 'variable'))),
			'variableMedianGap' => $this->gap(men: $this->median(values: array_column($menPaid, 'variable')), women: $this->median(values: array_column($womenPaid, 'variable'))),
			'womenReceivingVariable' => round((100.0 * count($womenPaid) / count($women)), 1),
			'menReceivingVariable' => round((100.0 * count($menPaid) / count($men)), 1),
		];
	}//end indicators()

	/**
	 * The women and men of each pay quartile of the whole group, as shares.
	 *
	 * @param array<int, array{gender: string, category: string, hourly: float, variable: float}> $people The group.
	 *
	 * @return array<int, array{quartile: int, women: float, men: float}>
	 */
	private function quartiles(array $people): array {
		$ranked = array_values(array_filter($people, static fn (array $p): bool => in_array($p['gender'], ['woman', 'man'], true)));
		usort($ranked, static fn (array $a, array $b): int => $a['hourly'] <=> $b['hourly']);
		$count = count($ranked);
		$quartiles = [];
		for ($quartile = 1; $quartile <= 4; $quartile++) {
			$slice = array_slice($ranked, (int)floor(($quartile - 1) * $count / 4), ((int)floor($quartile * $count / 4) - (int)floor(($quartile - 1) * $count / 4)));
			$women = count(array_filter($slice, static fn (array $p): bool => $p['gender'] === 'woman'));
			$size = max(1, count($slice));
			$quartiles[] = [
				'quartile' => $quartile,
				'women' => round((100.0 * $women / $size), 1),
				'men' => round((100.0 * (count($slice) - $women) / $size), 1),
			];
		}

		return $quartiles;
	}//end quartiles()

	/**
	 * People per category.
	 *
	 * @param array<int, array{gender: string, category: string, hourly: float, variable: float}> $people The people.
	 *
	 * @return array<string, array<int, array{gender: string, category: string, hourly: float, variable: float}>>
	 */
	private function groupByCategory(array $people): array {
		$groups = [];
		foreach ($people as $person) {
			$groups[$person['category']][] = $person;
		}

		return $groups;
	}//end groupByCategory()

	/**
	 * Women, men and the rest.
	 *
	 * @param array<int, array{gender: string, category: string, hourly: float, variable: float}> $people The people.
	 *
	 * @return array{woman: int, man: int}
	 */
	private function countByGender(array $people): array {
		$counts = ['woman' => 0, 'man' => 0];
		foreach ($people as $person) {
			if (isset($counts[$person['gender']]) === true) {
				$counts[$person['gender']]++;
			}
		}

		return $counts;
	}//end countByGender()

	/**
	 * The gap as a percentage of the men's figure; null without one.
	 *
	 * @param float|null $men   The men's figure.
	 * @param float|null $women The women's figure.
	 *
	 * @return float|null
	 */
	private function gap(?float $men, ?float $women): ?float {
		if ($men === null || $women === null || $men <= 0.0) {
			return null;
		}

		return round((100.0 * ($men - $women) / $men), 1);
	}//end gap()

	/**
	 * The mean; null for nothing.
	 *
	 * @param array<int, float> $values The values.
	 *
	 * @return float|null
	 */
	private function mean(array $values): ?float {
		return $values === [] ? null : (array_sum($values) / count($values));
	}//end mean()

	/**
	 * The median; null for nothing.
	 *
	 * @param array<int, float> $values The values.
	 *
	 * @return float|null
	 */
	private function median(array $values): ?float {
		sort($values);
		return $this->percentile->value(array_values($values), 50.0);
	}//end median()
}//end class
