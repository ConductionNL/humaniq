<?php

/**
 * The check of one payroll run, kept on the run.
 *
 * Four sources become PayrollRunFinding objects: every employee the
 * calculation skipped (blocking), approved timesheets and payroll-route
 * claims of the period that no run pays (warning), the rule audit of the
 * run's payslips (a mandatory violation blocks, others warn), and deviations
 * from each employee's own paid history (PayAnomalyDetector). A check
 * replaces the run's earlier findings, except an acknowledged one found again,
 * which keeps its acknowledgement and takes the new values. The run records
 * when it was checked and its counts. The check informs the approval; it
 * never blocks it (payroll-run-checks D1, D2).
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
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-001
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Composes, stores and counts the findings of one run.
 *
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-001
 */
class PayrollRunCheckService {

	/**
	 * Run statuses whose payslips count as paid history.
	 *
	 * @var list<string>
	 */
	private const PAID_STATUSES = ['approved', 'posted', 'paid'];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway  Reads the rows, writes the findings.
	 * @param RuleAuditService     $audit    The rule audit of a period's runs.
	 * @param PayAnomalyDetector   $detector The deviations.
	 * @param InternalWriteMarker  $marker   Marks the writes as humaniq's own.
	 * @param IAppConfig           $config   The payroll_check_thresholds setting.
	 * @param LoggerInterface      $logger   The logger.
	 *
	 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-001
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly RuleAuditService $audit,
		private readonly PayAnomalyDetector $detector,
		private readonly InternalWriteMarker $marker,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check one run and store its findings.
	 *
	 * @param string                     $runId   The run.
	 * @param list<array<string, mixed>> $skipped The employees the calculation skipped, with employeeId and reason.
	 *
	 * @return array{blocking: int, warning: int, info: int}
	 *
	 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-001
	 */
	public function check(string $runId, array $skipped = []): array {
		$counts = ['blocking' => 0, 'warning' => 0, 'info' => 0];
		$run = $this->gateway->findObjectData($runId, 'PayrollRun');
		if ($run === null) {
			return $counts;
		}

		$period = (string)($run['period'] ?? '');
		$checkedAt = gmdate('Y-m-d\TH:i:s\Z');
		$findings = array_merge(
			$this->skippedFindings(skipped: $skipped),
			$this->unpaidInputFindings(period: $period),
			$this->ruleFindings(run: $run, runId: $runId),
			$this->deviationFindings(runId: $runId, period: $period)
		);

		$this->store(runId: $runId, findings: $findings, checkedAt: $checkedAt);
		foreach ($findings as $finding) {
			++$counts[$finding['severity']];
		}

		unset($run['id'], $run['@self']);
		$run = array_merge($run, ['checkedAt' => $checkedAt, 'blockingFindings' => $counts['blocking'], 'warningFindings' => $counts['warning']]);
		$this->marker->runInternal(fn () => $this->gateway->save($run, 'PayrollRun', $runId));

		return $counts;
	}//end check()

	/**
	 * Every skipped employee is a blocking finding.
	 *
	 * @param list<array<string, mixed>> $skipped The skipped list.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function skippedFindings(array $skipped): array {
		$findings = [];
		foreach ($skipped as $entry) {
			$findings[] = self::finding(
				employeeId: (string)($entry['employeeId'] ?? ''),
				kind: 'skipped',
				severity: 'blocking',
				message: trim((string)($entry['employee'] ?? '') . ' wordt niet betaald: ' . (string)($entry['reason'] ?? 'onbekende reden') . '.')
			);
		}

		return $findings;
	}//end skippedFindings()

	/**
	 * Approved timesheets and payroll-route claims up to the period that no
	 * run has paid.
	 *
	 * @param string $period The run's period.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function unpaidInputFindings(string $period): array {
		$lastDay = ($period === '' ? '' : date('Y-m-t', (int)strtotime($period . '-01')));
		$findings = [];
		foreach ($this->rows('Timesheet') as $timesheet) {
			if ((string)($timesheet['status'] ?? '') === 'approved' && (string)($timesheet['payrollRunId'] ?? '') === '' && (string)($timesheet['period'] ?? '') <= $period) {
				$findings[] = self::finding(employeeId: (string)($timesheet['employeeId'] ?? ''), kind: 'unpaid-input', severity: 'warning', message: 'Een goedgekeurde urenstaat van ' . (string)($timesheet['period'] ?? '') . ' wordt door geen loonrun betaald.', ruleId: 'timesheet');
			}
		}

		foreach ($this->rows('Expense') as $claim) {
			$approvedOn = substr((string)($claim['approvedAt'] ?? ''), 0, 10);
			if ((string)($claim['status'] ?? '') === 'approved' && (string)($claim['reimbursementRoute'] ?? '') === 'payroll' && (string)($claim['payrollRunId'] ?? '') === '' && $approvedOn !== '' && $approvedOn <= $lastDay) {
				$findings[] = self::finding(employeeId: (string)($claim['employeeId'] ?? ''), kind: 'unpaid-input', severity: 'warning', message: 'Een goedgekeurde declaratie voor de salarisrun wordt door geen loonrun betaald.', ruleId: 'expense');
			}
		}

		return $findings;
	}//end unpaidInputFindings()

	/**
	 * The rule audit's violations on this run and its payslips.
	 *
	 * @param array<string, mixed> $run   The run.
	 * @param string               $runId The run's id.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function ruleFindings(array $run, string $runId): array {
		$employeeByPayslip = [$runId => ''];
		foreach ($this->rows('Payslip') as $payslip) {
			if ((string)($payslip['payrollRunId'] ?? '') === $runId) {
				$employeeByPayslip[self::idOf($payslip)] = (string)($payslip['employeeId'] ?? '');
			}
		}

		try {
			$report = $this->audit->auditPayrollRunScope((string)($run['period'] ?? ''), ((string)($run['administrationId'] ?? '') === '' ? null : (string)$run['administrationId']));
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: the rule audit of payroll run ' . $runId . ' could not run: ' . $e->getMessage());
			return [];
		}

		$findings = [];
		foreach ((array)($report['violations'] ?? []) as $violation) {
			$objectId = (string)($violation['objectId'] ?? '');
			if (array_key_exists($objectId, $employeeByPayslip) === false) {
				continue;
			}

			$findings[] = self::finding(
				employeeId: $employeeByPayslip[$objectId],
				kind: 'rule-violation',
				severity: ((string)($violation['severity'] ?? '') === 'mandatory' ? 'blocking' : 'warning'),
				message: (string)($violation['statement'] ?? ''),
				ruleId: (string)($violation['ruleId'] ?? '')
			);
		}//end foreach

		return $findings;
	}//end ruleFindings()

	/**
	 * Deviations of this run's payslips from each employee's paid history.
	 *
	 * @param string $runId  The run.
	 * @param string $period The run's period.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function deviationFindings(string $runId, string $period): array {
		$paidRuns = [];
		foreach ($this->rows('PayrollRun') as $run) {
			if (in_array((string)($run['status'] ?? ''), self::PAID_STATUSES, true) === true) {
				$paidRuns[self::idOf($run)] = true;
			}
		}

		$current = [];
		$history = [];
		foreach ($this->rows('Payslip') as $payslip) {
			$heldBy = (string)($payslip['payrollRunId'] ?? '');
			if ($heldBy === $runId) {
				$current[] = $payslip;
			} else if ($heldBy === '' || isset($paidRuns[$heldBy]) === true) {
				$history[(string)($payslip['employeeId'] ?? '')][] = $payslip;
			}
		}

		$raised = $this->raisedEmployees(period: $period);
		$thresholds = $this->thresholds();
		$findings = [];
		foreach ($current as $payslip) {
			$employeeId = (string)($payslip['employeeId'] ?? '');
			foreach ($this->detector->detect(payslip: $payslip, history: ($history[$employeeId] ?? []), thresholds: $thresholds, raiseApplied: isset($raised[$employeeId])) as $deviation) {
				$findings[] = array_merge(self::finding(employeeId: $employeeId, kind: 'deviation', severity: $deviation['severity'], message: $deviation['message']), array_intersect_key($deviation, array_flip(['component', 'currentValue', 'baselineValue', 'explanation'])));
			}
		}

		return $findings;
	}//end deviationFindings()

	/**
	 * The threshold overrides from the `payroll_check_thresholds` setting.
	 *
	 * The setting is JSON keyed by component, each with a numeric `relative`
	 * share and `absolute` floor. An unknown component, a non-numeric value or
	 * a setting that is not JSON is ignored, so the defaults apply.
	 *
	 * @return array<string, array{relative: float, absolute: float}>
	 *
	 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-003
	 */
	private function thresholds(): array {
		$decoded = json_decode($this->config->getValueString(Application::APP_ID, 'payroll_check_thresholds', ''), true);
		if (is_array($decoded) === false) {
			return [];
		}

		$thresholds = [];
		foreach (array_keys(PayAnomalyDetector::DEFAULT_THRESHOLDS) as $component) {
			$entry = ($decoded[$component] ?? null);
			if (is_array($entry) === true && is_numeric($entry['relative'] ?? null) === true && is_numeric($entry['absolute'] ?? null) === true) {
				$thresholds[$component] = ['relative' => (float)$entry['relative'], 'absolute' => (float)$entry['absolute']];
			}
		}

		return $thresholds;
	}//end thresholds()

	/**
	 * Employees whose raise reached the salary in the period.
	 *
	 * @param string $period The period.
	 *
	 * @return array<string, true>
	 */
	private function raisedEmployees(string $period): array {
		$raised = [];
		foreach ($this->rows('CompAdjustment') as $adjustment) {
			if ($period !== '' && str_starts_with((string)($adjustment['appliedAt'] ?? ''), $period) === true) {
				$raised[(string)($adjustment['employeeId'] ?? '')] = true;
			}
		}

		return $raised;
	}//end raisedEmployees()

	/**
	 * Replace the run's findings, keeping an acknowledged one found again.
	 *
	 * @param string                     $runId     The run.
	 * @param list<array<string, mixed>> $findings  The new findings.
	 * @param string                     $checkedAt When.
	 *
	 * @return void
	 */
	private function store(string $runId, array $findings, string $checkedAt): void {
		$acknowledged = [];
		foreach ($this->rows('PayrollRunFinding') as $existing) {
			if ((string)($existing['payrollRunId'] ?? '') !== $runId) {
				continue;
			}

			if ((string)($existing['status'] ?? '') === 'acknowledged') {
				$acknowledged[self::keyOf($existing)] = $existing;
				continue;
			}

			$this->marker->runInternal(fn () => $this->gateway->delete(self::idOf($existing), 'PayrollRunFinding'));
		}

		foreach ($findings as $finding) {
			$payload = array_merge($finding, ['payrollRunId' => $runId, 'checkedAt' => $checkedAt, 'status' => 'open']);
			$previous = ($acknowledged[self::keyOf($finding)] ?? null);
			$uuid = null;
			if ($previous !== null) {
				unset($acknowledged[self::keyOf($finding)]);
				$uuid = self::idOf($previous);
				$payload = array_merge($payload, array_intersect_key($previous, array_flip(['status', 'acknowledgementNote', 'acknowledgedBy'])));
			}

			$this->marker->runInternal(fn () => $this->gateway->save($payload, 'PayrollRunFinding', $uuid));
		}

		foreach ($acknowledged as $resolved) {
			$this->marker->runInternal(fn () => $this->gateway->delete(self::idOf($resolved), 'PayrollRunFinding'));
		}
	}//end store()

	/**
	 * A finding.
	 *
	 * @param string      $employeeId The employee.
	 * @param string      $kind       The kind.
	 * @param string      $severity   The severity.
	 * @param string      $message    The message.
	 * @param string|null $ruleId     The rule or input type.
	 *
	 * @return array<string, mixed>
	 */
	private static function finding(string $employeeId, string $kind, string $severity, string $message, ?string $ruleId = null): array {
		return ['employeeId' => $employeeId, 'kind' => $kind, 'severity' => $severity, 'message' => $message, 'ruleId' => $ruleId, 'component' => null, 'currentValue' => null, 'baselineValue' => null, 'explanation' => null];
	}//end finding()

	/**
	 * The identity of a finding across checks.
	 *
	 * @param array<string, mixed> $finding The finding.
	 *
	 * @return string
	 */
	private static function keyOf(array $finding): string {
		return implode('|', [(string)($finding['employeeId'] ?? ''), (string)($finding['kind'] ?? ''), (string)($finding['ruleId'] ?? ($finding['component'] ?? ''))]);
	}//end keyOf()

	/**
	 * Every row of a schema; none when the register cannot be read.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $schema): array {
		try {
			return array_values($this->gateway->loadAll($schema));
		} catch (\Throwable) {
			return [];
		}
	}//end rows()

	/**
	 * A row's id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
	}//end idOf()

}//end class
