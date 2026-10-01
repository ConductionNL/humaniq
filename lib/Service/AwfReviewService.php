<?php

/**
 * Awf Review Service
 *
 * Reviews the low unemployment premium (Awf) afterwards, the way the
 * Belastingdienst prescribes it (Handboek Loonheffingen 2026, maart 2026,
 * paragraaf 7.2.2 and 7.2.3; filings-premium-differentiation D2/D3):
 * - an employment that ends at most two months after it began is charged
 *   the high rate over every period it was charged low;
 * - after a calendar year, an employee on an average of 30 contracted hours
 *   a week or less who was paid more than 30 percent above the contracted
 *   hours is charged the high rate over the whole year.
 * The BBL and young part-timer exceptions are never reviewed.
 *
 * Each reviewed payslip is recomputed by the engine from its own stored
 * input with the high rate, and the difference in employer insurance
 * charges is written as one applied `PayrollAdjustment` (correction type
 * `awf-herziening`) settling in the run that is being calculated. Net pay
 * does not change: the Awf is an employer charge. A payslip is reviewed at
 * most once.
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
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-102
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Payroll\AwfHoursReview;
use OCA\Humaniq\Payroll\AwfTariffResolver;
use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\PackRepository;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\TaxTables;
use Psr\Log\LoggerInterface;

/**
 * The early-end and extra-hours review of the low Awf premium.
 *
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-102
 */
class AwfReviewService {

	/**
	 * The correction type of every review adjustment.
	 */
	public const CORRECTION_TYPE = 'awf-herziening';

	/**
	 * @param HoursRegisterGateway $gateway    The register plumbing.
	 * @param PayrollCalculator    $calculator The engine that recomputes a payslip.
	 * @param LoggerInterface      $logger     The logger.
	 * @param PackRepository       $packs      The jurisdiction-pack resolver.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly PayrollCalculator $calculator,
		private readonly LoggerInterface $logger,
		private readonly PackRepository $packs=new PackRepository(),
	) {

	}//end __construct()

	/**
	 * Review every employee before the run of a period is calculated:
	 * early ends always, the extra-hours review of the previous year in the
	 * first period of a year.
	 *
	 * @param string $period The period being calculated (`YYYY-MM`).
	 * @param string $runId  The run being calculated.
	 *
	 * @return array{earlyEnd: int, extraHours: int, skipped: array<int, array<string, string>>}
	 */
	public function review(string $period, string $runId): array {
		$outcome = ['earlyEnd' => 0, 'extraHours' => 0, 'skipped' => []];
		$sealed  = $this->sealedRunIds();
		$done    = $this->reviewedPayslipIds();
		$slipsByEmployee = $this->payslipsByEmployee();
		$reviewYear      = (substr($period, 5, 2) === '01' ? (string)((int)substr($period, 0, 4) - 1) : null);

		foreach ($this->contractsByEmployee() as $employeeId => $contracts) {
			$slips = array_values(
				array_filter(
					($slipsByEmployee[$employeeId] ?? []),
					static fn (array $slip): bool => isset($sealed[(string)($slip['payrollRunId'] ?? '')]) === true
						&& isset($done[(string)($slip['id'] ?? '')]) === false
				)
			);

			$targets = ['earlyEnd' => $this->earlyEndSlips(contracts: $contracts, slips: $slips)];
			if ($reviewYear !== null) {
				$targets['extraHours'] = $this->extraHoursSlips(contracts: $contracts, slips: ($slipsByEmployee[$employeeId] ?? []), reviewable: $slips, year: $reviewYear);
			}

			foreach ($targets as $kind => $toReview) {
				foreach ($toReview as $slip) {
					if (isset($done[(string)$slip['id']]) === true) {
						continue;
					}

					$skip = $this->settle(slip: $slip, kind: $kind, period: $period, runId: $runId);
					if ($skip !== null) {
						$outcome['skipped'][] = ['payslipId' => (string)$slip['id'], 'reason' => $skip];
						continue;
					}

					$done[(string)$slip['id']] = true;
					$outcome[$kind]++;
				}
			}
		}//end foreach

		return $outcome;

	}//end review()

	/**
	 * The year-to-date figures the signal rule reads, for the payslip of
	 * the period being calculated.
	 *
	 * @param string                           $employeeId The employee.
	 * @param array<int, array<string, mixed>> $contracts  The employee's contracts.
	 * @param string                           $period     The period being calculated.
	 * @param float                            $paidHours  The hours paid in this period.
	 * @param bool                             $low        Whether this period is charged the low rate.
	 *
	 * @return array{paid: float, contract: float, averageHoursPerWeek: int, overrunPercent: int}
	 */
	public function yearToDate(string $employeeId, array $contracts, string $period, float $paidHours, bool $low=true): array {
		$year  = substr($period, 0, 4);
		$slips = [['period' => $period, 'hours' => $paidHours, 'low' => $low]];
		foreach ($this->payslipsByEmployee()[$employeeId] ?? [] as $slip) {
			$slipPeriod = (string)($slip['period'] ?? '');
			if (substr($slipPeriod, 0, 4) === $year && $slipPeriod < $period) {
				$slips[] = $this->hoursRow($slip);
			}
		}

		return AwfHoursReview::figures(contracts: $contracts, slips: $slips, lastPeriod: $period);

	}//end yearToDate()

	/**
	 * Whether year-to-date figures already pass the review line.
	 *
	 * @param array<string, mixed> $figures The figures.
	 *
	 * @return bool
	 */
	public static function signals(array $figures): bool {
		return AwfHoursReview::exceeds($figures);

	}//end signals()

	/**
	 * The reviewable payslips inside a contract that ends early.
	 *
	 * @param array<int, array<string, mixed>> $contracts The employee's contracts.
	 * @param array<int, array<string, mixed>> $slips     The sealed, not yet reviewed payslips.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function earlyEndSlips(array $contracts, array $slips): array {
		$out = [];
		foreach ($contracts as $contract) {
			if (AwfTariffResolver::endsEarly($contract, $contracts) === false) {
				continue;
			}

			foreach ($slips as $slip) {
				if ($this->reviewable($slip) === true && AwfTariffResolver::coversPeriod($contract, (string)($slip['period'] ?? '')) === true) {
					$out[] = $slip;
				}
			}
		}

		return $out;

	}//end earlyEndSlips()

	/**
	 * The reviewable payslips of a year in which the paid hours ran more
	 * than 30 percent above the contract.
	 *
	 * @param array<int, array<string, mixed>> $contracts  The employee's contracts.
	 * @param array<int, array<string, mixed>> $slips      Every payslip of the employee.
	 * @param array<int, array<string, mixed>> $reviewable The sealed, not yet reviewed payslips.
	 * @param string                           $year       The year reviewed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function extraHoursSlips(array $contracts, array $slips, array $reviewable, string $year): array {
		$inYear = static fn (array $slip): bool => substr((string)($slip['period'] ?? ''), 0, 4) === $year;
		$targets = array_values(array_filter($reviewable, fn (array $slip): bool => $inYear($slip) === true && $this->reviewable($slip) === true));
		if ($targets === []) {
			return [];
		}

		$rows    = array_map(fn (array $slip): array => $this->hoursRow($slip), array_values(array_filter($slips, $inYear)));
		$figures = AwfHoursReview::figures(contracts: $contracts, slips: $rows, lastPeriod: $year . '-12');

		return (AwfHoursReview::exceeds($figures) === true ? $targets : []);

	}//end extraHoursSlips()

	/**
	 * Recompute one payslip at the high rate and write the applied
	 * adjustment. Returns the reason when it cannot be done.
	 *
	 * @param array<string, mixed> $slip   The payslip.
	 * @param string               $kind   `earlyEnd` or `extraHours`.
	 * @param string               $period The settlement period.
	 * @param string               $runId  The settlement run.
	 *
	 * @return string|null
	 */
	private function settle(array $slip, string $kind, string $period, string $runId): ?string {
		$snapshot = ($slip['engineInputSnapshot'] ?? null);
		if (is_string($snapshot) === true) {
			$snapshot = json_decode($snapshot, true);
		}

		if (is_array($snapshot) === false || $snapshot === []) {
			return 'no-engine-snapshot';
		}

		$slipPeriod = (string)($slip['period'] ?? '');
		try {
			$tables = TaxTables::load($this->packs->resolve((string)($snapshot['jurisdiction'] ?? 'NL'), $slipPeriod)->tablesId());
			$result = $this->calculator->calculate(CalculationInput::fromDecoded(array_merge($snapshot, ['awfTariff' => 'high'])), $tables);
		} catch (\Throwable $e) {
			$this->logger->warning('AwfReviewService: payslip ' . (string)$slip['id'] . ' could not be recomputed: ' . $e->getMessage());
			return 'tables-missing';
		}

		$delta = round(($result->werknemersverzekeringenCents - (int)round(((float)($slip['werknemersverzekeringen'] ?? 0.0)) * 100)) / 100, 2);
		$this->gateway->save(
			[
				'employeeId' => (string)($slip['employeeId'] ?? ''),
				'originalPayrollRunId' => (string)($slip['payrollRunId'] ?? ''),
				'originalPayslipId' => (string)$slip['id'],
				'originalPeriod' => $slipPeriod,
				'correctionType' => self::CORRECTION_TYPE,
				'correctionRef' => self::CORRECTION_TYPE . '-' . ($kind === 'earlyEnd' ? 'early-end' : 'extra-hours') . '-' . (string)$slip['id'],
				'deltaGross' => 0.0,
				'deltaLoonheffing' => 0.0,
				'deltaNet' => 0.0,
				'deltaWerknemersverzekeringen' => $delta,
				'deltaZvw' => 0.0,
				'deltaVolksverzekeringen' => 0.0,
				'deltaVakantiegeldReserved' => 0.0,
				'engineVersion' => $tables->id(),
				'settlementPeriod' => $period,
				'settlementPayrollRunId' => $runId,
				'status' => 'applied',
				'calculatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
			],
			'PayrollAdjustment'
		);

		return null;

	}//end settle()

	/**
	 * Whether a payslip was charged the low rate on a basis that may be
	 * reviewed (not the BBL or young part-timer exception). A payslip from
	 * before the basis was stamped counts when its input was low.
	 *
	 * @param array<string, mixed> $slip The payslip.
	 *
	 * @return bool
	 */
	private function reviewable(array $slip): bool {
		$basis = trim((string)($slip['awfTariffBasis'] ?? ''));
		if ($basis !== '') {
			return ((string)($slip['awfTariff'] ?? '') === 'low') && in_array($basis, AwfTariffResolver::REVIEWABLE_BASES, true) === true;
		}

		$snapshot = ($slip['engineInputSnapshot'] ?? null);
		return (is_array($snapshot) === true && (string)($snapshot['awfTariff'] ?? '') === 'low');

	}//end reviewable()

	/**
	 * A payslip as an hours row for the year figures: the paid hours
	 * (worked plus overtime) and whether the period was charged low.
	 *
	 * @param array<string, mixed> $slip The payslip.
	 *
	 * @return array{period: string, hours: float, low: bool}
	 */
	private function hoursRow(array $slip): array {
		$snapshot = ($slip['engineInputSnapshot'] ?? null);
		$low = ((string)($slip['awfTariff'] ?? (is_array($snapshot) === true ? ($snapshot['awfTariff'] ?? '') : '')) === 'low');
		return [
			'period' => (string)($slip['period'] ?? ''),
			'hours' => ((float)($slip['hoursWorked'] ?? 0.0) + (float)($slip['overtimeHours'] ?? 0.0)),
			'low' => $low,
		];

	}//end hoursRow()

	/**
	 * Every employee's contracts, keyed by the employee's object id (a
	 * contract may name the employee by id, slug or employee number).
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function contractsByEmployee(): array {
		$idByKey = [];
		foreach ($this->gateway->loadAll('Employee') as $employee) {
			$id = (string)($employee['id'] ?? '');
			foreach ([$id, (string)($employee['@self']['slug'] ?? ''), trim((string)($employee['employeeNumber'] ?? ''))] as $key) {
				if ($key !== '') {
					$idByKey[$key] = $id;
				}
			}
		}

		$out = [];
		foreach ($this->gateway->loadAll('EmploymentContract') as $contract) {
			$employeeId = ($idByKey[trim((string)($contract['employeeId'] ?? ''))] ?? '');
			if ($employeeId !== '') {
				$out[$employeeId][] = $contract;
			}
		}

		return $out;

	}//end contractsByEmployee()

	/**
	 * Every payslip, keyed by employee id.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function payslipsByEmployee(): array {
		$out = [];
		foreach ($this->gateway->loadAll('Payslip') as $slip) {
			$employeeId = (string)($slip['employeeId'] ?? '');
			if ($employeeId !== '') {
				$out[$employeeId][] = $slip;
			}
		}

		return $out;

	}//end payslipsByEmployee()

	/**
	 * The ids of every run that is no longer a draft.
	 *
	 * @return array<string, bool>
	 */
	private function sealedRunIds(): array {
		$out = [];
		foreach ($this->gateway->loadAll('PayrollRun') as $run) {
			if (in_array((string)($run['status'] ?? ''), ['approved', 'posted', 'paid'], true) === true) {
				$out[(string)($run['id'] ?? '')] = true;
			}
		}

		return $out;

	}//end sealedRunIds()

	/**
	 * The payslips a review adjustment already settles.
	 *
	 * @return array<string, bool>
	 */
	private function reviewedPayslipIds(): array {
		$out = [];
		foreach ($this->gateway->loadAll('PayrollAdjustment') as $adjustment) {
			if ((string)($adjustment['correctionType'] ?? '') === self::CORRECTION_TYPE) {
				$out[(string)($adjustment['originalPayslipId'] ?? '')] = true;
			}
		}

		return $out;

	}//end reviewedPayslipIds()
}//end class
