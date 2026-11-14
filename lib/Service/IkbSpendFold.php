<?php

/**
 * IKB Spend Fold
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The individual choice budget's part of a payroll run
 * (payroll-reservation-payouts D8): the approved spend requests settling in
 * the period become payout lines, at the special rate or, for an exempt goal,
 * untaxed with a WKR row; the December remainder, when the administration
 * pays it, and a leaver's balance are paid out too. Extra leave moves no money
 * and is settled on approval of the run.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-individual-choice-budget-shall-accrue-and-be-spent-by-approved-request-req-rsp-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * IKB payout lines of one payslip.
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-individual-choice-budget-shall-accrue-and-be-spent-by-approved-request-req-rsp-004
 */
final class IkbSpendFold {
	/**
	 * Add the IKB: approved requests settling now, the December remainder
	 * when the administration pays it, and the balance of a leaver (D8).
	 *
	 * @param array<string, mixed> $fold    The fold so far.
	 * @param array<string, mixed> $inputs  From inputs().
	 * @param array<string, mixed> $slip    The run's slip.
	 * @param bool                 $leaving Whether the employee leaves this period.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-individual-choice-budget-shall-accrue-and-be-spent-by-approved-request-req-rsp-004
	 */
	public function withIkb(array $fold, array $inputs, array $slip, bool $leaving): array {
		$period = (string)$inputs['period'];
		$year = (int)substr($period, 0, 4);
		$employeeId = (string)$slip['employeeId'];
		[$fold, $spentNow] = $this->requestLines(fold: $fold, inputs: $inputs, employeeId: $employeeId, period: $period);

		$remainderDue = ($leaving === true || ($inputs['schedule']['ikbPayOutRemainder'] === true && substr($period, 5, 2) === '12'));
		$budget = ($inputs['budgets'][$employeeId . '|' . $year] ?? null);
		$remainder = ((int)round(((float)($budget['balance'] ?? 0)) * 100) + $fold['ikbAccruedCents'] - $spentNow);
		if ($remainderDue === true && $remainder > 0) {
			$fold['lines'][] = ['kind' => ReservationPayoutRunService::KIND_IKB, 'reason' => ($leaving === true ? 'leaving' : 'ikb-remainder'), 'basisFrom' => sprintf('%04d-01', $year), 'basisTo' => $period, 'amount' => $remainder, 'specialRate' => true];
		}

		return $fold;
	}//end withIkb()

	/**
	 * The payout lines of the approved requests settling now; leave moves no money.
	 *
	 * @param array<string, mixed> $fold       The fold so far.
	 * @param array<string, mixed> $inputs     The run's inputs.
	 * @param string               $employeeId The employee.
	 * @param string               $period     The period.
	 *
	 * @return array{0: array<string, mixed>, 1: int} The fold and the cents spent.
	 */
	private function requestLines(array $fold, array $inputs, string $employeeId, string $period): array {
		$spentNow = 0;
		foreach (($inputs['requests'][$employeeId] ?? []) as $request) {
			$cents = (int)round(((float)($request['amount'] ?? 0)) * 100);
			$goal = (string)($request['goal'] ?? '');
			if ($cents <= 0 || $goal === 'leave') {
				continue;
			}

			$spentNow += $cents;
			$treatment = ($goal === 'goal' ? (string)($inputs['goals'][(string)($request['ikbGoalId'] ?? '')]['taxTreatment'] ?? 'taxed') : 'taxed');
			$line = ['kind' => ReservationPayoutRunService::KIND_IKB, 'reason' => 'ikb-request', 'basisFrom' => $period, 'basisTo' => $period, 'amount' => $cents, 'ikbSpendRequestId' => (string)($request['id'] ?? ($request['@self']['id'] ?? '')), 'specialRate' => ($treatment !== 'exempt')];
			$fold['lines'][] = $line;
			if ($treatment === 'exempt') {
				$fold['wkr'][] = self::wkrRow(request: $request, goal: ($inputs['goals'][(string)($request['ikbGoalId'] ?? '')] ?? []), cents: $cents, period: $period);
			}
		}


		return [$fold, $spentNow];
	}//end requestLines()

	/**
	 * The WKR row of an exempt goal spend.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param array<string, mixed> $goal    Its goal.
	 * @param int                  $cents   The amount.
	 * @param string               $period  The period.
	 *
	 * @return array<string, mixed>
	 */
	private static function wkrRow(array $request, array $goal, int $cents, string $period): array {
		return [
			'year' => (int)substr($period, 0, 4),
			'date' => $period . '-01',
			'description' => 'Keuzebudget: ' . (string)($goal['name'] ?? 'doel'),
			'amount' => round(($cents / 100), 2),
			'wkrCategory' => (string)($goal['wkrCategory'] ?? 'gericht-vrijgesteld'),
			'employeeId' => (string)($request['employeeId'] ?? ''),
			'sourceReference' => 'ikb:' . (string)($request['id'] ?? ($request['@self']['id'] ?? '')),
		];
	}//end wkrRow()
}//end class
