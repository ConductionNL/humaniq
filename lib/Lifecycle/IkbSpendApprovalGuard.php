<?php

/**
 * Humaniq IkbSpendApprovalGuard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The `approve` transition of an IkbSpendRequest (payroll-reservation-payouts
 * D8): separation of duties first, through the shared NoSelfApprovalGuard,
 * then the balance. A request is approved only when the employee's budget of
 * that year holds at least its amount. A budget that cannot be read, or none
 * at all, refuses: approving on a guess would spend money that is not there.
 *
 * @category Lifecycle
 * @package  OCA\Humaniq\Lifecycle
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

namespace OCA\Humaniq\Lifecycle;

use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * No self-approval, and never above the balance.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) GuardResult is OpenRegister's value-object factory.
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-individual-choice-budget-shall-accrue-and-be-spent-by-approved-request-req-rsp-004
 */
class IkbSpendApprovalGuard implements LifecycleGuardInterface {

	/**
	 * Refusal when no budget is found.
	 *
	 * @var string
	 */
	public const NO_BUDGET = 'Er is geen keuzebudget gevonden voor deze medewerker en dit jaar; goedkeuring is geweigerd.';

	/**
	 * @param RecordStoreInterface $records Reads the budget.
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-individual-choice-budget-shall-accrue-and-be-spent-by-approved-request-req-rsp-004
	 */
	public function __construct(
		private readonly RecordStoreInterface $records,
	) {
	}//end __construct()

	/**
	 * Authorise the approval.
	 *
	 * @param array<string, mixed> $object The IkbSpendRequest at its current state.
	 * @param string               $action The transition ('approve').
	 * @param string               $userId The approver.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-individual-choice-budget-shall-accrue-and-be-spent-by-approved-request-req-rsp-004
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$selfApproval = (new NoSelfApprovalGuard())->check($object, $action, $userId);
		if ($selfApproval->isAllowed() === false) {
			return $selfApproval;
		}

		// The request's own userId may not be stamped yet: compare the
		// approver with the employee's account as well, so a missing stamp
		// never lets an employee approve their own spend.
		if ($this->isTheEmployee(employeeId: (string)($object['employeeId'] ?? ''), userId: $userId) === true) {
			return GuardResult::deny('U mag uw eigen besteding van het keuzebudget niet goedkeuren. Een andere medewerker (manager of HR) moet dit doen.');
		}

		$amount = (float)($object['amount'] ?? 0);
		if ($amount <= 0) {
			return GuardResult::deny('Een verzoek zonder bedrag kan niet worden goedgekeurd.');
		}

		$balance = $this->balance(employeeId: (string)($object['employeeId'] ?? ''), year: (int)($object['year'] ?? 0));
		if ($balance === null) {
			return GuardResult::deny(self::NO_BUDGET);
		}

		if ($amount > $balance) {
			return GuardResult::deny(sprintf('Het verzoek (%.2f euro) is hoger dan het keuzebudget (%.2f euro); goedkeuring is geweigerd.', $amount, $balance));
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Whether the approver is the employee the request is for.
	 *
	 * @param string $employeeId The employee.
	 * @param string $userId     The approver.
	 *
	 * @return bool
	 */
	private function isTheEmployee(string $employeeId, string $userId): bool {
		try {
			$employee = $this->records->findObjectData($employeeId, 'Employee');
		} catch (\Throwable) {
			$employee = null;
		}

		$account = trim((string)($employee['nextcloudUserId'] ?? ''));

		return $account !== '' && $account === $userId;
	}//end isTheEmployee()

	/**
	 * The balance of the employee's budget for the year, or null when none is found or readable.
	 *
	 * @param string $employeeId The employee.
	 * @param int    $year       The year.
	 *
	 * @return float|null
	 */
	private function balance(string $employeeId, int $year): ?float {
		if ($employeeId === '' || $year <= 0) {
			return null;
		}

		try {
			$rows = $this->records->findFiltered('IkbBudget', ['employeeId' => $employeeId, 'year' => $year]);
		} catch (\Throwable) {
			return null;
		}

		foreach ($rows as $row) {
			if ((string)($row['employeeId'] ?? '') === $employeeId && (int)($row['year'] ?? 0) === $year) {
				return (float)($row['balance'] ?? 0);
			}
		}

		return null;
	}//end balance()
}//end class
