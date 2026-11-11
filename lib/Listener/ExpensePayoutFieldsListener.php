<?php

/**
 * Humaniq ExpensePayoutFieldsListener
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pre-save listener on OpenRegister's `ObjectCreatingEvent` and
 * `ObjectUpdatingEvent` for `Expense`. Payroll pays an approved claim from
 * its amounts, its approval date and the run that holds it, and an edit that
 * keeps the status `approved` never reaches OpenRegister's lifecycle check.
 * So this listener keeps those fields as they were decided:
 *
 * - a new claim starts as a draft or as submitted, without who approved it
 *   when and without run fields, because OpenRegister runs no lifecycle on a
 *   create; a create in any other status is refused;
 * - a claim stored as approved or reimbursed keeps its amounts, its currency,
 *   who approved it when, and its reimbursement route;
 * - the approve write (stored `submitted`, saved `approved`) keeps the
 *   amounts and currency it was submitted with;
 * - HR's reopen (stored `approved`, saved `submitted`, no payroll run) keeps
 *   the amounts and currency, and clears the approval and the route, so the
 *   claim is approved, and its route chosen, again;
 * - `payrollRunId` and `paidInPeriod` change only through humaniq's own writes
 *   (the payroll run).
 *
 * Which case applies is read from the STORED status. humaniq's own writes
 * pass, and so does the import of the example claims humaniq ships.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approved-claim-keeps-its-amounts/specs/humaniq-expenses/spec.md#Requirement:-An-approved-expense-claim-SHALL-keep-the-amounts-it-was-approved-for
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Keeps the fields payroll pays from as they were decided.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/approved-claim-keeps-its-amounts/specs/humaniq-expenses/spec.md#Requirement:-An-approved-expense-claim-SHALL-keep-the-amounts-it-was-approved-for
 */
class ExpensePayoutFieldsListener implements IEventListener {

	/**
	 * Lower-cased slug of the expense schema.
	 *
	 * @var string
	 */
	public const SLUG = 'expense';

	/**
	 * Fields only humaniq's own writes may change.
	 *
	 * @var list<string>
	 */
	private const RUN_FIELDS = ['payrollRunId', 'paidInPeriod'];

	/**
	 * The amounts a claim is paid from.
	 *
	 * @var list<string>
	 */
	private const AMOUNT_FIELDS = ['amount', 'taxFreeAmount', 'taxableAmount', 'currency'];

	/**
	 * The decision on a claim, and the route it is paid by.
	 *
	 * @var list<string>
	 */
	private const DECISION_FIELDS = ['approvedBy', 'approvedAt', 'reimbursementRoute'];

	/**
	 * Fields a new claim always starts without.
	 *
	 * @var list<string>
	 */
	private const BLANK_ON_CREATE = ['payrollRunId', 'paidInPeriod', 'approvedBy', 'approvedAt'];

	/**
	 * The states a new claim may start in; empty takes the lifecycle's initial state.
	 *
	 * @var list<string>
	 */
	private const CREATE_STATES = ['', 'draft', 'submitted'];

	/**
	 * Stored states of a decided claim.
	 *
	 * @var list<string>
	 */
	private const DECIDED_STATES = ['approved', 'reimbursed'];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Resolves the schema slug.
	 * @param InternalWriteMarker $marker Marks humaniq's own writes.
	 * @param IUserSession $userSession The account making a reopen.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
		private readonly IUserSession $userSession,
	) {

	}//end __construct()

	/**
	 * Keep the payout fields of a claim on its way into the register.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approved-claim-keeps-its-amounts/specs/humaniq-expenses/spec.md#Requirement:-An-approved-expense-claim-SHALL-keep-the-amounts-it-was-approved-for
	 */
	public function handle(Event $event): void {
		if ((new SchemaWriteFilter($this->gateway, $this->marker))->judges($event, self::SLUG) === false) {
			return;
		}

		$entity = SchemaWriteFilter::pendingObject($event);

		if ($event instanceof ObjectCreatingEvent) {
			$this->onCreate(event: $event, claim: ($entity->getObject() ?? []));
			return;
		}

		$this->onUpdate(event: $event, claim: ($entity->getObject() ?? []));
	}//end handle()

	/**
	 * An update: refuse a reopen the guard should have refused, else keep the
	 * payout fields this write may not change.
	 *
	 * @param ObjectUpdatingEvent $event The update.
	 * @param array<string, mixed> $claim The claim as it will be saved.
	 *
	 * @return void
	 */
	private function onUpdate(ObjectUpdatingEvent $event, array $claim): void {
		$stored = ($event->getOldObject()?->getObject() ?? []);
		if ($this->refusesReopen(claim: $claim, stored: $stored) === true) {
			$event->setErrors(['message' => 'Deze declaratie kan niet worden heropend.']);
			$event->stopPropagation();
			return;
		}

		$fields = array_merge(self::RUN_FIELDS, $this->keptFields(claim: $claim, stored: $stored));
		$kept = [];
		foreach ($fields as $field) {
			$kept[$field] = ($stored[$field] ?? null);
		}

		if ($this->isReopen(claim: $claim, stored: $stored) === true) {
			$kept = array_merge($kept, array_fill_keys(self::DECISION_FIELDS, null));
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $kept));
	}//end onUpdate()

	/**
	 * A create: refuse any state but draft or submitted, else blank the
	 * decision and run fields.
	 *
	 * @param ObjectCreatingEvent $event The create.
	 * @param array<string, mixed> $claim The claim as it will be saved.
	 *
	 * @return void
	 */
	private function onCreate(ObjectCreatingEvent $event, array $claim): void {
		if ($this->marker->isShippedImport() === true) {
			return;
		}

		if (in_array(trim((string)($claim['status'] ?? '')), self::CREATE_STATES, true) === false) {
			$event->setErrors(['message' => 'Een nieuwe declaratie begint als concept of als ingediend.']);
			$event->stopPropagation();
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), array_fill_keys(self::BLANK_ON_CREATE, null)));
	}//end onCreate()

	/**
	 * Whether an approved-to-submitted write must be refused, judged on the
	 * stored claim: a payroll run holds it, or the acting account is unknown,
	 * the claimant or the approver. HrReopenGuard decides first; this is the
	 * second check on the same rule.
	 *
	 * @param array<string, mixed> $claim The claim as it will be saved.
	 * @param array<string, mixed> $stored The claim as stored.
	 *
	 * @return bool
	 */
	private function refusesReopen(array $claim, array $stored): bool {
		if (trim((string)($stored['status'] ?? '')) !== 'approved' || trim((string)($claim['status'] ?? '')) !== 'submitted') {
			return false;
		}

		$actor = trim((string)$this->userSession->getUser()?->getUID());

		return trim((string)($stored['payrollRunId'] ?? '')) !== ''
			|| $actor === ''
			|| in_array($actor, [trim((string)($stored['userId'] ?? '')), trim((string)($stored['approvedBy'] ?? ''))], true) === true;
	}//end refusesReopen()

	/**
	 * Whether this write is HR's reopen: an approved claim no payroll run
	 * holds goes back to submitted. The lifecycle's HrReopenGuard decides who
	 * may make it.
	 *
	 * @param array<string, mixed> $claim The claim as it will be saved.
	 * @param array<string, mixed> $stored The claim as stored.
	 *
	 * @return bool
	 */
	private function isReopen(array $claim, array $stored): bool {
		return trim((string)($stored['status'] ?? '')) === 'approved'
			&& trim((string)($claim['status'] ?? '')) === 'submitted'
			&& trim((string)($stored['payrollRunId'] ?? '')) === '';
	}//end isReopen()

	/**
	 * Which amount and decision fields this write keeps from the stored claim.
	 *
	 * @param array<string, mixed> $claim The claim as it will be saved.
	 * @param array<string, mixed> $stored The claim as stored, [] on create.
	 *
	 * @return list<string>
	 */
	private function keptFields(array $claim, array $stored): array {
		$from = trim((string)($stored['status'] ?? ''));
		if ($this->isReopen(claim: $claim, stored: $stored) === true) {
			return self::AMOUNT_FIELDS;
		}

		if (in_array($from, self::DECIDED_STATES, true) === true) {
			return array_merge(self::AMOUNT_FIELDS, self::DECISION_FIELDS);
		}

		if ($from === 'submitted' && trim((string)($claim['status'] ?? '')) === 'approved') {
			return self::AMOUNT_FIELDS;
		}

		return [];
	}//end keptFields()

}//end class
