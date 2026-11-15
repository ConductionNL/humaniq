<?php

/**
 * LineFacts
 *
 * The facts of one income relationship of the wage tax return: the
 * employee, the covering contract, the run's payslip, the engine's
 * recalculation and the declaration period, with the findings recorded
 * while the line is made (filings-wage-tax-message D5).
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll\Loonaangifte
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Loonaangifte;

use OCA\Humaniq\Payroll\CalculationResult;

/**
 * The facts and findings of one income relationship.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
 */
final class LineFacts {

	/**
	 * The findings recorded so far.
	 *
	 * @var list<array{kind: string, severity: string, employeeId: string, element: string, problem: string}>
	 */
	private array $findings = [];

	/**
	 * The facts.
	 *
	 * @param array<string, mixed>        $employee The Employee, with its id.
	 * @param array<string, mixed>        $contract The covering EmploymentContract, or empty.
	 * @param array<string, mixed>        $payslip  The run's Payslip.
	 * @param CalculationResult|null      $result   The engine's recalculation, or null when it failed.
	 * @param array{0: string, 1: string} $period   The declaration period's first and last day.
	 */
	public function __construct(
		public readonly array $employee,
		public readonly array $contract,
		public readonly array $payslip,
		public readonly ?CalculationResult $result,
		public readonly array $period,
	) {
	}//end __construct()

	/**
	 * A filled employee string, trimmed, or null.
	 *
	 * @param string $field The field.
	 *
	 * @return string|null
	 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public function filled(string $field): ?string {
		$value = trim((string)($this->employee[$field] ?? ''));
		return $value === '' ? null : $value;
	}//end filled()

	/**
	 * A payslip amount in cents.
	 *
	 * @param string $field The field.
	 *
	 * @return int
	 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public function money(string $field): int {
		return is_numeric($this->payslip[$field] ?? null) === true ? (int)round((float)$this->payslip[$field] * 100) : 0;
	}//end money()

	/**
	 * The engine input of the payslip.
	 *
	 * @return array<string, mixed>
	 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public function snapshot(): array {
		return is_array($this->payslip['engineInputSnapshot'] ?? null) === true ? $this->payslip['engineInputSnapshot'] : [];
	}//end snapshot()

	/**
	 * Whether the employee is insured for the employee insurances.
	 *
	 * @return bool
	 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public function insured(): bool {
		return (($this->snapshot()['verzekeringsplichtig'] ?? true) !== false);
	}//end insured()

	/**
	 * Whether the anonymous rate was applied.
	 *
	 * @return bool
	 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public function anonymous(): bool {
		return (($this->payslip['anoniementariefApplied'] ?? false) === true);
	}//end anonymous()

	/**
	 * Whether the Zvw contribution was withheld rather than levied on the employer.
	 *
	 * @return bool
	 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public function withheldZvw(): bool {
		return (($this->payslip['zvwMode'] ?? '') === 'inhouding');
	}//end withheldZvw()

	/**
	 * The contracted hours per week.
	 *
	 * @return float
	 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public function contractHours(): float {
		return is_numeric($this->contract['hoursPerWeek'] ?? null) === true ? (float)$this->contract['hoursPerWeek'] : 0.0;
	}//end contractHours()

	/**
	 * The employee's display name.
	 *
	 * @return string
	 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public function name(): string {
		$name = trim(((string)($this->employee['firstName'] ?? '')) . ' ' . ((string)($this->employee['lastName'] ?? '')));
		return $name === '' ? (string)($this->employee['id'] ?? 'Een medewerker') : $name;
	}//end name()

	/**
	 * Record a finding.
	 *
	 * @param string $kind     The finding kind.
	 * @param string $element  The message element it concerns.
	 * @param string $problem  What is wrong, for the payroll officer.
	 * @param string $severity Blocking or warning.
	 *
	 * @return void
	 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public function find(string $kind, string $element, string $problem, string $severity='blocking'): void {
		$this->findings[] = ['kind' => $kind, 'severity' => $severity, 'employeeId' => (string)($this->employee['id'] ?? ''), 'element' => $element, 'problem' => $problem];
	}//end find()

	/**
	 * The findings recorded.
	 *
	 * @return list<array{kind: string, severity: string, employeeId: string, element: string, problem: string}>
	 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public function findings(): array {
		return $this->findings;
	}//end findings()

}//end class
