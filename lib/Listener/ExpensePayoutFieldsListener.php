<?php

/**
 * Humaniq ExpensePayoutFieldsListener
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
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

		if ($event instanceof ObjectCreatingEvent) {
			$this->onCreate(event: $event, claim: ($entity->getObject() ?? []));
			return;
		}

		$stored = ($event->getOldObject()?->getObject() ?? []);
		$claim = ($entity->getObject() ?? []);
		$fields = array_merge(self::RUN_FIELDS, $this->keptFields(claim: $claim, stored: $stored));
		$kept = [];
		foreach ($fields as $field) {
			$kept[$field] = ($stored[$field] ?? null);
		}

		if ($this->isReopen(claim: $claim, stored: $stored) === true) {
			$kept = array_merge($kept, array_fill_keys(self::DECISION_FIELDS, null));
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $kept));
	}//end handle()

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
