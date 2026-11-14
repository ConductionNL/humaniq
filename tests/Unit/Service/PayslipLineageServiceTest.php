<?php

/**
 * Unit tests for PayslipLineageService.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Drives the service through a fake ObjectService and a fake audit handler
 * (the two OpenRegister boundaries it reads and writes through).
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-mutation-lineage/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTime;
use OCA\Humaniq\Service\PayslipLineageService;
use OCA\Humaniq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The salary source rule and the line replacement.
 *
 * @spec openspec/changes/payroll-mutation-lineage/specs/payroll-mutation-lineage/spec.md#requirement-the-salary-line-shall-name-the-raise-or-the-edit-that-set-the-salary-req-pml-002
 */
final class PayslipLineageServiceTest extends TestCase {

	/**
	 * The fake ObjectService.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Audit rows the fake audit handler returns, by uuid; null throws.
	 *
	 * @var array<string, array<int, mixed>>|null
	 */
	private ?array $auditRows = [];

	/**
	 * How often the fake audit handler was read.
	 *
	 * @var int
	 */
	private int $auditReads = 0;

	/**
	 * A service over the given rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return PayslipLineageService
	 */
	private function service(array $rowsBySchema = []): PayslipLineageService {
		$this->objects = new class($rowsBySchema) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @var int
			 */
			private int $nextId = 1;

			/**
			 * @var array<int, string>
			 */
			public array $deleted = [];

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Rows.
			 */
			public function __construct(public array $rowsBySchema) {
			}//end __construct()

			/**
			 * @param string $register Unused.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options Unused.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return ($this->rowsBySchema[$this->schema] ?? []);
			}//end findAll()

			/**
			 * @param array<string, mixed> $object        The object.
			 * @param string|null          $register      Unused.
			 * @param string|null          $schema        The schema.
			 * @param string|null          $uuid          Unused.
			 * @param bool                 $_rbac         Unused.
			 * @param bool                 $_multitenancy Unused.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$saved = array_merge($object, ['id' => 'line-new-' . $this->nextId++]);
				$this->rowsBySchema[$schema][] = $saved;
				return $saved;
			}//end saveObject()

			/**
			 * @param string      $uuid          The id.
			 * @param string|null $register      Unused.
			 * @param string|null $schema        The schema.
			 * @param bool        $_rbac         Unused.
			 * @param bool        $_multitenancy Unused.
			 *
			 * @return bool
			 */
			public function deleteObject(string $uuid, ?string $register = null, ?string $schema = null, bool $_rbac = true, bool $_multitenancy = true): bool {
				$this->deleted[] = $uuid;
				$this->rowsBySchema[$schema] = array_values(array_filter(($this->rowsBySchema[$schema] ?? []), static fn (array $row): bool => $row['id'] !== $uuid));
				return true;
			}//end deleteObject()
		};

		$test  = $this;
		$audit = new class($test) {
			/**
			 * @param PayslipLineageServiceTest $test The test holding the rows.
			 */
			public function __construct(private PayslipLineageServiceTest $test) {
			}//end __construct()

			/**
			 * @param string $uuid The object.
			 *
			 * @return array<int, mixed>
			 */
			public function getLogs(string $uuid): array {
				return $this->test->auditFor($uuid);
			}//end getLogs()
		};

		$objects   = $this->objects;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === 'OCA\OpenRegister\Service\ObjectService' ? $objects : $audit)
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		return new PayslipLineageService($container, $settings, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * The fake audit handler's answer.
	 *
	 * @param string $uuid The object.
	 *
	 * @return array<int, mixed>
	 */
	public function auditFor(string $uuid): array {
		$this->auditReads++;
		if ($this->auditRows === null) {
			throw new RuntimeException('audit trail unavailable');
		}

		return ($this->auditRows[$uuid] ?? []);
	}//end auditFor()

	/**
	 * An audit entry shaped like OpenRegister's AuditTrail entity.
	 *
	 * @param int                  $id      The row id.
	 * @param string               $created When.
	 * @param string               $user    Who.
	 * @param array<string, mixed> $changed What changed.
	 *
	 * @return object
	 */
	private static function entry(int $id, string $created, string $user, array $changed): object {
		return new class($id, $created, $user, $changed) {
			/**
			 * @param int                  $id      The row id.
			 * @param string               $created When.
			 * @param string               $user    Who.
			 * @param array<string, mixed> $changed What changed.
			 */
			public function __construct(private int $id, private string $created, private string $user, private array $changed) {
			}//end __construct()

			/**
			 * @return int
			 */
			public function getId(): int {
				return $this->id;
			}//end getId()

			/**
			 * @return DateTime
			 */
			public function getCreated(): DateTime {
				return new DateTime($this->created);
			}//end getCreated()

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => $this->id, 'user' => $this->user, 'changed' => $this->changed];
			}//end jsonSerialize()
		};
	}//end entry()

	/**
	 * A salary set by an applied raise names the raise.
	 *
	 * @return void
	 */
	public function testASalarySetByAnAppliedRaiseNamesTheRaise(): void {
		$service = $this->service(
			[
				'CompAdjustment' => [
					['id' => 'raise-old', 'employeeId' => 'emp-1', 'proposedSalary' => 380000, 'appliedAt' => '2026-01-01T09:00:00Z'],
					['id' => 'raise-jul', 'employeeId' => 'emp-1', 'proposedSalary' => 387600, 'appliedAt' => '2026-07-01T09:00:00Z'],
					['id' => 'raise-other', 'employeeId' => 'emp-2', 'proposedSalary' => 387600, 'appliedAt' => '2026-07-02T09:00:00Z'],
					['id' => 'raise-later', 'employeeId' => 'emp-1', 'proposedSalary' => 387600, 'appliedAt' => '2026-08-01T09:00:00Z'],
				],
			]
		);

		$source = $service->salarySource(employee: ['id' => 'emp-1', 'grossMonthlySalary' => 3876.00], salaryCents: 387600, calculatedAt: '2026-07-25T10:00:00Z');

		self::assertSame(['component' => 'salary', 'amountCents' => 387600, 'sourceSchema' => 'CompAdjustment', 'sourceId' => 'raise-jul', 'sourceKind' => 'raise'], $source);
	}//end testASalarySetByAnAppliedRaiseNamesTheRaise()

	/**
	 * A salary set by a direct edit names the edit: its audit row, who and when.
	 *
	 * @return void
	 */
	public function testASalarySetByADirectEditNamesTheAuditEntry(): void {
		$this->auditRows = [
			'emp-1' => [
				self::entry(10, '2026-06-01T08:00:00Z', 'hr-old', ['grossMonthlySalary' => ['old' => 3700, 'new' => 3800]]),
				self::entry(11, '2026-07-03T14:30:00Z', 'hr-adviser', ['grossMonthlySalary' => ['old' => 3800, 'new' => 3900]]),
				self::entry(12, '2026-07-04T09:00:00Z', 'hr-adviser', ['phone' => ['old' => '1', 'new' => '2']]),
				self::entry(13, '2026-08-01T09:00:00Z', 'hr-late', ['grossMonthlySalary' => ['old' => 3900, 'new' => 4000]]),
			],
		];
		$service = $this->service(['CompAdjustment' => []]);

		$source = $service->salarySource(employee: ['id' => 'emp-1'], salaryCents: 390000, calculatedAt: '2026-07-25T10:00:00Z');
		$lines  = $service->replaceLines(payslip: ['id' => 'slip-1', 'payrollRunId' => 'run-jul', 'employeeId' => 'emp-1', 'period' => '2026-07'], sources: [$source], calculatedAt: '2026-07-25T10:00:00Z');

		self::assertSame(
			[
				[
					'payslipId' => 'slip-1',
					'payrollRunId' => 'run-jul',
					'employeeId' => 'emp-1',
					'period' => '2026-07',
					'component' => 'salary',
					'amount' => 3900.0,
					'sourceSchema' => 'Employee',
					'sourceId' => 'emp-1',
					'sourceKind' => 'record-edit',
					'sourceAuditTrailId' => 11,
					'sourceChangedBy' => 'hr-adviser',
					'sourceChangedAt' => '2026-07-03T14:30:00Z',
				],
			],
			$lines
		);
	}//end testASalarySetByADirectEditNamesTheAuditEntry()

	/**
	 * An unreadable audit trail still names the record, with no audit entry.
	 *
	 * @return void
	 */
	public function testAnUnavailableAuditReadLeavesTheAuditEntryEmpty(): void {
		$this->auditRows = null;
		$service         = $this->service(['CompAdjustment' => []]);

		$source = $service->salarySource(employee: ['id' => 'emp-1'], salaryCents: 380000, calculatedAt: '2026-07-25T10:00:00Z');
		$lines  = $service->replaceLines(
			payslip: ['id' => 'slip-1', 'payrollRunId' => 'run-jul', 'employeeId' => 'emp-1', 'period' => '2026-07'],
			sources: [$source, ['component' => 'retroAdjustment', 'amountCents' => 10000, 'sourceSchema' => 'PayrollAdjustment', 'sourceId' => 'adj-1', 'sourceKind' => 'correction']],
			calculatedAt: '2026-07-25T10:00:00Z'
		);

		self::assertCount(2, $lines);
		self::assertSame('emp-1', $lines[0]['sourceId']);
		self::assertSame('Employee', $lines[0]['sourceSchema']);
		self::assertNull($lines[0]['sourceAuditTrailId']);
		self::assertNull($lines[0]['sourceChangedBy']);
		self::assertSame('adj-1', $lines[1]['sourceId']);
		self::assertSame(100.0, $lines[1]['amount']);
		self::assertNull($lines[1]['sourceAuditTrailId']);
	}//end testAnUnavailableAuditReadLeavesTheAuditEntryEmpty()

	/**
	 * Replacing deletes only this payslip's previous lines, and audit rows are read once per run.
	 *
	 * @return void
	 */
	public function testReplacingDeletesOnlyThisPayslipsPreviousLines(): void {
		$this->auditRows = ['aa-1' => [self::entry(5, '2026-06-01T08:00:00Z', 'fleet', ['assetId' => ['new' => 'car-1']])]];
		$service         = $this->service(
			[
				'PayslipLine' => [
					['id' => 'old-1', 'payslipId' => 'slip-1', 'component' => 'bijtelling'],
					['id' => 'old-2', 'payslipId' => 'slip-1', 'component' => 'salary'],
					['id' => 'keep', 'payslipId' => 'slip-2', 'component' => 'salary'],
				],
			]
		);
		$bijtelling      = ['component' => 'bijtelling', 'amountCents' => 30000, 'sourceSchema' => 'AssetAssignment', 'sourceId' => 'aa-1', 'sourceKind' => 'assignment'];

		$service->replaceLines(payslip: ['id' => 'slip-1', 'employeeId' => 'emp-1'], sources: [$bijtelling], calculatedAt: '2026-07-25T10:00:00Z');
		$service->replaceLines(payslip: ['id' => 'slip-3', 'employeeId' => 'emp-3'], sources: [$bijtelling], calculatedAt: '2026-07-25T10:00:00Z');

		self::assertSame(['old-1', 'old-2'], $this->objects->deleted);
		$byPayslip = $service->linesByPayslip(['slip-1', 'slip-2']);
		self::assertSame(['keep'], array_column($byPayslip['slip-2'], 'id'));
		self::assertSame([5], array_column($byPayslip['slip-1'], 'sourceAuditTrailId'));
		self::assertSame(1, $this->auditReads);

		$service->beginRun();
		$service->replaceLines(payslip: ['id' => 'slip-1', 'employeeId' => 'emp-1'], sources: [$bijtelling], calculatedAt: '2026-07-25T10:00:00Z');
		self::assertSame(2, $this->auditReads);
	}//end testReplacingDeletesOnlyThisPayslipsPreviousLines()
}//end class
