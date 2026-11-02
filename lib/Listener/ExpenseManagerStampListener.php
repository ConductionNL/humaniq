<?php

/**
 * Humaniq ExpenseManagerStampListener
 *
 * Pre-save listener on OpenRegister's `ObjectCreatingEvent` and
 * `ObjectUpdatingEvent` for `Expense`. An expense claim of any category gets
 * its approver the way a timesheet does: `managerUserId` is resolved by the
 * shared org chain (HoursRegisterGateway::uniqueManagerUserIdFor, which
 * climbs to the parent unit, stays inside the employee's administration and
 * fails closed), for the claim's `expenseDate`, else today. `userId` and
 * `administrationId` are copied from the Employee. Client input to the three
 * is inert.
 *
 * The three are derived again on every write, whatever status the client
 * sends, so a submit or a resubmit after a rejection is routed to today's
 * manager. Two writes keep the stored values instead: the approve write
 * (stored `submitted`, saved `approved`) and any write of a claim stored as
 * approved or reimbursed, so a decided claim keeps the identity it was
 * decided under. On those two writes the claim also keeps its stored
 * `employeeId`, because payroll pays a claim to that employee. Which case
 * applies is read from the STORED status, never from the client's.
 * humaniq's own writes (the payroll run, the restamp repair) pass untouched.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
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
 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 * @spec openspec/changes/restamp-open-manager-records/specs/humaniq-expenses/spec.md#Requirement:-An-expense-claim-of-any-category-SHALL-be-approved-the-way-hours-are
 * @spec openspec/changes/approved-claim-keeps-its-employee/specs/humaniq-expenses/spec.md#Requirement:-An-approved-expense-claim-SHALL-keep-its-employee
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Stamps the approver and the claimant's account on an expense claim.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/restamp-open-manager-records/specs/humaniq-expenses/spec.md#Requirement:-An-expense-claim-of-any-category-SHALL-be-approved-the-way-hours-are
 */
class ExpenseManagerStampListener implements IEventListener {

	/**
	 * Lower-cased slug of the expense schema.
	 *
	 * @var string
	 */
	public const SLUG = 'expense';

	/**
	 * Stored claim states whose identity records who decided, and is kept.
	 *
	 * @var list<string>
	 */
	private const DECIDED_STATES = ['approved', 'reimbursed'];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The employee and the shared manager resolution.
	 * @param InternalWriteMarker $marker Marks humaniq's own writes.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Stamp a claim on its way into the register.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/restamp-open-manager-records/specs/humaniq-expenses/spec.md#Requirement:-An-expense-claim-of-any-category-SHALL-be-approved-the-way-hours-are
	 */
	public function handle(Event $event): void {
		if ($this->marker->isInternal() === true) {
			return;
		}

		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$entity = ($event instanceof ObjectCreatingEvent) ? $event->getObject() : $event->getNewObject();
		if (strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== self::SLUG) {
			return;
		}

		$stored = ($event instanceof ObjectUpdatingEvent) ? ($event->getOldObject()?->getObject() ?? []) : [];
		$stamps = $this->stampsFor(claim: ($entity->getObject() ?? []), stored: $stored);
		$event->setModifiedData(array_merge($event->getModifiedData(), $stamps));
	}//end handle()

	/**
	 * The identity stamps for a claim: derived from the Employee, or the
	 * stored values (with the stored employee) for a decided claim and the
	 * approve write, or the stored values for an Employee
	 * that cannot be read (a claim without an employee has none).
	 *
	 * @param array<string, mixed> $claim The claim as it will be saved.
	 * @param array<string, mixed> $stored The claim as stored, [] on create.
	 *
	 * @return array<string, string|null>
	 *
	 * @spec openspec/changes/approved-claim-keeps-its-employee/specs/humaniq-expenses/spec.md#Requirement:-An-approved-expense-claim-SHALL-keep-its-employee
	 */
	private function stampsFor(array $claim, array $stored): array {
		$kept = [
			'userId' => ($stored['userId'] ?? null),
			'managerUserId' => ($stored['managerUserId'] ?? null),
			'administrationId' => ($stored['administrationId'] ?? null),
		];

		if ($this->keepsStoredIdentity(claim: $claim, stored: $stored) === true) {
			return array_merge($kept, ['employeeId' => ($stored['employeeId'] ?? null)]);
		}

		$employeeId = trim((string)($claim['employeeId'] ?? ($stored['employeeId'] ?? '')));

		try {
			$employee = $this->gateway->findObjectData($employeeId, 'Employee');
			if ($employee === null) {
				return $kept;
			}

			return [
				'userId' => $this->nullableTrim(value: $employee['nextcloudUserId'] ?? null),
				'managerUserId' => $this->gateway->uniqueManagerUserIdFor($employeeId, $this->referenceDate(claim: $claim)),
				'administrationId' => $this->nullableTrim(value: $employee['administrationId'] ?? null),
			];
		} catch (\Throwable $e) {
			$this->logger->info('humaniq: expense approver stamp failed; keeping stored values', ['exception' => $e->getMessage()]);

			return $kept;
		}
	}//end stampsFor()

	/**
	 * Whether the write keeps the stored identity: the claim is stored as
	 * decided, or this is the approve write. Read from the stored status, so
	 * a client cannot choose it.
	 *
	 * @param array<string, mixed> $claim The claim as it will be saved.
	 * @param array<string, mixed> $stored The claim as stored, [] on create.
	 *
	 * @return bool
	 */
	private function keepsStoredIdentity(array $claim, array $stored): bool {
		$from = trim((string)($stored['status'] ?? ''));
		if (in_array($from, self::DECIDED_STATES, true) === true) {
			return true;
		}

		return $from === 'submitted' && trim((string)($claim['status'] ?? '')) === 'approved';
	}//end keepsStoredIdentity()

	/**
	 * The date the approver is resolved for: the claim's `expenseDate`, else
	 * today. Only the `YYYY-MM-DD` part counts.
	 *
	 * @param array<string, mixed> $claim The claim.
	 *
	 * @return string
	 */
	private function referenceDate(array $claim): string {
		$text = trim((string)($claim['expenseDate'] ?? ''));
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $text) === 1) {
			return substr($text, 0, 10);
		}

		return gmdate('Y-m-d');
	}//end referenceDate()

	/**
	 * Trim to a non-empty string or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null
	 */
	private function nullableTrim(mixed $value): ?string {
		$trimmed = trim((string)($value ?? ''));

		return $trimmed === '' ? null : $trimmed;
	}//end nullableTrim()

}//end class
