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
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
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
	 * The calculation time of the current call, as a Unix timestamp; null
	 * when it could not be read, which leaves every row in scope.
	 *
	 * @var integer|null
	 */
	private ?int $cutoff = null;

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
		$this->cutoff = $this->timestamp($calculatedAt);
		$employeeId   = RegisterRows::idOf($employee);
		$raise        = $this->appliedRaise(employeeId: $employeeId, salaryCents: $salaryCents);
		$source       = ['component' => 'salary', 'amountCents' => $salaryCents];
		if ($raise !== null) {
			return $source + ['sourceSchema' => 'CompAdjustment', 'sourceId' => RegisterRows::idOf($raise), 'sourceKind' => 'raise'];
		}

		return $source + [
			'sourceSchema' => 'Employee',
			'sourceId'     => $employeeId,
			'sourceKind'   => 'record-edit',
			'audit'        => $this->lastChange(uuid: $employeeId, field: 'grossMonthlySalary'),
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
		$this->cutoff = $this->timestamp($calculatedAt);
		if (RegisterRows::idOf($payslip) === '') {
			return [];
		}

		$this->deleteLinesOf(payslipId: RegisterRows::idOf($payslip));

		$written = [];
		foreach ($sources as $source) {
			$line = $this->line(payslip: $payslip, source: $source);
			if ($this->saveLine(line: $line) === true) {
				$written[] = $line;
			}
		}

		return $written;
	}//end replaceLines()

	/**
	 * Save one line; a failure is logged and never fails the run.
	 *
	 * @param array<string, mixed> $line The line.
	 *
	 * @return bool Whether it was saved.
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function saveLine(array $line): bool {
		try {
			$this->objectService()->saveObject(
				object: $line,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->register(), schema: self::SCHEMA)),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning('PayslipLineageService: kon regel ' . $line['component'] . ' van loonstrook ' . $line['payslipId'] . ' niet opslaan: ' . $e->getMessage());
			return false;
		}

		return true;
	}//end saveLine()

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
	 * @param array<string, mixed> $payslip The saved payslip.
	 * @param array<string, mixed> $source  The source.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function line(array $payslip, array $source): array {
		$sourceId = (string)($source['sourceId'] ?? '');
		$audit    = $this->auditOf(source: $source, sourceId: $sourceId);

		return [
			'payslipId'          => RegisterRows::idOf($payslip),
			'payrollRunId'       => self::stringOrNull($payslip['payrollRunId'] ?? null),
			'employeeId'         => (string)($payslip['employeeId'] ?? ''),
			'period'             => self::stringOrNull($payslip['period'] ?? null),
			'component'          => (string)($source['component'] ?? ''),
			'amount'             => round(((int)($source['amountCents'] ?? 0)) / 100, 2),
			'sourceSchema'       => self::stringOrNull($source['sourceSchema'] ?? null),
			'sourceId'           => self::stringOrNull($sourceId === '' ? null : $sourceId),
			'sourceKind'         => self::stringOrNull($source['sourceKind'] ?? null),
			'sourceAuditTrailId' => ($audit['id'] ?? null),
			'sourceChangedBy'    => ($audit['user'] ?? null),
			'sourceChangedAt'    => (($audit['at'] ?? null) !== null ? gmdate('Y-m-d\TH:i:s\Z', (int)$audit['at']) : null),
		];
	}//end line()

	/**
	 * The audit entry of a source: the one already resolved, else the last
	 * change of its object before the calculation.
	 *
	 * @param array<string, mixed> $source   The source.
	 * @param string               $sourceId Its object id, '' when none.
	 *
	 * @return array{id: int|null, user: string|null, at: int|null}|null
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function auditOf(array $source, string $sourceId): ?array {
		if (array_key_exists('audit', $source) === true) {
			return $source['audit'];
		}

		if ($sourceId === '') {
			return null;
		}

		return $this->lastChange(uuid: $sourceId);
	}//end auditOf()

	/**
	 * A value as a string, null kept null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function stringOrNull(mixed $value): ?string {
		return ($value !== null ? (string)$value : null);
	}//end stringOrNull()

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
					deletion: new ObjectDeletion(
						uuid: $lineId,
						target: new ObjectFindTarget(register: $this->register(), schema: self::SCHEMA)
					),
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
	 * @param string $employeeId  The employee.
	 * @param int    $salaryCents The salary read, in cents.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function appliedRaise(string $employeeId, int $salaryCents): ?array {
		if ($employeeId === '') {
			return null;
		}

		$this->raises ??= $this->loadAll('CompAdjustment');
		$best           = null;
		$bestAt         = null;
		foreach ($this->raises as $raise) {
			$appliedAt = $this->raiseAppliedAt(raise: $raise, employeeId: $employeeId);
			if ($appliedAt === null || $this->proposesSalary(raise: $raise, salaryCents: $salaryCents) === false) {
				continue;
			}

			if ($bestAt === null || $appliedAt > $bestAt) {
				$best   = $raise;
				$bestAt = $appliedAt;
			}
		}

		return $best;
	}//end appliedRaise()

	/**
	 * When a raise of this employee was applied, null when it is another
	 * employee's, not applied, or applied after the calculation.
	 *
	 * @param array<string, mixed> $raise      The CompAdjustment.
	 * @param string               $employeeId The employee.
	 *
	 * @return integer|null
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function raiseAppliedAt(array $raise, string $employeeId): ?int {
		$appliedAt = $this->timestamp($raise['appliedAt'] ?? null);
		if ((string)($raise['employeeId'] ?? '') !== $employeeId || $appliedAt === null) {
			return null;
		}

		return ($this->isAfterCutoff(at: $appliedAt) === true ? null : $appliedAt);
	}//end raiseAppliedAt()

	/**
	 * Whether a raise proposes exactly this salary.
	 *
	 * @param array<string, mixed> $raise       The CompAdjustment.
	 * @param int                  $salaryCents The salary read, in cents.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function proposesSalary(array $raise, int $salaryCents): bool {
		if (is_numeric($raise['proposedSalary'] ?? null) === false) {
			return false;
		}

		// CompAdjustment.proposedSalary is stored in cents (CompAdjustmentService).
		return (int)round((float)$raise['proposedSalary']) === $salaryCents;
	}//end proposesSalary()

	/**
	 * Whether a moment lies after the calculation of the current call.
	 *
	 * @param int|null $at The moment, null when unknown.
	 *
	 * @return bool
	 */
	private function isAfterCutoff(?int $at): bool {
		return $this->cutoff !== null && $at !== null && $at > $this->cutoff;
	}//end isAfterCutoff()

	/**
	 * An object's last audit row before the calculation, optionally only rows
	 * that changed one field. Null when there is none or the audit trail
	 * cannot be read.
	 *
	 * @param string      $uuid  The object.
	 * @param string|null $field Only rows that changed this field.
	 *
	 * @return array{id: int|null, user: string|null, at: int|null}|null
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private function lastChange(string $uuid, ?string $field = null): ?array {
		$best = null;
		foreach (($this->auditRows($uuid) ?? []) as $row) {
			if ($this->isAfterCutoff(at: $row['at']) === true || ($field !== null && array_key_exists($field, $row['changed']) === false)) {
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
		$id   = self::entryValue(entry: $entry, getter: 'getId', fallback: ($data['id'] ?? null));
		$user = ($data['userName'] ?? ($data['user'] ?? null));

		return [
			'id'      => (is_numeric($id) === true ? (int)$id : null),
			'user'    => (is_string($user) === true && $user !== '' ? $user : null),
			'at'      => $this->timestamp(self::entryValue(entry: $entry, getter: 'getCreated', fallback: ($data['created'] ?? null))),
			'changed' => (is_array($data['changed'] ?? null) === true ? $data['changed'] : []),
		];
	}//end auditRow()

	/**
	 * A value from an entity getter when the entry has it, else the fallback.
	 *
	 * @param mixed  $entry    The entry.
	 * @param string $getter   The getter.
	 * @param mixed  $fallback The value of the array form.
	 *
	 * @return mixed
	 */
	private static function entryValue(mixed $entry, string $getter, mixed $fallback): mixed {
		if (is_object($entry) === true && method_exists($entry, $getter) === true) {
			return $entry->{$getter}();
		}

		return $fallback;
	}//end entryValue()

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
