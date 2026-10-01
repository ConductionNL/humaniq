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
	 * The period items (D2).
	 *
	 * @var HandoffPeriodItems
	 */
	private readonly HandoffPeriodItems $items;

	/**
	 * The employee-level view (D2).
	 *
	 * @var HandoffEmployeeView
	 */
	private readonly HandoffEmployeeView $view;

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway     $gateway The register plumbing.
	 * @param LoggerInterface          $logger  The logger.
	 * @param HandoffPeriodItems|null  $items   The period items; built on the gateway when absent.
	 * @param HandoffEmployeeView|null $view    The employee view; built on the gateway when absent.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly LoggerInterface $logger,
		?HandoffPeriodItems $items = null,
		?HandoffEmployeeView $view = null,
	) {
		$this->items = ($items ?? new HandoffPeriodItems($gateway));
		$this->view = ($view ?? new HandoffEmployeeView(gateway: $gateway, items: $this->items));
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
		$current = $this->handoffOf(handoffs: $handoffs, period: $period);
		if ($current !== null && (string)($current['status'] ?? '') !== 'concept') {
			return ['status' => 'refused-not-concept', 'handoffId' => (string)$current['id'], 'mutationCount' => (int)($current['mutationCount'] ?? 0), 'message' => 'Deze overdracht is al klaargezet of verzonden; heropen hem eerst.'];
		}

		$handoffId = $this->clearConcept($current);
		$mutations = $this->mutationsOf(administrationId: $administrationId, period: $period, baseline: $this->baseline(handoffs: $handoffs, period: $period, exceptId: $handoffId));

		$payload = array_merge(($current ?? []), ['administrationId' => $administrationId, 'period' => $period, 'status' => 'concept', 'compiledBy' => $userId, 'compiledAt' => gmdate('Y-m-d\TH:i:s\Z'), 'mutationCount' => count($mutations)]);
		unset($payload['id']);
		$saved = $this->gateway->save(payload: $payload, schema: 'PayrollHandoff', uuid: ($handoffId === '' ? null : $handoffId));
		$handoffId = (string)$saved->getUuid();

		foreach ($mutations as $mutation) {
			$this->gateway->save(payload: array_merge($mutation, ['handoffId' => $handoffId, 'administrationId' => $administrationId]), schema: 'PayrollHandoffMutation');
		}

		return ['status' => 'compiled', 'handoffId' => $handoffId, 'mutationCount' => count($mutations)];
	}//end compile()

	/**
	 * The administration's handoff of a period, or null.
	 *
	 * @param list<array<string, mixed>> $handoffs The administration's handoffs.
	 * @param string                     $period   The period.
	 *
	 * @return array<string, mixed>|null
	 */
	private function handoffOf(array $handoffs, string $period): ?array {
		foreach ($handoffs as $handoff) {
			if ((string)($handoff['period'] ?? '') === $period) {
				return $handoff;
			}
		}

		return null;
	}//end handoffOf()

	/**
	 * Remove the mutations of a concept handoff about to be recompiled.
	 *
	 * @param array<string, mixed>|null $current The handoff, or null.
	 *
	 * @return string The handoff id, or '' when there is none yet.
	 */
	private function clearConcept(?array $current): string {
		if ($current === null) {
			return '';
		}

		$handoffId = (string)$current['id'];
		foreach ($this->gateway->findFiltered('PayrollHandoffMutation', ['handoffId' => $handoffId]) as $stale) {
			$this->gateway->delete((string)$stale['id'], 'PayrollHandoffMutation');
		}

		return $handoffId;
	}//end clearConcept()

	/**
	 * The mutations of every employee of the administration in the period.
	 *
	 * @param string                              $administrationId The administration.
	 * @param string                              $period           The period.
	 * @param array<string, array<string, mixed>> $baseline         What earlier handoffs sent, per employee.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function mutationsOf(string $administrationId, string $period, array $baseline): array {
		$mutations = [];
		foreach ($this->employeesIn(administrationId: $administrationId, period: $period) as $employee) {
			$employeeId = (string)$employee['id'];
			$sent = ($baseline[$employeeId] ?? null);
			$mutations = array_merge(
				$mutations,
				$this->view->differences(employee: $employee, sent: $sent, period: $period),
				$this->items->mutationsFor(employeeId: $employeeId, period: $period, sent: ($sent ?? []))
			);
		}

		return $mutations;
	}//end mutationsOf()

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

}//end class
