<?php

/**
 * Unit tests for RetroAdjustmentService.
 *
 * Pins the retro-adjustments service contract (design.md D1-D5): a
 * PayrollAdjustment is a DELTA object -- the sealed original Payslip and its
 * PayrollRun are READ ONLY, never passed to saveObject()/deleteObject()
 * (REQ-RETRO-001); the recompute always uses the ORIGINAL period's tax year,
 * and a missing historical table refuses rather than recomputes against the
 * wrong year (REQ-RETRO-002); the correction is idempotent by (originalPeriod,
 * employeeId, correctionRef) -- re-running updates the SAME object in place
 * (REQ-RETRO-003); a still-draft original refuses (REQ-RETRO-005). Drives the
 * service through the SAME fake ObjectService double as PayrollRunServiceTest
 * (a fake collaborator, not a fake of the logic under test) since the real
 * OpenRegister ObjectService is a sibling-app dependency not available in this
 * standalone suite; the delta figures asserted here are the design.md D2
 * hand-computed anchor figures diffed against a deliberately-wrong stored
 * payslip.
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
 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-001
 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-002
 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-003
 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Payroll\PayrollCalculator;
use OCA\Hrmq\Service\RetroAdjustmentService;
use OCA\Hrmq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for RetroAdjustmentService.
 *
 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-001
 */
class RetroAdjustmentServiceTest extends TestCase {

	/**
	 * Build a fake ObjectService double: `findAll()` returns the seeded rows
	 * for the current schema, `saveObject()` records every write (assigning a
	 * generated id when no uuid is given) and reflects it back into the seeded
	 * rows, `deleteObject()` records + removes the row.
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
			 * Every deleteObject() uuid, in delete order.
			 *
			 * @var array<int, string>
			 */
			public array $deleted = [];

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

			/**
			 * @param string $uuid The object id to delete.
			 * @param string|null $register Register slug (unused by the fake).
			 * @param string|null $schema Schema name.
			 * @param bool $_rbac Unused by the fake.
			 * @param bool $_multitenancy Unused by the fake.
			 *
			 * @return bool
			 */
			public function deleteObject(
				string $uuid,
				?string $register = null,
				?string $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): bool {
				$this->deleted[] = $uuid;
				return true;
			}//end deleteObject()

		};

	}//end fakeObjectService()

	/**
	 * Build a fully-wired RetroAdjustmentService plus its fake ObjectService
	 * double (for assertions on what was saved/deleted).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return array{0: RetroAdjustmentService, 1: object}
	 */
	private function service(array $rowsBySchema = []): array {
		$fake = $this->fakeObjectService($rowsBySchema);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		$settings->method('getPayrollAofTariff')->willReturn('laag');
		$settings->method('getPayrollWhkPercentage')->willReturnArgument(0);

		$logger = $this->createMock(LoggerInterface::class);

		return [new RetroAdjustmentService($container, $settings, new PayrollCalculator(), $logger), $fake];
	}//end service()

	/**
	 * The anchor-case Employee fixture (design.md D2: wit, korting, below AOW),
	 * overridable per test. The stored gross was WRONG (3500); the correction
	 * recomputes at the correct 3800.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function employee(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'emp-1',
				'employeeNumber' => 'EMP-NL-0001',
				'firstName' => 'Sanne',
				'lastName' => 'de Vries',
				'dateOfBirth' => '1990-04-12',
				'startDate' => '2022-01-01',
				'endDate' => null,
				'grossMonthlySalary' => 3800.00,
				'taxTableColor' => 'wit',
				'loonheffingskortingToegepast' => true,
				'bsn' => '123456782',
				'identityDocumentVerified' => true,
				'nextcloudUserId' => 'sanne',
			],
			$overrides
		);

	}//end employee()

	/**
	 * The covering-contract fixture for the anchor employee.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function contract(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'ct-1',
				'employeeId' => 'emp-1',
				'type' => 'permanent',
				'writtenContract' => true,
				'startDate' => '2022-01-01',
				'endDate' => null,
				'hoursPerWeek' => 36.0,
				'awfTariff' => 'low',
			],
			$overrides
		);

	}//end contract()

	/**
	 * The sealed original Payslip fixture: an APPROVED run for 2026-02 whose
	 * stored figures are the WRONG-gross (3500) result -- the correction
	 * recomputes at the corrected 3800 gross and stores the difference.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function storedPayslip(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'ps-orig',
				'payrollRunId' => 'run-approved',
				'employeeId' => 'emp-1',
				'period' => '2026-02',
				'grossPay' => 3500.00,
				'loonheffing' => 620.00,
				'nettoPay' => 2880.00,
				'werknemersverzekeringen' => 386.00,
				'zvw' => 213.50,
				'volksverzekeringen' => 400.00,
				'vakantiegeldReserved' => 280.00,
			],
			$overrides
		);

	}//end storedPayslip()

	/**
	 * A seed row set with a sealed (approved) original run + payslip.
	 *
	 * @param string $runStatus The original PayrollRun status (approved by default).
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function sealedRows(string $runStatus = 'approved'): array {
		return [
			'Employee' => [$this->employee()],
			'EmploymentContract' => [$this->contract()],
			'PayrollRun' => [
				['id' => 'run-approved', 'period' => '2026-02', 'administrationId' => 'ADM-001', 'jurisdiction' => 'NL', 'status' => $runStatus, 'engineVersion' => 'nl-2026'],
				['id' => 'run-draft-apr', 'period' => '2026-04', 'administrationId' => 'ADM-001', 'jurisdiction' => 'NL', 'status' => 'draft'],
			],
			'Payslip' => [$this->storedPayslip()],
			'PayrollAdjustment' => [],
		];

	}//end sealedRows()

	/**
	 * Objects saved to a given schema, in save order.
	 *
	 * @param object $fake The fake ObjectService.
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedFor(object $fake, string $schema): array {
		$out = [];
		foreach ($fake->saved as $entry) {
			if ($entry['schema'] === $schema) {
				$out[] = $entry['object'];
			}
		}

		return $out;
	}//end savedFor()

	/**
	 * REQ-RETRO-001 + REQ-RETRO-002: the delta is stored on a new
	 * PayrollAdjustment carrying cents-exact delta* fields against the ORIGINAL
	 * period's tax year, and NOTHING is written against the sealed Payslip or
	 * PayrollRun.
	 *
	 * @return void
	 */
	public function testComputesDeltaAgainstOriginalTaxYearAndNeverTouchesSealedOriginal(): void {
		[$service, $fake] = $this->service($this->sealedRows());

		$result = $service->adjustFor('2026-02', 'emp-1', 't1', 3800.00, 'backdated-raise', '2026-04');

		$this->assertSame('computed', $result['status'], $result['message']);
		$this->assertSame('nl-2026', $result['engineVersion'], 'The recompute uses the ORIGINAL period (2026-02) tax year.');

		// Exactly one PayrollAdjustment written; NOTHING against Payslip/PayrollRun.
		$adjustments = $this->savedFor($fake, 'PayrollAdjustment');
		$this->assertCount(1, $adjustments);
		$this->assertSame([], $this->savedFor($fake, 'Payslip'), 'The sealed Payslip is never written.');
		$this->assertSame([], $this->savedFor($fake, 'PayrollRun'), 'The sealed PayrollRun is never written.');
		$this->assertSame([], $fake->deleted, 'Nothing is ever deleted.');

		// The delta = recomputed(3800, nl-2026 anchor) - stored(wrong 3500 figures).
		$adjustment = $adjustments[0];
		$this->assertSame('emp-1', $adjustment['employeeId']);
		$this->assertSame('run-approved', $adjustment['originalPayrollRunId']);
		$this->assertSame('ps-orig', $adjustment['originalPayslipId']);
		$this->assertSame('2026-02', $adjustment['originalPeriod']);
		$this->assertSame('t1', $adjustment['correctionRef']);
		$this->assertSame('draft', $adjustment['status']);
		$this->assertSame('2026-04', $adjustment['settlementPeriod']);

		// Anchor recompute figures: gross 3800.00, loonheffing 718.83,
		// nettoPay 3081.17, wnv 419.14, zvw 231.80, vakantiegeld 304.00.
		$this->assertSame(300.00, $adjustment['deltaGross']);
		$this->assertSame(98.83, $adjustment['deltaLoonheffing']);
		$this->assertSame(201.17, $adjustment['deltaNet']);
		$this->assertSame(33.14, $adjustment['deltaWerknemersverzekeringen']);
		$this->assertSame(18.30, $adjustment['deltaZvw']);
		$this->assertSame(24.00, $adjustment['deltaVakantiegeldReserved']);
		$this->assertSame('nabetaling', $adjustment['settlementLine'], 'A positive net delta is a nabetaling.');

	}//end testComputesDeltaAgainstOriginalTaxYearAndNeverTouchesSealedOriginal()

	/**
	 * REQ-RETRO-003: re-running the same (originalPeriod, employeeId,
	 * correctionRef) updates the SAME PayrollAdjustment in place -- no second
	 * object, no double-count.
	 *
	 * @return void
	 */
	public function testReRunningTheSameCorrectionIsIdempotent(): void {
		$rows = $this->sealedRows();
		$rows['PayrollAdjustment'] = [
			[
				'id' => 'adj-1',
				'employeeId' => 'emp-1',
				'originalPeriod' => '2026-02',
				'correctionRef' => 't1',
				'correctedGrossMonthlySalary' => 3800.00,
				'status' => 'draft',
				'settlementPeriod' => '2026-04',
				'engineVersion' => 'nl-2026',
				'deltaNet' => 201.17,
			],
		];

		[$service, $fake] = $this->service($rows);

		// Re-run WITHOUT --gross: the stored corrected gross is reused.
		$result = $service->adjustFor('2026-02', 'emp-1', 't1');

		$this->assertSame('computed', $result['status'], $result['message']);
		$this->assertTrue($result['idempotent'], 'The existing adjustment is matched, not duplicated.');

		$adjustments = $this->savedFor($fake, 'PayrollAdjustment');
		$this->assertCount(1, $adjustments, 'Exactly one save -- the existing object, updated in place.');
		$this->assertSame('adj-1', $adjustments[0]['id'], 'The SAME (originalPeriod, employeeId, correctionRef) object is updated.');
		$this->assertSame(201.17, $adjustments[0]['deltaNet'], 'The recorded delta is unchanged.');

		// Still nothing against the sealed original.
		$this->assertSame([], $this->savedFor($fake, 'Payslip'));
		$this->assertSame([], $this->savedFor($fake, 'PayrollRun'));

	}//end testReRunningTheSameCorrectionIsIdempotent()

	/**
	 * REQ-RETRO-002: a cross-year correction (original period 2025-11, only
	 * nl-2026.json exists) refuses with the historical-tables-missing message
	 * and writes no PayrollAdjustment.
	 *
	 * @return void
	 */
	public function testCrossYearCorrectionRefusesWhenHistoricalTableMissing(): void {
		$rows = [
			'Employee' => [$this->employee()],
			'EmploymentContract' => [$this->contract()],
			'PayrollRun' => [
				['id' => 'run-2025', 'period' => '2025-11', 'administrationId' => 'ADM-001', 'jurisdiction' => 'NL', 'status' => 'approved', 'engineVersion' => 'nl-2025'],
			],
			'Payslip' => [$this->storedPayslip(['id' => 'ps-2025', 'payrollRunId' => 'run-2025', 'period' => '2025-11'])],
			'PayrollAdjustment' => [],
		];

		[$service, $fake] = $this->service($rows);

		$result = $service->adjustFor('2025-11', 'emp-1', 't1', 3800.00, null, '2026-04');

		$this->assertSame('historical-tables-missing', $result['status']);
		$this->assertStringContainsString('nl-2025.json', $result['message']);
		$this->assertSame([], $this->savedFor($fake, 'PayrollAdjustment'), 'No adjustment is written for a cross-year refusal.');

	}//end testCrossYearCorrectionRefusesWhenHistoricalTableMissing()

	/**
	 * REQ-RETRO-005: a draft original run refuses adjustment
	 * (`refused-original-draft`) and writes nothing -- a draft run is
	 * recomputed directly, not corrected via a delta.
	 *
	 * @return void
	 */
	public function testDraftOriginalRunRefusesAdjustment(): void {
		[$service, $fake] = $this->service($this->sealedRows('draft'));

		$result = $service->adjustFor('2026-02', 'emp-1', 't1', 3800.00, null, '2026-04');

		$this->assertSame('refused-original-draft', $result['status']);
		$this->assertSame([], $this->savedFor($fake, 'PayrollAdjustment'), 'No adjustment is written against a draft original.');

	}//end testDraftOriginalRunRefusesAdjustment()

	/**
	 * REQ-RETRO-004: applying a correction flips status to applied and stamps
	 * settlementPayrollRunId -- and, crucially, still never touches the sealed
	 * original.
	 *
	 * @return void
	 */
	public function testApplyStampsSettlementRunAndFlipsStatus(): void {
		[$service, $fake] = $this->service($this->sealedRows());

		$result = $service->adjustFor('2026-02', 'emp-1', 't1', 3800.00, null, '2026-04', true);

		$this->assertSame('applied', $result['status'], $result['message']);

		$adjustments = $this->savedFor($fake, 'PayrollAdjustment');
		$this->assertCount(1, $adjustments);
		$this->assertSame('applied', $adjustments[0]['status']);
		$this->assertSame('run-draft-apr', $adjustments[0]['settlementPayrollRunId'], 'The settlement run of 2026-04 is stamped.');

		// Sealed original still untouched even on apply.
		$this->assertSame([], $this->savedFor($fake, 'Payslip'));
		$this->assertSame([], $this->savedFor($fake, 'PayrollRun'));

	}//end testApplyStampsSettlementRunAndFlipsStatus()

}//end class
