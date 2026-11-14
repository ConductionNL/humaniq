<?php

/**
 * Payslip Lineage Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Writes, for each calculated payslip, one `PayslipLine` per component with
 * the object that caused the amount and the audit entry of that object's last
 * change before the calculation (payroll-mutation-lineage D1/D3). The payroll
 * run hands over the sources it folded; this service resolves who changed
 * them and when, and replaces the payslip's previous lines.
 *
 * Lineage is a record of what the run already did. A failure to read the
 * audit trail leaves `sourceAuditTrailId` empty, and a failure to write a line
 * is logged: neither fails the payroll run, whose payslip stays the figure of
 * record.
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
 * @spec openspec/changes/payroll-mutation-lineage/specs/payroll-mutation-lineage/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeInterface;
use OCA\Humaniq\Support\ConvertsRowsToArrays;
use OCA\Humaniq\Support\LoadsAllRows;
use OCA\Humaniq\Support\ResolvesObjectService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds and replaces the source-carrying lines of a payslip.
 *
 * @spec openspec/changes/payroll-mutation-lineage/specs/payroll-mutation-lineage/spec.md
 */
final class PayslipLineageService {

	use ConvertsRowsToArrays;
	use LoadsAllRows;
	use ResolvesObjectService;

	/**
	 * The lines' schema.
	 */
	public const SCHEMA = 'PayslipLine';

	/**
	 * The audit handler OpenRegister exposes for one object's audit rows.
	 */
	private const AUDIT_HANDLER = 'OCA\OpenRegister\Service\Object\AuditHandler';

	/**
	 * Page size for the row reads.
	 */
	private const LIMIT = 10000;

	/**
	 * Audit rows already read this run, by object uuid.
	 *
	 * @var array<string, array<int, array{id: int|null, user: string|null, at: int|null, changed: array<string, mixed>}>|null>
	 */
	private array $auditCache = [];

	/**
	 * The CompAdjustments of the register, read once per run.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $raises = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container       Resolves OpenRegister's services.
	 * @param SettingsService    $settingsService The register slug and OpenRegister availability.
	 * @param LoggerInterface    $logger          Logger.
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Forget what the previous run read, so a run sees the current raises
	 * and audit rows (the per-run cache of design.md Risks).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/tasks.md#task-2.3
	 */
	public function beginRun(): void {
		$this->auditCache = [];
		$this->raises     = null;
	}//end beginRun()

	/**
	 * The source of an employee's salary: the latest applied raise whose
	 * proposed salary equals the salary read, else the last edit to the
	 * employee's salary before the calculation (design.md D3).
	 *
	 * @param array<string, mixed> $employee     The Employee the engine read.
	 * @param int                  $salaryCents  The salary the engine read, in cents.
	 * @param string               $calculatedAt The calculation time, ISO 8601.
	 *
	 * @return array<string, mixed> A source: `component`, `amountCents`, `sourceSchema`, `sourceId`, `sourceKind`, and `audit` when already resolved.
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/specs/payroll-mutation-lineage/spec.md#requirement-the-salary-line-shall-name-the-raise-or-the-edit-that-set-the-salary-req-pml-002
	 */
	public function salarySource(array $employee, int $salaryCents, string $calculatedAt): array {
		$employeeId = RegisterRows::idOf($employee);
		$raise      = $this->appliedRaise(employeeId: $employeeId, salaryCents: $salaryCents, calculatedAt: $calculatedAt);
		if ($raise !== null) {
			return [
				'component'    => 'salary',
				'amountCents'  => $salaryCents,
				'sourceSchema' => 'CompAdjustment',
				'sourceId'     => RegisterRows::idOf($raise),
				'sourceKind'   => 'raise',
			];
		}

		return [
			'component'    => 'salary',
			'amountCents'  => $salaryCents,
			'sourceSchema' => 'Employee',
			'sourceId'     => $employeeId,
			'sourceKind'   => 'record-edit',
			'audit'        => $this->lastChange(uuid: $employeeId, calculatedAt: $calculatedAt, field: 'grossMonthlySalary'),
		];
	}//end salarySource()

	/**
	 * Replace a payslip's lines with one line per source.
	 *
	 * The payslip's previous lines are deleted first, so a recalculated draft
	 * holds only its current lines (design.md D1).
	 *
	 * @param array<string, mixed>             $payslip      The saved payslip: its id, `payrollRunId`, `employeeId`, `period`.
	 * @param array<int, array<string, mixed>> $sources      The sources, as `salarySource()` returns them.
	 * @param string                           $calculatedAt The calculation time, ISO 8601.
	 *
	 * @return array<int, array<string, mixed>> The lines written.
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/specs/payroll-mutation-lineage/spec.md#requirement-each-calculated-payslip-shall-carry-one-line-per-component-with-its-source-req-pml-001
	 */
	public function replaceLines(array $payslip, array $sources, string $calculatedAt): array {
		$payslipId = RegisterRows::idOf($payslip);
		if ($payslipId === '') {
			return [];
		}

		$this->deleteLinesOf(payslipId: $payslipId);

		$written = [];
		foreach ($sources as $source) {
			$line = $this->line(payslip: $payslip, payslipId: $payslipId, source: $source, calculatedAt: $calculatedAt);
			try {
				$this->objectService()->saveObject(
					object: $line,
					register: $this->register(),
					schema: self::SCHEMA,
					_rbac: false,
					_multitenancy: false
				);
				$written[] = $line;
			} catch (\Throwable $e) {
				$this->logger->warning('PayslipLineageService: kon regel ' . $line['component'] . ' van loonstrook ' . $payslipId . ' niet opslaan: ' . $e->getMessage());
			}
		}

		return $written;
	}//end replaceLines()

	/**
	 * The lines of the given payslips, keyed by payslip id.
	 *
	 * @param array<int, string> $payslipIds The payslip ids.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/tasks.md#task-2.5
	 */
	public function linesByPayslip(array $payslipIds): array {
		$wanted = array_fill_keys(array_filter($payslipIds, static fn (string $id): bool => $id !== ''), true);
		$out    = [];
		if ($wanted === []) {
			return $out;
		}

		foreach ($this->loadAll(self::SCHEMA) as $line) {
			$payslipId = (string)($line['payslipId'] ?? '');
			if (isset($wanted[$payslipId]) === true) {
				$out[$payslipId][] = $line;
			}
		}

		return $out;
	}//end linesByPayslip()

	/**
	 * One line to write.
	 *
	 * @param array<string, mixed> $payslip      The saved payslip.
	 * @param string               $payslipId    Its id.
	 * @param array<string, mixed> $source       The source.
	 * @param string               $calculatedAt The calculation time.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function line(array $payslip, string $payslipId, array $source, string $calculatedAt): array {
		$sourceId = (string)($source['sourceId'] ?? '');
		$audit    = null;
		if (array_key_exists('audit', $source) === true) {
			$audit = $source['audit'];
		} else if ($sourceId !== '') {
			$audit = $this->lastChange(uuid: $sourceId, calculatedAt: $calculatedAt);
		}

		$changedAt = null;
		if (($audit['at'] ?? null) !== null) {
			$changedAt = gmdate('Y-m-d\TH:i:s\Z', (int)$audit['at']);
		}

		return [
			'payslipId'          => $payslipId,
			'payrollRunId'       => (($payslip['payrollRunId'] ?? null) !== null ? (string)$payslip['payrollRunId'] : null),
			'employeeId'         => (string)($payslip['employeeId'] ?? ''),
			'period'             => (($payslip['period'] ?? null) !== null ? (string)$payslip['period'] : null),
			'component'          => (string)($source['component'] ?? ''),
			'amount'             => round(((int)($source['amountCents'] ?? 0)) / 100, 2),
			'sourceSchema'       => (($source['sourceSchema'] ?? null) !== null ? (string)$source['sourceSchema'] : null),
			'sourceId'           => ($sourceId !== '' ? $sourceId : null),
			'sourceKind'         => (($source['sourceKind'] ?? null) !== null ? (string)$source['sourceKind'] : null),
			'sourceAuditTrailId' => ($audit['id'] ?? null),
			'sourceChangedBy'    => ($audit['user'] ?? null),
			'sourceChangedAt'    => $changedAt,
		];
	}//end line()

	/**
	 * Delete a payslip's previous lines.
	 *
	 * @param string $payslipId The payslip.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function deleteLinesOf(string $payslipId): void {
		foreach (($this->linesByPayslip([$payslipId])[$payslipId] ?? []) as $line) {
			$lineId = RegisterRows::idOf($line);
			if ($lineId === '') {
				continue;
			}

			try {
				$this->objectService()->deleteObject(
					uuid: $lineId,
					register: $this->register(),
					schema: self::SCHEMA,
					_rbac: false,
					_multitenancy: false
				);
			} catch (\Throwable $e) {
				$this->logger->warning('PayslipLineageService: kon oude regel ' . $lineId . ' niet verwijderen: ' . $e->getMessage());
			}
		}
	}//end deleteLinesOf()

	/**
	 * The latest raise of an employee, applied on or before the calculation,
	 * whose proposed salary is the salary read.
	 *
	 * @param string $employeeId   The employee.
	 * @param int    $salaryCents  The salary read, in cents.
	 * @param string $calculatedAt The calculation time.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function appliedRaise(string $employeeId, int $salaryCents, string $calculatedAt): ?array {
		if ($employeeId === '') {
			return null;
		}

		$this->raises ??= $this->loadAll('CompAdjustment');
		$before         = $this->timestamp($calculatedAt);
		$best           = null;
		$bestAt         = null;
		foreach ($this->raises as $raise) {
			$appliedAt = $this->timestamp($raise['appliedAt'] ?? null);
			if ((string)($raise['employeeId'] ?? '') !== $employeeId || $appliedAt === null) {
				continue;
			}

			if (($before !== null && $appliedAt > $before) || is_numeric($raise['proposedSalary'] ?? null) === false) {
				continue;
			}

			// CompAdjustment.proposedSalary is stored in cents (CompAdjustmentService).
			if ((int)round((float)$raise['proposedSalary']) !== $salaryCents) {
				continue;
			}

			if ($bestAt === null || $appliedAt > $bestAt) {
				$best   = $raise;
				$bestAt = $appliedAt;
			}
		}//end foreach

		return $best;
	}//end appliedRaise()

	/**
	 * An object's last audit row before the calculation, optionally only rows
	 * that changed one field. Null when there is none or the audit trail
	 * cannot be read.
	 *
	 * @param string      $uuid         The object.
	 * @param string      $calculatedAt The calculation time.
	 * @param string|null $field        Only rows that changed this field.
	 *
	 * @return array{id: int|null, user: string|null, at: int|null}|null
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function lastChange(string $uuid, string $calculatedAt, ?string $field = null): ?array {
		$rows = $this->auditRows($uuid);
		if ($rows === null) {
			return null;
		}

		$before = $this->timestamp($calculatedAt);
		$best   = null;
		foreach ($rows as $row) {
			if ($before !== null && $row['at'] !== null && $row['at'] > $before) {
				continue;
			}

			if ($field !== null && array_key_exists($field, $row['changed']) === false) {
				continue;
			}

			if ($best === null || $this->isLater(row: $row, than: $best) === true) {
				$best = $row;
			}
		}

		if ($best === null) {
			return null;
		}

		return ['id' => $best['id'], 'user' => $best['user'], 'at' => $best['at']];
	}//end lastChange()

	/**
	 * Whether an audit row is later than another: by time, then by id.
	 *
	 * @param array{id: int|null, at: int|null} $row  The candidate.
	 * @param array{id: int|null, at: int|null} $than The current best.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function isLater(array $row, array $than): bool {
		if ((int)($row['at'] ?? 0) !== (int)($than['at'] ?? 0)) {
			return (int)($row['at'] ?? 0) > (int)($than['at'] ?? 0);
		}

		return (int)($row['id'] ?? 0) > (int)($than['id'] ?? 0);
	}//end isLater()

	/**
	 * One object's audit rows, read once per run. Null when the audit trail
	 * cannot be read.
	 *
	 * @param string $uuid The object.
	 *
	 * @return array<int, array{id: int|null, user: string|null, at: int|null, changed: array<string, mixed>}>|null
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function auditRows(string $uuid): ?array {
		if (array_key_exists($uuid, $this->auditCache) === true) {
			return $this->auditCache[$uuid];
		}

		try {
			$logs = $this->openRegisterService(self::AUDIT_HANDLER)->getLogs($uuid);
			$rows = array_map(fn (mixed $entry): array => $this->auditRow($entry), (is_array($logs) === true ? array_values($logs) : []));
		} catch (\Throwable $e) {
			$this->logger->warning('PayslipLineageService: kon audit-logs voor ' . $uuid . ' niet laden: ' . $e->getMessage());
			$rows = null;
		}

		$this->auditCache[$uuid] = $rows;

		return $rows;
	}//end auditRows()

	/**
	 * One audit row, from an `AuditTrail` entity or its array form.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return array{id: int|null, user: string|null, at: int|null, changed: array<string, mixed>}
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function auditRow(mixed $entry): array {
		$data = $entry;
		if (is_object($entry) === true && method_exists($entry, 'jsonSerialize') === true) {
			$data = $entry->jsonSerialize();
		}

		$data = (is_array($data) === true ? $data : []);

		$id = ($data['id'] ?? null);
		if (is_object($entry) === true && method_exists($entry, 'getId') === true) {
			$id = $entry->getId();
		}

		$created = ($data['created'] ?? null);
		if (is_object($entry) === true && method_exists($entry, 'getCreated') === true) {
			$created = $entry->getCreated();
		}

		$user = ($data['userName'] ?? ($data['user'] ?? null));

		return [
			'id'      => (is_numeric($id) === true ? (int)$id : null),
			'user'    => (is_string($user) === true && $user !== '' ? $user : null),
			'at'      => $this->timestamp($created),
			'changed' => (is_array($data['changed'] ?? null) === true ? $data['changed'] : []),
		];
	}//end auditRow()

	/**
	 * A time as a unix timestamp, or null when it is not one.
	 *
	 * @param mixed $value A DateTimeInterface or a date-time string.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function timestamp(mixed $value): ?int {
		if ($value instanceof DateTimeInterface) {
			return $value->getTimestamp();
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		$time = strtotime($value);

		return ($time === false ? null : $time);
	}//end timestamp()

	/**
	 * @return string The configured humaniq register slug.
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()
}//end class
