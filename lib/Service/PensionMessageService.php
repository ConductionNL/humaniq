<?php

/**
 * PensionMessageService
 *
 * The figures a pension delivery reports for one PensionFiling: the fund's
 * scheme for the filing's administration and period, and per payslip of the
 * approved run the pensionable wage, the part-time factor, the pension base
 * and the contributions, or why an employee cannot be reported
 * (filings-pension-upa-message D2).
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
 * @spec openspec/changes/filings-pension-upa-message/specs/pension-upa-message/spec.md#REQ-PUM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Payroll\Pension\PensionBase;

/**
 * Reads a filing's scheme, run, payslips and contracts and computes the lines.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) PensionBase is pure and static by design, the LoonaangifteMessageBuilder precedent.
 *
 * @spec openspec/changes/filings-pension-upa-message/specs/pension-upa-message/spec.md#REQ-PUM-001
 */
class PensionMessageService {

	/**
	 * The run states a delivery is made from, as PayrollRunApprovedGuard allows.
	 *
	 * @var list<string>
	 */
	private const FILED_RUN_STATES = ['approved', 'posted', 'paid'];

	/**
	 * The service.
	 *
	 * @param RecordStoreInterface $gateway           Register reads.
	 * @param float                $fullTimeHoursWeek The hours per week of a full-time employee.
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
		private readonly float $fullTimeHoursWeek=AbsenceRateService::DEFAULT_FULL_TIME_HOURS_PER_WEEK,
	) {
	}//end __construct()

	/**
	 * The scheme, the delivery format, one line per employee and the findings
	 * of a pension filing. Any finding means no delivery can be made yet.
	 *
	 * @param array<string, mixed> $filing The PensionFiling.
	 *
	 * @return array{scheme: array<string, mixed>|null, messageFormat: string, lines: list<array<string, mixed>>, findings: list<array{kind: string, severity: string, employeeId: string, problem: string}>}
	 *
	 * @spec openspec/changes/filings-pension-upa-message/specs/pension-upa-message/spec.md#REQ-PUM-001
	 * @spec openspec/changes/filings-pension-upa-message/specs/pension-upa-message/spec.md#REQ-PUM-002
	 */
	public function lines(array $filing): array {
		$outcome = ['scheme' => null, 'messageFormat' => '', 'lines' => [], 'findings' => []];
		$period = self::periodDates(period: (string)($filing['period'] ?? ''));
		if ($period === null) {
			$outcome['findings'][] = self::finding(kind: 'period', employeeId: '', problem: 'De pensioenaangifte heeft geen geldige periode (JJJJ-MM).');
			return $outcome;
		}

		$fund = (string)($filing['fund'] ?? '');
		$scheme = $this->schemeFor(administrationId: (string)($filing['administrationId'] ?? ''), fund: $fund, period: $period);
		if ($scheme === null) {
			$outcome['findings'][] = self::finding(kind: 'no-scheme', employeeId: '', problem: 'Er is geen pensioenregeling voor fonds ' . $fund . ' die geldt in ' . (string)$filing['period'] . '.');
		}

		$run = $this->approvedRun(runId: trim((string)($filing['payrollRunId'] ?? '')));
		if ($run === null) {
			$outcome['findings'][] = self::finding(kind: 'run-not-approved', employeeId: '', problem: 'De pensioenaangifte verwijst niet naar een goedgekeurde, geboekte of betaalde loonrun.');
		}

		if ($scheme === null || $run === null) {
			return $outcome;
		}

		$outcome['scheme'] = $scheme;
		$outcome['messageFormat'] = (string)($scheme['messageFormat'] ?? '');
		foreach ($this->gateway->findFiltered('Payslip', ['payrollRunId' => (string)($run['id'] ?? '')]) as $slip) {
			$line = $this->line(slip: $slip, scheme: $scheme, period: $period);
			if (isset($line['finding']) === true) {
				$outcome['findings'][] = $line['finding'];
				continue;
			}

			$outcome['lines'][] = $line;
		}

		return $outcome;
	}//end lines()

	/**
	 * One employee's line, or the finding that keeps it out.
	 *
	 * @param array<string, mixed>        $slip   The payslip.
	 * @param array<string, mixed>        $scheme The scheme.
	 * @param array{0: string, 1: string} $period The first and last day of the period.
	 *
	 * @return array<string, mixed>
	 */
	private function line(array $slip, array $scheme, array $period): array {
		$employeeId = (string)($slip['employeeId'] ?? '');
		$employee = ($this->gateway->findObjectData($employeeId, 'Employee') ?? []);
		$name = trim((string)($employee['firstName'] ?? '') . ' ' . (string)($employee['lastName'] ?? ''));
		if ($name === '') {
			$name = $employeeId;
		}

		$contract = $this->contractOf(employeeId: $employeeId, period: $period);
		if ($contract === []) {
			return ['finding' => self::finding(kind: 'no-contract', employeeId: $employeeId, problem: $name . ' heeft geen arbeidsovereenkomst in deze periode, dus de deeltijdfactor ontbreekt.')];
		}

		$hours = ($contract['hoursPerWeek'] ?? null);
		if (is_numeric($hours) === false || (float)$hours <= 0.0) {
			return ['finding' => self::finding(kind: 'no-part-time-factor', employeeId: $employeeId, problem: $name . ' heeft een arbeidsovereenkomst zonder uren per week, dus de deeltijdfactor ontbreekt.')];
		}

		$figures = PensionBase::of(wage: (float)($slip['grossPay'] ?? 0), hoursPerWeek: (float)$hours, fullTimeHoursWeek: $this->fullTimeHoursWeek, scheme: $scheme);

		return array_merge(
			[
				'employeeId' => $employeeId,
				'employeeNumber' => (string)($employee['employeeNumber'] ?? ''),
				'startDate' => (string)($contract['startDate'] ?? ''),
				'endDate' => (string)($contract['endDate'] ?? ''),
			],
			$figures
		);
	}//end line()

	/**
	 * The fund's scheme for the administration that applies in the period;
	 * the latest started one when several do.
	 *
	 * @param string                      $administrationId The administration.
	 * @param string                      $fund             The fund.
	 * @param array{0: string, 1: string} $period           The first and last day of the period.
	 *
	 * @return array<string, mixed>|null
	 */
	private function schemeFor(string $administrationId, string $fund, array $period): ?array {
		$found = null;
		foreach ($this->gateway->findFiltered('PensionScheme', ['administrationId' => $administrationId, 'fund' => $fund]) as $scheme) {
			if (self::covers(from: (string)($scheme['validFrom'] ?? ''), until: (string)($scheme['validUntil'] ?? ''), period: $period) === false) {
				continue;
			}

			if ($found === null || (string)($scheme['validFrom'] ?? '') > (string)($found['validFrom'] ?? '')) {
				$found = $scheme;
			}
		}

		return $found;
	}//end schemeFor()

	/**
	 * The run the filing names, when it is approved, posted or paid.
	 *
	 * @param string $runId The PayrollRun id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function approvedRun(string $runId): ?array {
		if ($runId === '') {
			return null;
		}

		$run = $this->gateway->findObjectData($runId, 'PayrollRun');
		if ($run === null || in_array((string)($run['status'] ?? ''), self::FILED_RUN_STATES, true) === false) {
			return null;
		}

		$run['id'] = (string)($run['id'] ?? $runId);
		return $run;
	}//end approvedRun()

	/**
	 * The contract covering the period, the latest started when several do.
	 *
	 * @param string                      $employeeId The employee.
	 * @param array{0: string, 1: string} $period     The first and last day of the period.
	 *
	 * @return array<string, mixed>
	 */
	private function contractOf(string $employeeId, array $period): array {
		$covering = [];
		foreach ($this->gateway->findFiltered('EmploymentContract', ['employeeId' => $employeeId]) as $contract) {
			$start = (string)($contract['startDate'] ?? '');
			if (self::covers(from: $start, until: (string)($contract['endDate'] ?? ''), period: $period) === true) {
				$covering[$start . '|' . (string)($contract['id'] ?? '')] = $contract;
			}
		}

		krsort($covering);
		return (array_values($covering)[0] ?? []);
	}//end contractOf()

	/**
	 * Whether a dated span overlaps the period; an empty end is open.
	 *
	 * @param string                      $from   The first day, Y-m-d.
	 * @param string                      $until  The last day, Y-m-d, or ''.
	 * @param array{0: string, 1: string} $period The first and last day of the period.
	 *
	 * @return bool
	 */
	private static function covers(string $from, string $until, array $period): bool {
		return $from !== '' && $from <= $period[1] && ($until === '' || $until >= $period[0]);
	}//end covers()

	/**
	 * The first and last day of a `YYYY-MM` period, or null when it is not one.
	 *
	 * @param string $period The period.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function periodDates(string $period): ?array {
		if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period) !== 1) {
			return null;
		}

		$first = $period . '-01';
		return [$first, date('Y-m-t', (int)strtotime($first))];
	}//end periodDates()

	/**
	 * A blocking finding.
	 *
	 * @param string $kind       What is missing.
	 * @param string $employeeId The employee, or '' for the filing.
	 * @param string $problem    The sentence the filing shows.
	 *
	 * @return array{kind: string, severity: string, employeeId: string, problem: string}
	 */
	private static function finding(string $kind, string $employeeId, string $problem): array {
		return ['kind' => $kind, 'severity' => 'blocking', 'employeeId' => $employeeId, 'problem' => $problem];
	}//end finding()
}//end class
