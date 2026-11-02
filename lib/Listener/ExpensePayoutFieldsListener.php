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
 * - a claim stored as approved or reimbursed keeps its amounts, its currency
 *   and who approved it when;
 * - the approve write (stored `submitted`, saved `approved`) keeps the
 *   amounts and currency it was submitted with;
 * - `payrollRunId` and `paidInPeriod` change only through humaniq's own writes
 *   (the payroll run); a new claim starts without them.
 *
 * Which case applies is read from the STORED status. humaniq's own writes pass.
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
	 * The decision on a claim.
	 *
	 * @var list<string>
	 */
	private const DECISION_FIELDS = ['approvedBy', 'approvedAt'];

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

		$stored = ($event instanceof ObjectUpdatingEvent) ? ($event->getOldObject()?->getObject() ?? []) : [];
		$fields = array_merge(self::RUN_FIELDS, $this->keptFields(claim: ($entity->getObject() ?? []), stored: $stored));
		$kept = [];
		foreach ($fields as $field) {
			$kept[$field] = ($stored[$field] ?? null);
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $kept));
	}//end handle()

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
		if (in_array($from, self::DECIDED_STATES, true) === true) {
			return array_merge(self::AMOUNT_FIELDS, self::DECISION_FIELDS);
		}

		if ($from === 'submitted' && trim((string)($claim['status'] ?? '')) === 'approved') {
			return self::AMOUNT_FIELDS;
		}

		return [];
	}//end keptFields()

}//end class
