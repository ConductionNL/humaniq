<?php

/**
 * Payroll Handoff Service
 *
 * Compiles one period's payroll mutations for an administration whose
 * payroll an outside bureau runs, as differences from what the previous
 * handoffs sent (payroll-external-bureau-handoff D2), and checks the
 * bureau's returned payslips for completeness (D4). Delivery and return go
 * through integriq on the handoff's declared lifecycle; this service holds
 * no bureau format or credential.
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
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Log\LoggerInterface;

/**
 * Compiles bureau handoffs and checks their intake.
 *
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
 */
class PayrollHandoffService {

	/**
	 * The employee-level payroll facts per mutation kind.
	 *
	 * @var array<string, list<string>>
	 */
	private const EMPLOYEE_SLICES = [
		'salary' => ['grossMonthlySalary'],
		'bank-account' => ['iban'],
		'tax-settings' => ['taxTableColor', 'loonheffingskortingToegepast'],
		'leave' => ['endDate'],
	];

	/**
	 * The contract facts a bureau needs.
	 *
	 * @var list<string>
	 */
	private const CONTRACT_FIELDS = ['type', 'hoursPerWeek', 'hourlyWage', 'cao', 'caoSchaal'];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The register plumbing.
	 * @param LoggerInterface      $logger  The logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Compile (or recompile, while in concept) the handoff of one
	 * administration and period.
	 *
	 * @param string $administrationId The administration.
	 * @param string $period           The wage period, YYYY-MM.
	 * @param string $userId           The account compiling it.
	 *
	 * @return array{status: string, handoffId: string, mutationCount: int, message?: string}
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
	 */
	public function compile(string $administrationId, string $period, string $userId): array {
		if ($this->isOutsourced($administrationId) === false) {
			return ['status' => 'refused-engine', 'handoffId' => '', 'mutationCount' => 0, 'message' => 'Deze administratie wordt door humaniq verloond; er is geen overdracht.'];
		}

		$handoffs = $this->gateway->findFiltered('PayrollHandoff', ['administrationId' => $administrationId]);
		$current = null;
		foreach ($handoffs as $handoff) {
			if ((string)($handoff['period'] ?? '') === $period) {
				$current = $handoff;
			}
		}

		if ($current !== null && (string)($current['status'] ?? '') !== 'concept') {
			return ['status' => 'refused-not-concept', 'handoffId' => (string)$current['id'], 'mutationCount' => (int)($current['mutationCount'] ?? 0), 'message' => 'Deze overdracht is al klaargezet of verzonden; heropen hem eerst.'];
		}

		$handoffId = ($current === null ? '' : (string)$current['id']);
		if ($handoffId !== '') {
			foreach ($this->gateway->findFiltered('PayrollHandoffMutation', ['handoffId' => $handoffId]) as $stale) {
				$this->gateway->delete((string)$stale['id'], 'PayrollHandoffMutation');
			}
		}

		$baseline = $this->baseline(handoffs: $handoffs, period: $period, exceptId: $handoffId);
		$mutations = [];
		foreach ($this->employeesIn(administrationId: $administrationId, period: $period) as $employee) {
			$view = $this->viewOf(employee: $employee);
			$employeeId = (string)$employee['id'];
			foreach ($this->differences(employee: $employee, view: $view, sent: ($baseline[$employeeId] ?? null), period: $period) as $mutation) {
				$mutations[] = $mutation;
			}
		}

		$saved = $this->gateway->save(
			payload: array_merge(
				($current ?? []),
				['administrationId' => $administrationId, 'period' => $period, 'status' => 'concept', 'compiledBy' => $userId, 'compiledAt' => gmdate('Y-m-d\TH:i:s\Z'), 'mutationCount' => count($mutations)]
			),
			schema: 'PayrollHandoff',
			uuid: ($handoffId === '' ? null : $handoffId)
		);
		$handoffId = (string)$saved->getUuid();

		foreach ($mutations as $mutation) {
			$this->gateway->save(payload: array_merge($mutation, ['handoffId' => $handoffId, 'administrationId' => $administrationId]), schema: 'PayrollHandoffMutation');
		}

		return ['status' => 'compiled', 'handoffId' => $handoffId, 'mutationCount' => count($mutations)];
	}//end compile()

	/**
	 * Check the bureau's returned payslips: every employee of the period
	 * has exactly one, and none names someone outside the administration.
	 * Returned payslips are stamped with the employee's account.
	 *
	 * @param string $handoffId The handoff.
	 *
	 * @return array{blocking: int, findings: list<array<string, mixed>>}
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-004
	 */
	public function checkIntake(string $handoffId): array {
		$handoff = $this->gateway->findObjectData($handoffId, 'PayrollHandoff');
		if ($handoff === null) {
			return ['blocking' => 0, 'findings' => []];
		}

		$expected = [];
		foreach ($this->employeesIn(administrationId: (string)($handoff['administrationId'] ?? ''), period: (string)($handoff['period'] ?? '')) as $employee) {
			$expected[(string)$employee['id']] = $employee;
		}

		$returned = [];
		$findings = [];
		foreach ($this->gateway->findFiltered('Payslip', ['payrollHandoffId' => $handoffId]) as $payslip) {
			$employeeId = (string)($payslip['employeeId'] ?? '');
			$returned[$employeeId] = (($returned[$employeeId] ?? 0) + 1);
			if (isset($expected[$employeeId]) === false) {
				$findings[] = ['kind' => 'unknown-employee', 'employeeId' => $employeeId, 'message' => 'Het bureau leverde een loonstrook voor iemand buiten deze administratie.'];
				continue;
			}

			$this->stampPayslip(payslip: $payslip, employee: $expected[$employeeId]);
		}

		foreach ($expected as $employeeId => $employee) {
			$count = ($returned[$employeeId] ?? 0);
			if ($count === 0) {
				$findings[] = ['kind' => 'missing-payslip', 'employeeId' => $employeeId, 'message' => 'Geen loonstrook terug van het bureau.'];
			} else if ($count > 1) {
				$findings[] = ['kind' => 'duplicate-payslip', 'employeeId' => $employeeId, 'message' => 'Meer dan één loonstrook terug van het bureau.'];
			}
		}

		$payload = array_merge($handoff, ['intakeFindings' => $findings, 'blockingFindings' => count($findings)]);
		unset($payload['id']);
		$this->gateway->save(payload: $payload, schema: 'PayrollHandoff', uuid: $handoffId);

		return ['blocking' => count($findings), 'findings' => $findings];
	}//end checkIntake()

	/**
	 * Whether an outside bureau runs the administration's payroll.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return bool
	 */
	private function isOutsourced(string $administrationId): bool {
		foreach ($this->gateway->findFiltered('hrAdministration', ['administrationId' => $administrationId]) as $administration) {
			if (($administration['payrollProcessing'] ?? 'engine') === 'external-bureau') {
				return true;
			}
		}

		return false;
	}//end isOutsourced()

	/**
	 * What earlier handoffs sent, per employee: their mutations' sent state
	 * folded in period order. A handoff still in concept sent nothing.
	 *
	 * @param list<array<string, mixed>> $handoffs The administration's handoffs.
	 * @param string                     $period   The period being compiled.
	 * @param string                     $exceptId The handoff being compiled.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function baseline(array $handoffs, string $period, string $exceptId): array {
		usort($handoffs, static fn (array $a, array $b): int => strcmp((string)($a['period'] ?? ''), (string)($b['period'] ?? '')));
		$sent = [];
		foreach ($handoffs as $handoff) {
			if ((string)$handoff['id'] === $exceptId || ($handoff['status'] ?? '') === 'concept' || (string)($handoff['period'] ?? '') > $period) {
				continue;
			}

			foreach ($this->gateway->findFiltered('PayrollHandoffMutation', ['handoffId' => (string)$handoff['id']]) as $mutation) {
				$employeeId = (string)($mutation['employeeId'] ?? '');
				$sent[$employeeId] = array_merge(($sent[$employeeId] ?? []), (array)($mutation['sentState'] ?? []));
			}
		}

		return $sent;
	}//end baseline()

	/**
	 * The employees of the administration employed in any day of the period.
	 *
	 * @param string $administrationId The administration.
	 * @param string $period           The period.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function employeesIn(string $administrationId, string $period): array {
		$first = $period . '-01';
		$last = date('Y-m-t', (int)strtotime($first));
		$out = [];
		foreach ($this->gateway->findFiltered('Employee', ['administrationId' => $administrationId]) as $employee) {
			$start = (string)($employee['startDate'] ?? '');
			$end = (string)($employee['endDate'] ?? '');
			if (($start === '' || $start <= $last) && ($end === '' || $end >= $first)) {
				$out[] = $employee;
			}
		}

		return $out;
	}//end employeesIn()

	/**
	 * The payroll view of one employee, per mutation kind.
	 *
	 * @param array<string, mixed> $employee The employee.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function viewOf(array $employee): array {
		$view = [];
		foreach (self::EMPLOYEE_SLICES as $kind => $fields) {
			foreach ($fields as $field) {
				$view[$kind][$field] = $this->normalise($employee[$field] ?? null);
			}
		}

		$contract = $this->latestContract((string)$employee['id']);
		foreach (self::CONTRACT_FIELDS as $field) {
			$view['contract'][$field] = $this->normalise($contract[$field] ?? null);
		}

		return $view;
	}//end viewOf()

	/**
	 * The mutations of one employee: one start for someone never sent, else
	 * one per kind whose values differ from what was sent.
	 *
	 * @param array<string, mixed>                     $employee The employee.
	 * @param array<string, array<string, mixed>>      $view     The payroll view.
	 * @param array<string, mixed>|null                $sent     What earlier handoffs sent, or null.
	 * @param string                                   $period   The period.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function differences(array $employee, array $view, ?array $sent, string $period): array {
		$employeeId = (string)$employee['id'];
		if ($sent === null) {
			$fields = [];
			foreach ($view as $values) {
				foreach ($values as $field => $value) {
					$fields[$field] = ['old' => null, 'new' => $value];
				}
			}

			return [['employeeId' => $employeeId, 'kind' => 'start', 'effectiveDate' => ((string)($employee['startDate'] ?? '') === '' ? $period . '-01' : (string)$employee['startDate']), 'fields' => $fields, 'sourceSchema' => 'Employee', 'sourceId' => $employeeId, 'sentState' => $view]];
		}

		$mutations = [];
		foreach ($view as $kind => $values) {
			$fields = [];
			foreach ($values as $field => $value) {
				$old = ($sent[$kind][$field] ?? null);
				if ($old !== $value) {
					$fields[$field] = ['old' => $old, 'new' => $value];
				}
			}

			if ($fields === []) {
				continue;
			}

			[$sourceSchema, $sourceId, $effective] = $this->sourceOf(kind: $kind, employee: $employee, period: $period);
			$mutations[] = ['employeeId' => $employeeId, 'kind' => $kind, 'effectiveDate' => $effective, 'fields' => $fields, 'sourceSchema' => $sourceSchema, 'sourceId' => $sourceId, 'sentState' => [$kind => $values]];
		}

		return $mutations;
	}//end differences()

	/**
	 * Where a change came from and from when it applies: a salary change
	 * from the latest applied pay change, a contract change from the
	 * contract, anything else from the employee record.
	 *
	 * @param string               $kind     The mutation kind.
	 * @param array<string, mixed> $employee The employee.
	 * @param string               $period   The period.
	 *
	 * @return array{0: string, 1: string, 2: string}
	 */
	private function sourceOf(string $kind, array $employee, string $period): array {
		$employeeId = (string)$employee['id'];
		if ($kind === 'salary') {
			$latest = null;
			foreach ($this->gateway->findFiltered('CompAdjustment', ['employeeId' => $employeeId, 'status' => 'applied']) as $adjustment) {
				if ($latest === null || (string)($adjustment['appliedAt'] ?? '') > (string)($latest['appliedAt'] ?? '')) {
					$latest = $adjustment;
				}
			}

			if ($latest !== null) {
				return ['CompAdjustment', (string)$latest['id'], substr((string)($latest['appliedAt'] ?? ''), 0, 10)];
			}
		}

		if ($kind === 'contract') {
			$contract = $this->latestContract($employeeId);
			if ($contract !== []) {
				return ['EmploymentContract', (string)$contract['id'], (string)($contract['startDate'] ?? ($period . '-01'))];
			}
		}

		if ($kind === 'leave' && (string)($employee['endDate'] ?? '') !== '') {
			return ['Employee', $employeeId, (string)$employee['endDate']];
		}

		return ['Employee', $employeeId, $period . '-01'];
	}//end sourceOf()

	/**
	 * The employee's most recent contract, or [].
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return array<string, mixed>
	 */
	private function latestContract(string $employeeId): array {
		$latest = [];
		foreach ($this->gateway->findFiltered('EmploymentContract', ['employeeId' => $employeeId]) as $contract) {
			if ($latest === [] || (string)($contract['startDate'] ?? '') > (string)($latest['startDate'] ?? '')) {
				$latest = $contract;
			}
		}

		return $latest;
	}//end latestContract()

	/**
	 * Stamp a returned payslip with the employee's account and its source.
	 *
	 * @param array<string, mixed> $payslip  The payslip.
	 * @param array<string, mixed> $employee The employee.
	 *
	 * @return void
	 */
	private function stampPayslip(array $payslip, array $employee): void {
		$stamps = ['externalSource' => 'external-bureau'];
		$account = trim((string)($employee['nextcloudUserId'] ?? ''));
		if (trim((string)($payslip['userId'] ?? '')) === '' && $account !== '') {
			$stamps['userId'] = $account;
		}

		if (array_diff_assoc($stamps, array_intersect_key($payslip, $stamps)) === []) {
			return;
		}

		$id = (string)$payslip['id'];
		unset($payslip['id']);
		try {
			$this->gateway->save(payload: array_merge($payslip, $stamps), schema: 'Payslip', uuid: $id);
		} catch (\Throwable $e) {
			$this->logger->warning('PayrollHandoffService: could not stamp payslip ' . $id . ': ' . $e->getMessage());
		}
	}//end stampPayslip()

	/**
	 * A comparable value: numbers as floats, empty strings as null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return mixed
	 */
	private function normalise(mixed $value): mixed {
		if (is_int($value) === true || (is_string($value) === true && is_numeric($value) === true && preg_match('/^\d+(\.\d+)?$/', $value) === 1 && str_contains($value, '.'))) {
			return (float)$value;
		}

		return ($value === '' ? null : $value);
	}//end normalise()

}//end class
