<?php

/**
 * Unit tests for CompAdjustmentService.
 *
 * Pins the comp-cycles effective-dating write contract (design.md D5): an
 * approved, due, within-band adjustment writes the converted
 * Employee.grossMonthlySalary (cents -> euros) and drives the adjustment to
 * effective; non-approved, not-yet-due, and out-of-band adjustments are
 * refused and write nothing; an already-effective adjustment is an
 * idempotent no-op; dry-run evaluates without writing. Drives the service
 * through a fake ObjectService double (a fake collaborator, not a fake of
 * the service logic under test) since the real OpenRegister ObjectService is
 * a sibling-app dependency not available in this standalone suite.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CompAdjustmentService;
use OCA\Humaniq\Service\CompBandValidator;
use OCA\Humaniq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CompAdjustmentService.
 *
 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
 */
class CompAdjustmentServiceTest extends TestCase {

	/**
	 * Build a fake ObjectService double supporting the subset
	 * CompAdjustmentService uses: `find()` by id/schema, `setSchema()` +
	 * `findAll()`, and `saveObject()` (recording every write and reflecting
	 * it back into the seeded rows).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return object The fake ObjectService.
	 */
	private function fakeObjectService(array $rowsBySchema = []): object {
		return new class($rowsBySchema) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Every saveObject() call, as `['schema' => ..., 'object' => ...]`.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
			 */
			public function __construct(
				public array $rowsBySchema,
			) {

			}//end __construct()

			/**
			 * @param string $register Register slug (unused by the fake).
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema name.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options Query options (unused by the fake).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()

			/**
			 * @param string $id Object id.
			 * @param string $register Register slug (unused by the fake).
			 * @param string $schema Schema name.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, string $register, string $schema): ?array {
				foreach (($this->rowsBySchema[$schema] ?? []) as $row) {
					if ((string)($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				return null;
			}//end find()

			/**
			 * @param array<string, mixed> $object The object to save.
			 * @param string|null $register Register slug (unused by the fake).
			 * @param string|null $schema Schema name.
			 * @param string|null $uuid Existing id when updating.
			 * @param bool $_rbac Unused by the fake.
			 * @param bool $_multitenancy Unused by the fake.
			 *
			 * @return array<string, mixed> The saved object (with its id).
			 */
			public function saveObject(
				array $object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$targetSchema = ($schema ?? $this->schema);
				$id = ($uuid ?? (string)($object['id'] ?? ''));
				$saved = array_merge($object, ['id' => $id]);

				$this->saved[] = ['schema' => $targetSchema, 'object' => $saved];

				$rows = ($this->rowsBySchema[$targetSchema] ?? []);
				$replaced = false;
				foreach ($rows as $i => $row) {
					if ((string)($row['id'] ?? '') === $id) {
						$rows[$i] = $saved;
						$replaced = true;
						break;
					}
				}

				if ($replaced === false) {
					$rows[] = $saved;
				}

				$this->rowsBySchema[$targetSchema] = $rows;

				return $saved;
			}//end saveObject()

		};

	}//end fakeObjectService()

	/**
	 * Build a fully-wired CompAdjustmentService plus its fake ObjectService
	 * double (for assertions on what was saved).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return array{0: CompAdjustmentService, 1: object}
	 */
	private function service(array $rowsBySchema = []): array {
		$fake = $this->fakeObjectService($rowsBySchema);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);

		return [new CompAdjustmentService($container, $settings, $logger, new CompBandValidator()), $fake];
	}//end service()

	/**
	 * The standard fixture: one employee at 3300.00 euro, one band
	 * [300000, 420000] cents, one approved+due adjustment proposing 360000
	 * cents.
	 *
	 * @param array<string, mixed> $adjustmentOverrides Fields to override on the CompAdjustment.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function fixture(array $adjustmentOverrides = []): array {
		$adjustment = array_merge(
			[
				'id' => 'adj-1',
				'cycleId' => 'cycle-1',
				'employeeId' => 'emp-1',
				'contractId' => 'contract-1',
				'currentSalary' => 330000,
				'proposedSalary' => 360000,
				'targetBandId' => 'band-a',
				'effectiveDate' => gmdate('Y-m-d'),
				'status' => 'approved',
				'proposedBy' => 'alice',
				'approvedBy' => 'bob',
				'rationale' => 'Jaarlijkse aanpassing',
				'appliedAt' => null,
			],
			$adjustmentOverrides
		);

		return [
			'Employee' => [
				['id' => 'emp-1', 'grossMonthlySalary' => 3300.00],
			],
			'SalaryBand' => [
				['id' => 'band-a', 'minSalary' => 300000, 'maxSalary' => 420000],
			],
			'CompAdjustment' => [$adjustment],
		];

	}//end fixture()

	/**
	 * An approved, due, within-band adjustment writes the converted
	 * grossMonthlySalary (cents -> euros) onto the Employee, stamps
	 * appliedAt, and becomes effective.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
	 */
	public function testApprovedDueWithinBandWritesSalaryAndBecomesEffective(): void {
		[$service, $fake] = $this->service($this->fixture());

		$result = $service->effectuateOne('adj-1');

		$this->assertSame('applied', $result['status']);
		$this->assertSame('emp-1', $result['employeeId']);
		$this->assertSame(3600.00, $result['newGrossMonthlySalary']);

		$employeeSaves = array_values(array_filter($fake->saved, static fn (array $s): bool => $s['schema'] === 'Employee'));
		$this->assertCount(1, $employeeSaves);
		$this->assertSame(3600.00, $employeeSaves[0]['object']['grossMonthlySalary']);

		$adjustmentSaves = array_values(array_filter($fake->saved, static fn (array $s): bool => $s['schema'] === 'CompAdjustment'));
		$this->assertCount(1, $adjustmentSaves);
		$this->assertSame('effective', $adjustmentSaves[0]['object']['status']);
		$this->assertNotNull($adjustmentSaves[0]['object']['appliedAt']);

	}//end testApprovedDueWithinBandWritesSalaryAndBecomesEffective()

	/**
	 * A non-approved adjustment (e.g. still `proposed`) is refused and
	 * writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
	 */
	public function testNonApprovedAdjustmentRefusedWritesNothing(): void {
		[$service, $fake] = $this->service($this->fixture(['status' => 'proposed']));

		$result = $service->effectuateOne('adj-1');

		$this->assertSame('refused-not-approved', $result['status']);
		$this->assertSame([], $fake->saved);

	}//end testNonApprovedAdjustmentRefusedWritesNothing()

	/**
	 * A future-dated (not-yet-due) approved adjustment is refused and writes
	 * nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
	 */
	public function testNotYetDueAdjustmentRefusedWritesNothing(): void {
		$tomorrow = gmdate('Y-m-d', (strtotime('today') + 86400));
		[$service, $fake] = $this->service($this->fixture(['effectiveDate' => $tomorrow]));

		$result = $service->effectuateOne('adj-1');

		$this->assertSame('refused-not-due', $result['status']);
		$this->assertSame([], $fake->saved);

	}//end testNotYetDueAdjustmentRefusedWritesNothing()

	/**
	 * A proposedSalary outside the target band's range is refused and writes
	 * nothing (REQ-COMP-007, enforced belt-and-braces inside the service
	 * itself, not only the corpus CheckProvider).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
	 */
	public function testOutOfBandAdjustmentRefusedWritesNothing(): void {
		[$service, $fake] = $this->service($this->fixture(['proposedSalary' => 900000]));

		$result = $service->effectuateOne('adj-1');

		$this->assertSame('refused-out-of-band', $result['status']);
		$this->assertSame([], $fake->saved);

	}//end testOutOfBandAdjustmentRefusedWritesNothing()

	/**
	 * A band-less adjustment (targetBandId null) is not checked against any
	 * band and still applies (the vacuous precedent).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
	 */
	public function testBandLessAdjustmentApplies(): void {
		[$service] = $this->service($this->fixture(['targetBandId' => null, 'proposedSalary' => 999999]));

		$result = $service->effectuateOne('adj-1');

		$this->assertSame('applied', $result['status']);

	}//end testBandLessAdjustmentApplies()

	/**
	 * Re-running effectuation on an already-effective adjustment is an
	 * idempotent no-op: the outcome reports a skip and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
	 */
	public function testAlreadyEffectiveAdjustmentIsIdempotentNoOp(): void {
		[$service, $fake] = $this->service($this->fixture(['status' => 'effective']));

		$result = $service->effectuateOne('adj-1');

		$this->assertSame('already-effective', $result['status']);
		$this->assertSame([], $fake->saved);

	}//end testAlreadyEffectiveAdjustmentIsIdempotentNoOp()

	/**
	 * Dry-run evaluates the same predicate but writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
	 */
	public function testDryRunEvaluatesWithoutWriting(): void {
		[$service, $fake] = $this->service($this->fixture());

		$outcomes = $service->effectuateCycle('cycle-1', null, true);

		$this->assertCount(1, $outcomes);
		$this->assertSame('would-apply', $outcomes[0]['status']);
		$this->assertSame([], $fake->saved);

	}//end testDryRunEvaluatesWithoutWriting()

	/**
	 * effectuateCycle() batches every adjustment in the cycle, applying the
	 * due one and refusing the not-yet-due one, one outcome per adjustment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
	 */
	public function testEffectuateCycleBatchesEveryAdjustmentInTheCycle(): void {
		$tomorrow = gmdate('Y-m-d', (strtotime('today') + 86400));

		$rows = $this->fixture();
		$rows['CompAdjustment'][] = [
			'id' => 'adj-2',
			'cycleId' => 'cycle-1',
			'employeeId' => 'emp-1',
			'contractId' => 'contract-1',
			'proposedSalary' => 400000,
			'targetBandId' => 'band-a',
			'effectiveDate' => $tomorrow,
			'status' => 'approved',
			'proposedBy' => 'alice',
		];

		[$service] = $this->service($rows);

		$outcomes = $service->effectuateCycle('cycle-1');

		$this->assertCount(2, $outcomes);
		$byId = [];
		foreach ($outcomes as $outcome) {
			$byId[$outcome['adjustmentId']] = $outcome['status'];
		}

		$this->assertSame('applied', $byId['adj-1']);
		$this->assertSame('refused-not-due', $byId['adj-2']);

	}//end testEffectuateCycleBatchesEveryAdjustmentInTheCycle()

}//end class
