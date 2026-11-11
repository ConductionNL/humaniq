<?php

/**
 * Handoff Period Items
 *
 * The period items of a payroll handoff (payroll-external-bureau-handoff
 * D2): approved hours, payroll-route claims, allowances, leave sold or
 * bought, sickness starting or ending, and garnishments. Each travels as its
 * own mutation, keyed by the object it came from, once; a later change to an
 * item that went out travels as the difference.
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

/**
 * Compiles the period items of one employee into handoff mutations.
 *
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
 */
class HandoffPeriodItems {

	/**
	 * Per mutation kind: the schema, the fields a bureau needs, the field
	 * a new item takes effect from, and the field that ends it.
	 *
	 * @var array<string, array{schema: string, fields: list<string>, start: string, end: string}>
	 */
	private const ITEMS = [
		'hours' => ['schema' => 'Timesheet', 'fields' => ['period', 'hours', 'overtimeHours'], 'start' => '', 'end' => ''],
		'claim' => ['schema' => 'Expense', 'fields' => ['title', 'category', 'amount', 'taxFreeAmount', 'taxableAmount', 'expenseDate'], 'start' => 'expenseDate', 'end' => ''],
		'allowance' => ['schema' => 'RecurringAllowance', 'fields' => ['kind', 'amountPerMonth', 'amountPerDay', 'daysPerMonth', 'taxTreatment', 'startDate', 'endDate', 'status'], 'start' => 'startDate', 'end' => 'endDate'],
		'leave-transaction' => ['schema' => 'LeaveTransaction', 'fields' => ['transactionType', 'leaveType', 'year', 'hours', 'hourlyRate'], 'start' => '', 'end' => ''],
		'sickness' => ['schema' => 'SickLeaveCase', 'fields' => ['firstSickDay', 'recoveredDate', 'loondoorbetalingPercentage'], 'start' => 'firstSickDay', 'end' => 'recoveredDate'],
		'garnishment' => ['schema' => 'Loonbeslag', 'fields' => ['creditor', 'dossierRef', 'orderedAmount', 'beslagvrijeVoet', 'status', 'effectiveFrom', 'effectiveTo'], 'start' => 'effectiveFrom', 'end' => 'effectiveTo'],
	];

	/**
	 * The status a never-sent item of each kind needs to be due; a sickness
	 * case is due on its dates alone.
	 *
	 * @var array<string, string>
	 */
	private const DUE_STATUS = [
		'hours' => 'approved',
		'claim' => 'approved',
		'leave-transaction' => 'approved',
		'allowance' => 'active',
		'garnishment' => 'actief',
	];

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface $gateway The register plumbing.
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
	) {

	}//end __construct()

	/**
	 * The item mutations of one employee for a period: every item that is
	 * due in the period and was never sent, and every item that was sent and
	 * changed since.
	 *
	 * @param string               $employeeId The employee.
	 * @param string               $period     The wage period, YYYY-MM.
	 * @param array<string, mixed> $sent       What earlier handoffs sent for the employee.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
	 */
	public function mutationsFor(string $employeeId, string $period, array $sent): array {
		$mutations = [];
		foreach (array_keys(self::ITEMS) as $kind) {
			foreach ($this->gateway->findFiltered(self::ITEMS[$kind]['schema'], ['employeeId' => $employeeId]) as $row) {
				$item = $this->item(kind: $kind, row: $row);
				$item->employeeId = $employeeId;
				$item->period = $period;
				$item->before = (isset($sent[$item->key]) === true ? (array)$sent[$item->key] : null);
				$mutations = array_merge($mutations, $this->mutationsOf(item: $item));
			}
		}

		return $mutations;
	}//end mutationsFor()

	/**
	 * One item of a kind, keyed as the sent state keys it.
	 *
	 * @param string               $kind The mutation kind.
	 * @param array<string, mixed> $row  The item.
	 *
	 * @return HandoffItem
	 */
	private function item(string $kind, array $row): HandoffItem {
		$item = new HandoffItem();
		$item->kind = $kind;
		$item->row = $row;
		$item->key = 'item:' . (string)($row['id'] ?? '');

		return $item;
	}//end item()

	/**
	 * The item's mutation with the employee and the state it sends, or none
	 * when a never-sent item is not due or nothing changed.
	 *
	 * @param HandoffItem $item The item.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function mutationsOf(HandoffItem $item): array {
		if ($item->before === null && $this->isDue(item: $item) === false) {
			return [];
		}

		$mutation = $this->mutationOf(item: $item);
		if ($mutation === null) {
			return [];
		}

		return [array_merge($mutation, ['employeeId' => $item->employeeId, 'sentState' => [$item->key => $this->valuesOf(kind: $item->kind, row: $item->row)]])];
	}//end mutationsOf()

	/**
	 * A comparable value: numbers as floats, empty strings as null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
	 */
	public function normalise(mixed $value): mixed {
		if (is_int($value) === true || (is_string($value) === true && preg_match('/^\d+\.\d+$/', $value) === 1)) {
			return (float)$value;
		}

		return ($value === '' ? null : $value);
	}//end normalise()

	/**
	 * Whether a never-sent item belongs in this period's handoff.
	 *
	 * @param HandoffItem $item The item and the period.
	 *
	 * @return bool
	 */
	private function isDue(HandoffItem $item): bool {
		$row = $item->row;
		$status = (self::DUE_STATUS[$item->kind] ?? null);
		if ($status !== null && (string)($row['status'] ?? '') !== $status) {
			return false;
		}

		return match ($item->kind) {
			'hours' => ((string)($row['period'] ?? '') === $item->period),
			'claim' => (($row['reimbursementRoute'] ?? '') === 'payroll'),
			'leave-transaction' => true,
			'allowance' => $this->runsIn(item: $item, span: ['startDate', 'endDate']),
			'sickness' => $this->runsIn(item: $item, span: ['firstSickDay', 'recoveredDate']),
			default => $this->runsIn(item: $item, span: ['effectiveFrom', 'effectiveTo']),
		};
	}//end isDue()

	/**
	 * Whether the item's date range touches the period; an empty end is open.
	 *
	 * @param HandoffItem               $item The item and the period.
	 * @param array{0: string, 1: string} $span The item's start and end fields.
	 *
	 * @return bool
	 */
	private function runsIn(HandoffItem $item, array $span): bool {
		$from = (string)($item->row[$span[0]] ?? '');
		$until = (string)($item->row[$span[1]] ?? '');
		$first = $item->period . '-01';
		$last = date('Y-m-t', (int)strtotime($first));

		return ($from !== '' && $from <= $last && ($until === '' || $until >= $first));
	}//end runsIn()

	/**
	 * The mutation of one item, or null when nothing changed since it went
	 * out.
	 *
	 * @param HandoffItem $item The item and what was sent for it.
	 *
	 * @return array<string, mixed>|null
	 */
	private function mutationOf(HandoffItem $item): ?array {
		$fields = $this->changedFields(item: $item);
		if ($fields === []) {
			return null;
		}

		$effective = $this->effectiveDate(item: $item, fields: ($item->before === null ? [] : $fields));

		return ['kind' => $item->kind, 'effectiveDate' => $effective, 'fields' => $fields, 'sourceSchema' => self::ITEMS[$item->kind]['schema'], 'sourceId' => (string)($item->row['id'] ?? '')];
	}//end mutationOf()

	/**
	 * The fields whose value differs from what was sent, old and new.
	 *
	 * @param HandoffItem $item The item and what was sent for it.
	 *
	 * @return array<string, array{old: mixed, new: mixed}>
	 */
	private function changedFields(HandoffItem $item): array {
		$fields = [];
		foreach ($this->valuesOf(kind: $item->kind, row: $item->row) as $field => $value) {
			$old = ($item->before[$field] ?? null);
			if ($old !== $value) {
				$fields[$field] = ['old' => $old, 'new' => $value];
			}
		}

		return $fields;
	}//end changedFields()

	/**
	 * From when a mutation applies: the new end of a sent item that ended,
	 * else the item's start, else the first of the period.
	 *
	 * @param HandoffItem                         $item   The item and the period.
	 * @param array<string, array<string, mixed>> $fields The changes of a sent item, or [] for a new one.
	 *
	 * @return string
	 */
	private function effectiveDate(HandoffItem $item, array $fields): string {
		$spec = self::ITEMS[$item->kind];
		$end = ($spec['end'] === '' ? null : ($fields[$spec['end']]['new'] ?? null));
		if ($end !== null) {
			return (string)$end;
		}

		$start = ($spec['start'] === '' ? '' : (string)($item->row[$spec['start']] ?? ''));
		return ($start === '' ? $item->period . '-01' : $start);
	}//end effectiveDate()

	/**
	 * The comparable values of the fields a bureau needs.
	 *
	 * @param string               $kind The mutation kind.
	 * @param array<string, mixed> $row  The item.
	 *
	 * @return array<string, mixed>
	 */
	private function valuesOf(string $kind, array $row): array {
		$values = [];
		foreach (self::ITEMS[$kind]['fields'] as $field) {
			$values[$field] = $this->normalise($row[$field] ?? null);
		}

		return $values;
	}//end valuesOf()

}//end class
