<?php

/**
 * Approved claims and recurring allowances in the payroll run.
 *
 * The run asks four things of this service. Which approved payroll-route
 * claims of an employee it pays: approved on or before the period's last day,
 * with no taxable part, and not held by another run (D1, D2). How every active
 * allowance covering the period splits into a taxed part for the gross and an
 * untaxed part for net (D4, through AllowanceSplitter). After the payslips are
 * saved, a stamp on each paid claim naming the run, and one WKR declaration
 * per untaxed allowance payment, upserted on its source reference (D5). And,
 * when the run is approved, the claims it paid are marked reimbursed (D2).
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
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-002
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Payroll\TaxTables;

/**
 * Selection, splitting and stamping of claims and allowances.
 *
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
 */
class PayrollExpenseFoldService {

	/**
	 * The WKR description per allowance kind.
	 *
	 * @var array<string, string>
	 */
	private const KIND_LABELS = [
		'thuiswerk' => 'Thuiswerkvergoeding',
		'reiskosten' => 'Reiskostenvergoeding',
		'telefoon' => 'Telefoonvergoeding',
	];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway  Reads the rows and writes the stamps.
	 * @param InternalWriteMarker  $marker   Marks the stamps as humaniq's own writes.
	 * @param AllowanceSplitter    $splitter The taxed and untaxed split.
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
		private readonly AllowanceSplitter $splitter = new AllowanceSplitter(),
	) {
	}//end __construct()

	/**
	 * The home-working day norm from the tables, or null when the tables do
	 * not carry it. A placeholder leaf counts as unverified.
	 *
	 * @param TaxTables $tables The run's tables.
	 *
	 * @return array{perDayCents: int, verified: bool}|null
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-002
	 */
	public function normFrom(TaxTables $tables): ?array {
		try {
			$leaf = $tables->resolveLeaf(['wkr', 'thuiswerkNormPerDag'], true);
		} catch (\RuntimeException) {
			return null;
		}

		$provenance = ($leaf['provenance'] ?? null);

		return [
			'perDayCents' => (int)$leaf['value'],
			'verified' => ($provenance !== null && $provenance['verified'] === true && $provenance['placeholder'] === false),
		];
	}//end normFrom()

	/**
	 * Everything the fold reads, once per run.
	 *
	 * @param array{perDayCents: int, verified: bool}|null $norm The home-working norm.
	 *
	 * @return array{norm: array{perDayCents: int, verified: bool}|null, expenses: list<array<string, mixed>>, allowances: list<array<string, mixed>>, arrangementsById: array<string, array<string, mixed>>, wkrByReference: array<string, string>}
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
	 */
	public function inputs(?array $norm): array {
		$arrangementsById = [];
		foreach ($this->rows('CommuteArrangement') as $arrangement) {
			$arrangementsById[self::idOf($arrangement)] = $arrangement;
		}

		$wkrByReference = [];
		foreach ($this->rows('WkrDeclaration') as $row) {
			$reference = (string)($row['sourceReference'] ?? '');
			if ($reference !== '') {
				$wkrByReference[$reference] = self::idOf($row);
			}
		}

		return [
			'norm' => $norm,
			'expenses' => $this->rows('Expense'),
			'allowances' => $this->rows('RecurringAllowance'),
			'arrangementsById' => $arrangementsById,
			'wkrByReference' => $wkrByReference,
		];
	}//end inputs()

	/**
	 * The claims and allowances one employee is paid in one run.
	 *
	 * @param array<string, mixed> $inputs     From inputs().
	 * @param string               $employeeId The employee.
	 * @param string               $period     The run's period, YYYY-MM.
	 * @param string               $runId      The run.
	 *
	 * @return array{claimCents: int, claimIds: list<string>, taxedCents: int, untaxedCents: int, lines: list<array<string, mixed>>, wkr: list<array<string, mixed>>}
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-002
	 */
	public function foldFor(array $inputs, string $employeeId, string $period, string $runId): array {
		$fold = ['claimCents' => 0, 'claimIds' => [], 'taxedCents' => 0, 'untaxedCents' => 0, 'lines' => [], 'wkr' => []];
		$lastDay = self::lastDay($period);
		if ($lastDay === null) {
			return $fold;
		}

		foreach ((array)$inputs['expenses'] as $claim) {
			if ((string)($claim['employeeId'] ?? '') === $employeeId && $this->isPayable(claim: $claim, lastDay: $lastDay, runId: $runId) === true) {
				$fold['claimCents'] += (int)round(((float)$claim['amount']) * 100);
				$fold['claimIds'][] = self::idOf($claim);
			}
		}

		foreach ((array)$inputs['allowances'] as $allowance) {
			if ((string)($allowance['employeeId'] ?? '') !== $employeeId || $this->splitter->covers(allowance: $allowance, period: $period) === false) {
				continue;
			}

			$arrangement = ($inputs['arrangementsById'][(string)($allowance['commuteArrangementId'] ?? '')] ?? null);
			$split = $this->splitter->split(allowance: $allowance, arrangement: $arrangement, norm: $inputs['norm']);
			$fold['taxedCents'] += $split['taxedCents'];
			$fold['untaxedCents'] += $split['untaxedCents'];
			$fold['lines'][] = [
				'allowanceId' => self::idOf($allowance),
				'kind' => (string)($allowance['kind'] ?? ''),
				'status' => $split['status'],
				'taxed' => self::euros($split['taxedCents']),
				'untaxed' => self::euros($split['untaxedCents']),
				'wkrCategory' => $split['wkrCategory'],
			];
			if ($split['untaxedCents'] > 0) {
				$fold['wkr'][] = [
					'sourceReference' => 'allowance:' . self::idOf($allowance) . ':' . $period,
					'amount' => self::euros($split['untaxedCents']),
					'wkrCategory' => $split['wkrCategory'],
					'employeeId' => $employeeId,
					'date' => $lastDay,
					'year' => (int)substr($period, 0, 4),
					'description' => (self::KIND_LABELS[(string)($allowance['kind'] ?? '')] ?? 'Vaste vergoeding') . ' ' . $period,
				];
			}
		}//end foreach

		return $fold;
	}//end foldFor()

	/**
	 * The payslip fields of a fold; none when nothing was folded, so a
	 * payslip without claims or allowances is unchanged.
	 *
	 * @param array<string, mixed> $fold From foldFor().
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
	 */
	public function payslipFields(array $fold): array {
		if ($fold['claimIds'] === [] && $fold['lines'] === []) {
			return [];
		}

		return [
			'reimbursements' => self::euros((int)$fold['claimCents']),
			'reimbursedExpenseIds' => $fold['claimIds'],
			'allowancesTaxed' => self::euros((int)$fold['taxedCents']),
			'allowancesUntaxed' => self::euros((int)$fold['untaxedCents']),
			'allowanceLines' => $fold['lines'],
		];
	}//end payslipFields()

	/**
	 * Stamp every paid claim with the run and period; unstamp a claim this
	 * run held before but no longer pays. An unchanged stamp is not written.
	 *
	 * @param list<array<string, mixed>> $expenses Every Expense.
	 * @param list<string>               $paidIds  The claims this run pays.
	 * @param string                     $runId    The run.
	 * @param string                     $period   The period.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
	 */
	public function stampClaims(array $expenses, array $paidIds, string $runId, string $period): void {
		foreach ($expenses as $claim) {
			$claimId = self::idOf($claim);
			$stamp = ['payrollRunId' => null, 'paidInPeriod' => null];
			if (in_array($claimId, $paidIds, true) === true) {
				$stamp = ['payrollRunId' => $runId, 'paidInPeriod' => $period];
			} else if ((string)($claim['payrollRunId'] ?? '') !== $runId) {
				continue;
			}

			if (($claim['payrollRunId'] ?? null) === $stamp['payrollRunId'] && ($claim['paidInPeriod'] ?? null) === $stamp['paidInPeriod']) {
				continue;
			}

			$this->write(payload: array_merge($claim, $stamp), schema: 'Expense', uuid: $claimId);
		}
	}//end stampClaims()

	/**
	 * Upsert the WKR declarations of the run's allowance payments on their
	 * source reference, so a recalculation rewrites the same row.
	 *
	 * @param array<string, mixed>       $inputs           From inputs().
	 * @param list<array<string, mixed>> $rows             The rows of foldFor().
	 * @param string                     $administrationId The run's administration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-003
	 */
	public function writeWkr(array $inputs, array $rows, string $administrationId): void {
		foreach ($rows as $row) {
			$uuid = ($inputs['wkrByReference'][(string)$row['sourceReference']] ?? null);
			$this->write(payload: array_merge($row, ['administrationId' => $administrationId]), schema: 'WkrDeclaration', uuid: $uuid);
		}
	}//end writeWkr()

	/**
	 * Mark every approved claim a run paid as reimbursed; returns the count.
	 *
	 * @param string $runId The approved run.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
	 */
	public function markReimbursed(string $runId): int {
		if ($runId === '') {
			return 0;
		}

		$marked = 0;
		foreach ($this->rows('Expense') as $claim) {
			if ((string)($claim['payrollRunId'] ?? '') !== $runId || (string)($claim['status'] ?? '') !== 'approved') {
				continue;
			}

			$this->write(payload: array_merge($claim, ['status' => 'reimbursed', 'reimbursedAt' => gmdate('Y-m-d\TH:i:s\Z')]), schema: 'Expense', uuid: self::idOf($claim));
			++$marked;
		}

		return $marked;
	}//end markReimbursed()

	/**
	 * Whether a claim is paid by this run.
	 *
	 * @param array<string, mixed> $claim   The Expense.
	 * @param string               $lastDay The period's last day.
	 * @param string               $runId   The run.
	 *
	 * @return bool
	 */
	private function isPayable(array $claim, string $lastDay, string $runId): bool {
		$heldBy = (string)($claim['payrollRunId'] ?? '');
		$approvedOn = substr((string)($claim['approvedAt'] ?? ''), 0, 10);
		$taxable = ($claim['taxableAmount'] ?? null);

		return (string)($claim['status'] ?? '') === 'approved'
			&& (string)($claim['reimbursementRoute'] ?? '') === 'payroll'
			&& is_numeric($claim['amount'] ?? null) === true && (float)$claim['amount'] > 0.0
			&& (is_numeric($taxable) === false || (float)$taxable <= 0.0)
			&& $approvedOn !== '' && $approvedOn <= $lastDay
			&& ($heldBy === '' || $heldBy === $runId);
	}//end isPayable()

	/**
	 * One internal write, without the id fields.
	 *
	 * @param array<string, mixed> $payload The object.
	 * @param string               $schema  The schema.
	 * @param string|null          $uuid    The object, or null to create.
	 *
	 * @return void
	 */
	private function write(array $payload, string $schema, ?string $uuid): void {
		unset($payload['id'], $payload['@self']);
		$this->marker->runInternal(fn () => $this->gateway->save($payload, $schema, $uuid));
	}//end write()

	/**
	 * Every row of a schema; none when the register does not carry it yet.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $schema): array {
		try {
			return array_values($this->gateway->loadAll($schema));
		} catch (\Throwable) {
			return [];
		}
	}//end rows()

	/**
	 * A period's last day, or null for a malformed period.
	 *
	 * @param string $period The period, YYYY-MM.
	 *
	 * @return string|null
	 */
	private static function lastDay(string $period): ?string {
		if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
			return null;
		}

		return date('Y-m-t', (int)strtotime($period . '-01'));
	}//end lastDay()

	/**
	 * A row's id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
	}//end idOf()

	/**
	 * Cents to euros.
	 *
	 * @param int $cents The amount in cents.
	 *
	 * @return float
	 */
	private static function euros(int $cents): float {
		return round($cents / 100, 2);
	}//end euros()

}//end class
