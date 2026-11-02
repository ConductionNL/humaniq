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
 * The stamp is renewed on every write of an open claim, so a submit or a
 * resubmit after a rejection is routed to today's manager. Approved and
 * reimbursed claims keep the stamp they were decided under, and humaniq's own
 * writes (the payroll run, the restamp repair) pass untouched.
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
	 * Claim states whose stamp records who decided, and is kept.
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
		if ($stamps !== []) {
			$event->setModifiedData(array_merge($event->getModifiedData(), $stamps));
		}
	}//end handle()

	/**
	 * The identity stamps for a claim; [] for a decided claim or a claim
	 * without an employee.
	 *
	 * @param array<string, mixed> $claim The claim as it will be saved.
	 * @param array<string, mixed> $stored The claim as stored, [] on create.
	 *
	 * @return array<string, string|null>
	 */
	private function stampsFor(array $claim, array $stored): array {
		$employeeId = trim((string)($claim['employeeId'] ?? ($stored['employeeId'] ?? '')));
		if ($employeeId === '' || in_array(trim((string)($claim['status'] ?? '')), self::DECIDED_STATES, true) === true) {
			return [];
		}

		$kept = [
			'userId' => ($stored['userId'] ?? null),
			'managerUserId' => ($stored['managerUserId'] ?? null),
			'administrationId' => ($stored['administrationId'] ?? null),
		];

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
