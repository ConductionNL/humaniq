<?php

/**
 * Humaniq Deployment Plan Service
 *
 * The arithmetic of a teacher's yearly deployment plan: the norm their
 * contracts give for a school year, and the plan's planned total against it.
 *
 * The norm is the CAO's annual norm (CaoRegistry::annualNormHours, which is
 * null for an unverified or placeholder figure) times each contract's share of
 * a full-time week (hours per week / 40) times the share of the school year the
 * contract covers, summed over the contracts active in the school year. When a
 * contract's CAO yields no usable norm the whole norm is null, and HR enters it
 * on the plan by hand (`normSource: handmatig`).
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-each-teacher-shall-have-a-yearly-deployment-plan-against-the-norm-for-their-contract-req-edu-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Closure;
use DateTimeImmutable;
use OCA\Humaniq\Standards\CaoRegistry;

/**
 * Norm and totals of a deployment plan.
 *
 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-each-teacher-shall-have-a-yearly-deployment-plan-against-the-norm-for-their-contract-req-edu-001
 */
class DeploymentPlanService {

	/**
	 * A full-time week, the base of a contract's share (AbsenceRateService::DEFAULT_FULL_TIME_HOURS_PER_WEEK).
	 */
	public const FULL_TIME_HOURS_PER_WEEK = 40.0;

	/**
	 * The plan line categories.
	 */
	public const CATEGORIES = ['lesgevend', 'lesgebonden', 'schooltaak', 'professionalisering', 'duurzame-inzetbaarheid'];

	/**
	 * Resolves a CAO id to its annual norm, or null.
	 *
	 * @var Closure(string): ?float
	 */
	private readonly Closure $annualNorm;

	/**
	 * Constructor.
	 *
	 * @param Closure|null $annualNorm Resolves a CAO id to its annual norm in hours, or null; CaoRegistry::annualNormHours by default.
	 */
	public function __construct(?Closure $annualNorm = null) {
		$this->annualNorm = ($annualNorm ?? Closure::fromCallable([CaoRegistry::class, 'annualNormHours']));
	}//end __construct()

	/**
	 * The norm a teacher's contracts give for a school year.
	 *
	 * @param array<int, array<string, mixed>> $contracts  The teacher's EmploymentContract rows (`startDate`, `endDate`, `hoursPerWeek`, `cao`).
	 * @param string                           $schoolYear The school year, `YYYY-YYYY` (1 August to 31 July).
	 *
	 * @return array{hours: float, source: string}|null The norm from the CAO, or null when it has to be entered by hand.
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-a-part-time-contract-halfway-through-the-year
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-an-unverified-cao-figure-is-not-used
	 */
	public function normFor(array $contracts, string $schoolYear): ?array {
		$year = self::schoolYearBounds(schoolYear: $schoolYear);
		if ($year === null) {
			return null;
		}

		[$yearStart, $yearEnd] = $year;
		$yearDays = self::days(from: $yearStart, until: $yearEnd);
		$total = 0.0;
		$counted = 0;
		foreach ($contracts as $contract) {
			$covered = self::coveredDays(contract: $contract, yearStart: $yearStart, yearEnd: $yearEnd);
			if ($covered === 0) {
				continue;
			}

			$annual = ($this->annualNorm)((string)($contract['cao'] ?? ''));
			$hoursPerWeek = ($contract['hoursPerWeek'] ?? null);
			if ($annual === null || is_numeric($hoursPerWeek) === false || (float)$hoursPerWeek <= 0.0) {
				// One contract the CAO cannot price makes the whole norm a hand-entered one.
				return null;
			}

			$total += ($annual * ((float)$hoursPerWeek / self::FULL_TIME_HOURS_PER_WEEK) * ($covered / $yearDays));
			$counted++;
		}

		if ($counted === 0) {
			return null;
		}

		return ['hours' => round($total, 2), 'source' => 'cao'];
	}//end normFor()

	/**
	 * A plan's lines with their hours, its planned total, the difference with its norm and whether it is an overload.
	 *
	 * A line with `hoursPerWeek` and `weeks` (a teaching line) counts their
	 * product; any other line counts its own `hours`.
	 *
	 * @param array<string, mixed> $plan The plan (`lines`, `normHours`).
	 *
	 * @return array{lines: array<int, array<string, mixed>>, plannedHours: float, differenceHours: float|null, overload: bool}
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-a-school-leader-sees-an-overload
	 */
	public function totals(array $plan): array {
		$lines = [];
		$planned = 0.0;
		foreach ((is_array($plan['lines'] ?? null) === true ? $plan['lines'] : []) as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$line['hours'] = self::lineHours(line: $line);
			$planned += $line['hours'];
			$lines[] = $line;
		}

		$planned = round($planned, 2);
		$difference = null;
		if (is_numeric($plan['normHours'] ?? null) === true) {
			$difference = round($planned - (float)$plan['normHours'], 2);
		}

		return [
			'lines'           => $lines,
			'plannedHours'    => $planned,
			'differenceHours' => $difference,
			'overload'        => ($difference !== null && $difference > 0.0),
		];
	}//end totals()

	/**
	 * The hours of one line.
	 *
	 * @param array<string, mixed> $line The line.
	 *
	 * @return float
	 */
	private static function lineHours(array $line): float {
		$perWeek = ($line['hoursPerWeek'] ?? null);
		$weeks = ($line['weeks'] ?? null);
		if (is_numeric($perWeek) === true && is_numeric($weeks) === true) {
			return round((float)$perWeek * (float)$weeks, 2);
		}

		if (is_numeric($line['hours'] ?? null) === true) {
			return (float)$line['hours'];
		}

		return 0.0;
	}//end lineHours()

	/**
	 * The first and last day of a school year, or null for a malformed one.
	 *
	 * @param string $schoolYear `YYYY-YYYY`, the second year one after the first.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-each-teacher-shall-have-a-yearly-deployment-plan-against-the-norm-for-their-contract-req-edu-001
	 */
	public static function schoolYearBounds(string $schoolYear): ?array {
		if (preg_match('/^(\d{4})-(\d{4})$/', trim($schoolYear), $match) !== 1 || ((int)$match[2] - (int)$match[1]) !== 1) {
			return null;
		}

		return [new DateTimeImmutable($match[1].'-08-01'), new DateTimeImmutable($match[2].'-07-31')];
	}//end schoolYearBounds()

	/**
	 * How many days of the school year a contract covers; 0 when none or its dates do not parse.
	 *
	 * @param array<string, mixed> $contract  The contract.
	 * @param DateTimeImmutable    $yearStart The first day of the school year.
	 * @param DateTimeImmutable    $yearEnd   The last day of the school year.
	 *
	 * @return int
	 */
	private static function coveredDays(array $contract, DateTimeImmutable $yearStart, DateTimeImmutable $yearEnd): int {
		$start = self::date(value: $contract['startDate'] ?? null);
		if ($start === null) {
			return 0;
		}

		$end = self::date(value: $contract['endDate'] ?? null);
		$from = max($start, $yearStart);
		$until = $yearEnd;
		if ($end !== null) {
			$until = min($end, $yearEnd);
		}

		if ($from > $until) {
			return 0;
		}

		return self::days(from: $from, until: $until);
	}//end coveredDays()

	/**
	 * The number of days from one date to another, both included.
	 *
	 * @param DateTimeImmutable $from  The first day.
	 * @param DateTimeImmutable $until The last day.
	 *
	 * @return int
	 */
	private static function days(DateTimeImmutable $from, DateTimeImmutable $until): int {
		return ((int)$from->diff($until)->days + 1);
	}//end days()

	/**
	 * A date (`YYYY-MM-DD`, or the date part of a timestamp), or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function date(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
		if ($date === false) {
			return null;
		}

		return $date;
	}//end date()
}//end class
