<?php

/**
 * Unit tests for PayrollMutationService.
 *
 * Pins the payroll-mutation-reports service contract (design.md D1-D7): the
 * design.md worked example (A changed / B left / C entered -> the exact
 * run-level deltas), the per-component integer-cents exactness of the
 * subtraction, the first-run-all-entered path, the idempotent
 * (fromRunId, toRunId) upsert, prior-run auto-resolution, and the
 * same-administration guard. Drives the service through a fake ObjectService
 * double (a fake collaborator, not a fake of the service logic under test)
 * since the real OpenRegister ObjectService is a sibling-app dependency not
 * available in this standalone suite — the `PayrollRunServiceTest` fake
 * shape.
 *
 * Verified against HEAD 2026-07-14 (PayrollMutationService class docblock):
 * Payslip's money fields are euro-denominated `number`s (2 decimals) —
 * `PayrollRunService` writes them via `euros()` (cents -> euros), NOT
 * literal integer-cents integers. So this suite's fixtures use
 * euro-denominated Payslip values (the design.md worked example's cents
 * figures, divided by 100 — same relative proportions, same worked-example
 * shape) and assert the resulting deltas in euros; the service itself still
 * does every classification/delta computation in integer cents internally
 * (converted at read time), so the assertions below also pin exactness for
 * amounts a naive float subtraction would corrupt.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Service
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
 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-009
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\PayrollMutationService;
use OCA\Hrmq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PayrollMutationService.
 *
 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-001
 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-002
 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-003
 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-005
 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-006
 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-007
 */
class PayrollMutationServiceTest extends TestCase {

	/**
	 * Build a fake ObjectService double: `findAll()` returns the seeded rows
	 * for the current schema, `saveObject()` records every write (assigning
	 * a generated id when no uuid is given) and reflects it back into the
	 * seeded rows.
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
			 * @var int
			 */
			private int $nextId = 1;

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

				$id = ($uuid ?? ('generated-' . $targetSchema . '-' . $this->nextId++));
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
	 * Build a fully-wired PayrollMutationService plus its fake ObjectService
	 * double (for assertions on what was saved).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return array{0: PayrollMutationService, 1: object}
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

		return [new PayrollMutationService($container, $settings, $logger), $fake];
	}//end service()

	/**
	 * A Payslip fixture row for one employee of one run.
	 *
	 * @param string $runId The PayrollRun id.
	 * @param string $employeeId The employee id.
	 * @param float $grossPay Gross pay (euros).
	 * @param float $nettoPay Net pay (euros).
	 * @param float $loonheffing Loonheffing (euros).
	 * @param float $wnv Werknemersverzekeringen (euros).
	 * @param float $zvw Zvw (euros).
	 *
	 * @return array<string, mixed>
	 */
	private function payslip(string $runId, string $employeeId, float $grossPay, float $nettoPay, float $loonheffing, float $wnv, float $zvw): array {
		return [
			'id' => 'ps-' . $runId . '-' . $employeeId,
			'payrollRunId' => $runId,
			'employeeId' => $employeeId,
			'grossPay' => $grossPay,
			'nettoPay' => $nettoPay,
			'loonheffing' => $loonheffing,
			'werknemersverzekeringen' => $wnv,
			'zvw' => $zvw,
		];

	}//end payslip()

	/**
	 * The design.md worked example, scaled to euros (each cents figure / 100
	 * — same worked-example shape, real Payslip storage unit).
	 *
	 * @return void
	 */
	public function testWorkedExampleRollsUpToExpectedRunLevelDeltas(): void {
		[$service] = $this->service(
			[
				'PayrollRun' => [
					['id' => 'run-feb', 'period' => '2026-02', 'administrationId' => 'ADM-001'],
					['id' => 'run-mar', 'period' => '2026-03', 'administrationId' => 'ADM-001'],
				],
				'Payslip' => [
					// From (2026-02): A + B.
					$this->payslip('run-feb', 'emp-a', 3800.00, 3081.17, 718.83, 419.14, 231.80),
					$this->payslip('run-feb', 'emp-b', 2500.00, 2100.00, 300.00, 250.00, 150.00),
					// To (2026-03): A (raise) + C (B left, C joined).
					$this->payslip('run-mar', 'emp-a', 4000.00, 3220.00, 780.00, 440.00, 240.00),
					$this->payslip('run-mar', 'emp-c', 3000.00, 2400.00, 420.00, 300.00, 180.00),
				],
			]
		);

		$outcome = $service->diff('run-mar', 'run-feb');
		$this->assertSame('ok', $outcome['status']);
		$report = $outcome['report'];

		$this->assertSame('run-feb', $report['fromRunId']);
		$this->assertSame('run-mar', $report['toRunId']);
		$this->assertSame('2026-02', $report['fromPeriod']);
		$this->assertSame('2026-03', $report['toPeriod']);
		$this->assertSame('ADM-001', $report['administrationId']);

		$this->assertSame(1, $report['enteredCount']);
		$this->assertSame(1, $report['leftCount']);
		$this->assertSame(1, $report['changedCount']);
		$this->assertSame(0, $report['unchangedCount']);

		$this->assertSame(700.00, $report['grossDelta']);
		$this->assertSame(438.83, $report['netDelta']);
		$this->assertSame(181.17, $report['loonheffingDelta']);
		$this->assertSame(109.06, $report['employerCostDelta']);
		$this->assertSame(809.06, $report['totalWageCostDelta']);

		$byEmployee = [];
		foreach ($report['lines'] as $line) {
			$byEmployee[$line['employeeId']] = $line;
		}

		$this->assertSame('changed', $byEmployee['emp-a']['classification']);
		$this->assertSame(200.00, $byEmployee['emp-a']['grossPayDelta']);
		$this->assertSame(138.83, $byEmployee['emp-a']['nettoPayDelta']);
		$this->assertSame(61.17, $byEmployee['emp-a']['loonheffingDelta']);
		$this->assertSame(29.06, $byEmployee['emp-a']['employerCostDelta']);

		$this->assertSame('left', $byEmployee['emp-b']['classification']);
		$this->assertSame(-2500.00, $byEmployee['emp-b']['grossPayDelta']);
		$this->assertSame(-2100.00, $byEmployee['emp-b']['nettoPayDelta']);
		$this->assertSame(-300.00, $byEmployee['emp-b']['loonheffingDelta']);
		$this->assertSame(-400.00, $byEmployee['emp-b']['employerCostDelta']);

		$this->assertSame('entered', $byEmployee['emp-c']['classification']);
		$this->assertSame(0.0, $byEmployee['emp-c']['grossPayBefore']);
		$this->assertSame(3000.00, $byEmployee['emp-c']['grossPayDelta']);
		$this->assertSame(2400.00, $byEmployee['emp-c']['nettoPayDelta']);
		$this->assertSame(420.00, $byEmployee['emp-c']['loonheffingDelta']);
		$this->assertSame(480.00, $byEmployee['emp-c']['employerCostDelta']);

	}//end testWorkedExampleRollsUpToExpectedRunLevelDeltas()

	/**
	 * @return void
	 */
	public function testPerComponentDeltaIsExactCentsNotFloatDrift(): void {
		[$service] = $this->service(
			[
				'PayrollRun' => [
					['id' => 'run-1', 'period' => '2026-01', 'administrationId' => 'ADM-001'],
					['id' => 'run-2', 'period' => '2026-02', 'administrationId' => 'ADM-001'],
				],
				'Payslip' => [
					// 10.10 -> 10.20 is exactly +0.10 in cents; a naive float
					// subtraction of these exact literals is also fine in
					// isolation, but chained through many employees and
					// components float error compounds — the service must
					// still land on the exact cents figure.
					$this->payslip('run-1', 'emp-x', 10.10, 10.10, 10.10, 5.05, 5.05),
					$this->payslip('run-2', 'emp-x', 10.20, 10.20, 10.20, 5.10, 5.10),
				],
			]
		);

		$outcome = $service->diff('run-2', 'run-1');
		$report = $outcome['report'];

		$this->assertSame(0.10, $report['grossDelta']);
		$this->assertSame(0.10, $report['netDelta']);
		$this->assertSame(0.10, $report['loonheffingDelta']);
		$this->assertSame(0.10, $report['employerCostDelta']);
		$this->assertSame('changed', $report['lines'][0]['classification']);
		$this->assertSame(0.10, $report['lines'][0]['grossPayDelta']);

	}//end testPerComponentDeltaIsExactCentsNotFloatDrift()

	/**
	 * design.md D5: a first run (no prior period) classifies every employee
	 * `entered` with `before = 0`, and the run-level deltas equal the `to`
	 * run's own totals.
	 *
	 * @return void
	 */
	public function testFirstRunHasEveryEmployeeEnteredWithZeroBaseline(): void {
		[$service] = $this->service(
			[
				'PayrollRun' => [
					['id' => 'run-first', 'period' => '2026-01', 'administrationId' => 'ADM-050'],
				],
				'Payslip' => [
					$this->payslip('run-first', 'emp-a', 3000.00, 2500.00, 400.00, 300.00, 100.00),
					$this->payslip('run-first', 'emp-b', 2000.00, 1700.00, 250.00, 200.00, 80.00),
				],
			]
		);

		// No --from given, and no earlier period for ADM-050 exists -> auto-resolution finds none.
		$outcome = $service->diff('run-first');
		$this->assertSame('ok', $outcome['status']);
		$report = $outcome['report'];

		$this->assertNull($report['fromRunId']);
		$this->assertNull($report['fromPeriod']);
		$this->assertSame(2, $report['enteredCount']);
		$this->assertSame(0, $report['leftCount']);
		$this->assertSame(0, $report['changedCount']);
		$this->assertSame(0, $report['unchangedCount']);

		foreach ($report['lines'] as $line) {
			$this->assertSame('entered', $line['classification']);
			$this->assertSame(0.0, $line['grossPayBefore']);
			$this->assertSame(0.0, $line['nettoPayBefore']);
			$this->assertSame(0.0, $line['loonheffingBefore']);
			$this->assertSame(0.0, $line['employerCostBefore']);
		}

		// Run-level deltas equal the to-run's own totals (5000 gross, 4200 net, 650 loonheffing, 680 employer cost).
		$this->assertSame(5000.00, $report['grossDelta']);
		$this->assertSame(4200.00, $report['netDelta']);
		$this->assertSame(650.00, $report['loonheffingDelta']);
		$this->assertSame(680.00, $report['employerCostDelta']);
		$this->assertSame(5680.00, $report['totalWageCostDelta']);

	}//end testFirstRunHasEveryEmployeeEnteredWithZeroBaseline()

	/**
	 * design.md D6: `--to` alone resolves the closest earlier period of the
	 * SAME administration, skipping a closer period belonging to a different
	 * administration.
	 *
	 * @return void
	 */
	public function testAutoResolvesClosestPriorPeriodOfSameAdministration(): void {
		[$service] = $this->service(
			[
				'PayrollRun' => [
					['id' => 'run-jan', 'period' => '2026-01', 'administrationId' => 'ADM-001'],
					['id' => 'run-feb-other', 'period' => '2026-02', 'administrationId' => 'ADM-099'],
					['id' => 'run-mar', 'period' => '2026-03', 'administrationId' => 'ADM-001'],
				],
				'Payslip' => [
					$this->payslip('run-jan', 'emp-a', 3000.00, 2500.00, 400.00, 300.00, 100.00),
					$this->payslip('run-mar', 'emp-a', 3000.00, 2500.00, 400.00, 300.00, 100.00),
				],
			]
		);

		$outcome = $service->diff('run-mar');
		$this->assertSame('ok', $outcome['status']);
		$this->assertSame('run-jan', $outcome['report']['fromRunId'], 'The closer 2026-02 run of a DIFFERENT administration must be skipped.');
		$this->assertSame('unchanged', $outcome['report']['lines'][0]['classification']);

	}//end testAutoResolvesClosestPriorPeriodOfSameAdministration()

	/**
	 * design.md D4: a cross-administration pair is refused, not silently
	 * diffed.
	 *
	 * @return void
	 */
	public function testCrossAdministrationPairIsRefused(): void {
		[$service] = $this->service(
			[
				'PayrollRun' => [
					['id' => 'run-1', 'period' => '2026-01', 'administrationId' => 'ADM-001'],
					['id' => 'run-2', 'period' => '2026-01', 'administrationId' => 'ADM-099'],
				],
				'Payslip' => [],
			]
		);

		$outcome = $service->diff('run-2', 'run-1');
		$this->assertSame('failed', $outcome['status']);
		$this->assertNull($outcome['report']);
		$this->assertNotSame('', $outcome['message']);

	}//end testCrossAdministrationPairIsRefused()

	/**
	 * design.md D7: regenerating the same (fromRunId, toRunId) pair upserts
	 * the existing PayrollMutationReport in place — never a duplicate.
	 *
	 * @return void
	 */
	public function testPersistIsIdempotentPerRunPair(): void {
		[$service, $fake] = $this->service(
			[
				'PayrollRun' => [
					['id' => 'run-1', 'period' => '2026-01', 'administrationId' => 'ADM-001'],
					['id' => 'run-2', 'period' => '2026-02', 'administrationId' => 'ADM-001'],
				],
				'Payslip' => [
					$this->payslip('run-1', 'emp-a', 3000.00, 2500.00, 400.00, 300.00, 100.00),
					$this->payslip('run-2', 'emp-a', 3100.00, 2600.00, 420.00, 310.00, 110.00),
				],
				'PayrollMutationReport' => [],
			]
		);

		$outcome1 = $service->diff('run-2', 'run-1');
		$persist1 = $service->persist($outcome1['report']);
		$this->assertSame('ok', $persist1['status']);
		$firstId = $persist1['reportId'];

		$outcome2 = $service->diff('run-2', 'run-1');
		$persist2 = $service->persist($outcome2['report']);
		$this->assertSame('ok', $persist2['status']);

		$this->assertSame($firstId, $persist2['reportId'], 'Regenerating the same pair must update the SAME report id.');

		$reports = ($fake->rowsBySchema['PayrollMutationReport'] ?? []);
		$this->assertCount(1, $reports, 'Regenerating the same pair must never create a second PayrollMutationReport.');
		$this->assertSame('run-1', $reports[0]['fromRunId']);
		$this->assertSame('run-2', $reports[0]['toRunId']);

	}//end testPersistIsIdempotentPerRunPair()

}//end class
