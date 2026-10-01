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
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The register plumbing.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
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
		foreach (self::ITEMS as $kind => $item) {
			foreach ($this->gateway->findFiltered($item['schema'], ['employeeId' => $employeeId]) as $row) {
				$key = 'item:' . (string)($row['id'] ?? '');
				$before = (isset($sent[$key]) === true ? (array)$sent[$key] : null);
				if ($before === null && $this->isDue(kind: $kind, row: $row, period: $period) === false) {
					continue;
				}

				$mutation = $this->mutationOf(kind: $kind, row: $row, before: $before, period: $period);
				if ($mutation !== null) {
					$mutations[] = array_merge($mutation, ['employeeId' => $employeeId, 'sentState' => [$key => $this->valuesOf(kind: $kind, row: $row)]]);
				}
			}
		}

		return $mutations;
	}//end mutationsFor()

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
	 * @param string               $kind   The mutation kind.
	 * @param array<string, mixed> $row    The item.
	 * @param string               $period The period.
	 *
	 * @return bool
	 */
	private function isDue(string $kind, array $row, string $period): bool {
		$first = $period . '-01';
		$last = date('Y-m-t', (int)strtotime($first));
		$status = (string)($row['status'] ?? '');

		return match ($kind) {
			'hours' => ($status === 'approved' && (string)($row['period'] ?? '') === $period),
			'claim' => ($status === 'approved' && ($row['reimbursementRoute'] ?? '') === 'payroll'),
			'leave-transaction' => ($status === 'approved'),
			'allowance' => ($status === 'active' && $this->runsIn(from: (string)($row['startDate'] ?? ''), until: (string)($row['endDate'] ?? ''), first: $first, last: $last) === true),
			'sickness' => $this->runsIn(from: (string)($row['firstSickDay'] ?? ''), until: (string)($row['recoveredDate'] ?? ''), first: $first, last: $last),
			default => ($status === 'actief' && $this->runsIn(from: (string)($row['effectiveFrom'] ?? ''), until: (string)($row['effectiveTo'] ?? ''), first: $first, last: $last) === true),
		};
	}//end isDue()

	/**
	 * Whether a date range touches the period; an empty end is open.
	 *
	 * @param string $from  The start.
	 * @param string $until The end, or ''.
	 * @param string $first The period's first day.
	 * @param string $last  The period's last day.
	 *
	 * @return bool
	 */
	private function runsIn(string $from, string $until, string $first, string $last): bool {
		return ($from !== '' && $from <= $last && ($until === '' || $until >= $first));
	}//end runsIn()

	/**
	 * The mutation of one item, or null when nothing changed since it went
	 * out.
	 *
	 * @param string                    $kind   The mutation kind.
	 * @param array<string, mixed>      $row    The item.
	 * @param array<string, mixed>|null $before What was sent, or null.
	 * @param string                    $period The period.
	 *
	 * @return array<string, mixed>|null
	 */
	private function mutationOf(string $kind, array $row, ?array $before, string $period): ?array {
		$fields = [];
		foreach ($this->valuesOf(kind: $kind, row: $row) as $field => $value) {
			$old = ($before[$field] ?? null);
			if ($old !== $value) {
				$fields[$field] = ['old' => $old, 'new' => $value];
			}
		}

		if ($fields === []) {
			return null;
		}

		$effective = $this->effectiveDate(kind: $kind, row: $row, fields: ($before === null ? [] : $fields), period: $period);

		return ['kind' => $kind, 'effectiveDate' => $effective, 'fields' => $fields, 'sourceSchema' => self::ITEMS[$kind]['schema'], 'sourceId' => (string)($row['id'] ?? '')];
	}//end mutationOf()

	/**
	 * From when a mutation applies: the new end of a sent item that ended,
	 * else the item's start, else the first of the period.
	 *
	 * @param string                              $kind   The mutation kind.
	 * @param array<string, mixed>                $row    The item.
	 * @param array<string, array<string, mixed>> $fields The changes of a sent item, or [] for a new one.
	 * @param string                              $period The period.
	 *
	 * @return string
	 */
	private function effectiveDate(string $kind, array $row, array $fields, string $period): string {
		$item = self::ITEMS[$kind];
		$end = ($item['end'] === '' ? null : ($fields[$item['end']]['new'] ?? null));
		if ($end !== null) {
			return (string)$end;
		}

		$start = ($item['start'] === '' ? '' : (string)($row[$item['start']] ?? ''));
		return ($start === '' ? $period . '-01' : $start);
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
