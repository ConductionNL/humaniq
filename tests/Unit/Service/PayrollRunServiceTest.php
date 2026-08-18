<?php

/**
 * Unit tests for PayrollRunService.
 *
 * Pins the payroll-core-engine service contract (design.md D4/D5): the
 * probe-before-create idempotency per (period, administrationId), the
 * draft-only recalculation guard (approved runs refuse and change nothing),
 * the (payrollRunId, employeeId)-keyed upsert + orphan cleanup that never
 * touches hand-entered payslips, per-employee skip reasons (no contract /
 * no monthly salary / anoniementarief preconditions / non-NL), payslip
 * stamping (payrollRunId, userId, arbeidskorting, zvwMode) and the
 * cents-exact totals roll-up + engineVersion/calculatedAt stamps. Drives the
 * service through a fake ObjectService double (a fake collaborator, not a
 * fake of the service logic under test) since the real OpenRegister
 * ObjectService is a sibling-app dependency not available in this standalone
 * suite; the anchor-case euro amounts asserted here are the design.md D2
 * hand-computed figures.
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
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-003
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-004
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-005
 * @spec openspec/changes/fleet-bijtelling/specs/fleet-bijtelling/spec.md#REQ-FLEET-003
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-001
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-002
 * @spec openspec/changes/30-procent-regeling/specs/30-procent-regeling/spec.md#REQ-30P-003
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Payroll\PayrollCalculator;
use OCA\Hrmq\Payroll\SickPayCalculator;
use OCA\Hrmq\Service\PayrollRetentionGuardService;
use OCA\Hrmq\Service\PayrollRunService;
use OCA\Hrmq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PayrollRunService.
 *
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-003
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-001
 */
class PayrollRunServiceTest extends TestCase {

	/**
	 * Build a fake ObjectService double: `findAll()` returns the seeded rows
	 * for the current schema, `saveObject()` records every write (assigning a
	 * generated id when no uuid is given) and reflects it back into the
	 * seeded rows, `deleteObject()` records + removes the row.
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

				$targetSchema = ($schema ?? $this->schema);
				$rows = ($this->rowsBySchema[$targetSchema] ?? []);
				foreach ($rows as $i => $row) {
					if ((string)($row['id'] ?? '') === $uuid) {
						unset($rows[$i]);
						break;
					}
				}

				$this->rowsBySchema[$targetSchema] = array_values($rows);

				return true;
			}//end deleteObject()

		};

	}//end fakeObjectService()

	/**
	 * Build a fully-wired PayrollRunService plus its fake ObjectService
	 * double (for assertions on what was saved/deleted).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 * @param PayrollRetentionGuardService|null $retentionGuard A mocked retention guard, or null for a permissive default (hrmq#99 -- `savePayslip()` places the AWR floor hold on every seal; most tests here are not exercising that behaviour, so a plain mock with no expectations is the default).
	 *
	 * @return array{0: PayrollRunService, 1: object, 2: PayrollRetentionGuardService&\PHPUnit\Framework\MockObject\MockObject}
	 */
	private function service(array $rowsBySchema = [], ?PayrollRetentionGuardService $retentionGuard = null): array {
		$fake = $this->fakeObjectService($rowsBySchema);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		$settings->method('getPayrollAofTariff')->willReturn('laag');
		$settings->method('getPayrollWhkPercentage')->willReturnArgument(0);

		$logger = $this->createMock(LoggerInterface::class);

		if ($retentionGuard === null) {
			$retentionGuard = $this->createMock(PayrollRetentionGuardService::class);
		}

		return [
			new PayrollRunService($container, $settings, new PayrollCalculator(), new SickPayCalculator(), $retentionGuard, $logger),
			$fake,
			$retentionGuard,
		];

	}//end service()

	/**
	 * The anchor-case Employee fixture (design.md D2: €3.800, wit, korting,
	 * below AOW), overridable per test.
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
	 * @return void
	 */
	public function testCreatesDraftRunAndAnchorPayslip(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
			]
		);

		$result = $service->runFor('2026-02');

		$this->assertSame('calculated', $result['status']);
		$this->assertCount(1, $result['computed']);
		$this->assertSame([], $result['skipped']);

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);
		$payslip = $payslips[0];

		$this->assertSame((string)$result['runId'], (string)$payslip['payrollRunId']);
		$this->assertSame('sanne', $payslip['userId']);
		$this->assertSame('emp-1', $payslip['employeeId']);
		$this->assertSame('2026-02', $payslip['period']);
		$this->assertSame('NL', $payslip['jurisdiction']);
		$this->assertSame('werkgeversheffing', $payslip['zvwMode']);
		// The design.md D2 anchor figures, euro-denominated.
		$this->assertSame(3800.00, $payslip['grossPay']);
		$this->assertSame(718.83, $payslip['loonheffing']);
		$this->assertSame(473.75, $payslip['arbeidskorting']);
		$this->assertSame(231.80, $payslip['zvw']);
		$this->assertSame(419.14, $payslip['werknemersverzekeringen']);
		$this->assertSame(304.00, $payslip['vakantiegeldReserved']);
		$this->assertSame(3081.17, $payslip['nettoPay']);
		$this->assertFalse($payslip['anoniementariefApplied']);

		// The run: created draft once, then updated with totals + stamps.
		$runs = $this->savedFor($fake, 'PayrollRun');
		$this->assertCount(2, $runs);
		$this->assertSame('draft', $runs[0]['status']);
		$final = $runs[1];
		$this->assertSame('draft', $final['status']);
		$this->assertSame(3800.00, $final['totalGross']);
		$this->assertSame(718.83, $final['totalLoonheffing']);
		$this->assertSame(650.94, $final['totalEmployerCharges']);
		$this->assertSame(718.83, $final['totalWithholdings']);
		$this->assertSame(3081.17, $final['totalNet']);
		// jurisdiction-packs design.md D7: engineVersion stamps the jurisdiction
		// PACK that computed the run, `{packId}@{packVersion}`, rather than the
		// bare tables id. Strictly more information — it names the chain as well
		// as the parameter set. Every cents-exact total above is unchanged.
		$this->assertSame('nl-2026@1.1.0', $final['engineVersion']);
		$this->assertNotSame('', trim((string)$final['calculatedAt']));
		$this->assertArrayNotHasKey('glExpensePosted', $final);

	}//end testCreatesDraftRunAndAnchorPayslip()

	/**
	 * 30-procent-regeling (design.md D4/D5): a granted-ruling employee's
	 * payslip carries `thirtyPercentRulingExemption` €1.140,00 and a `nettoPay`
	 * of €3.548,83 -- HIGHER than the €3.081,17 the same €3.800,00 gross yields
	 * without the ruling, never lower. `grossPay` stays the full €3.800,00.
	 *
	 * @return void
	 */
	public function testGrantedRulingStampsExemptionAndRaisesNetto(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [
					$this->employee(
						[
							'thirtyPercentRulingGranted' => true,
							'thirtyPercentRulingRate' => 30.0,
						]
					),
				],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
			]
		);

		$result = $service->runFor('2026-02');
		$this->assertSame('calculated', $result['status']);

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);
		$payslip = $payslips[0];

		$this->assertSame(1140.00, $payslip['thirtyPercentRulingExemption'], '30%-ruling: exemption must be €1.140,00 (min(3800, 21833.33) × 30%).');
		$this->assertSame(3800.00, $payslip['grossPay'], '30%-ruling: grossPay stays the full unreduced €3.800,00.');
		$this->assertSame(251.17, $payslip['loonheffing']);
		$this->assertSame(3548.83, $payslip['nettoPay']);
		$this->assertGreaterThan(3081.17, $payslip['nettoPay'], '30%-ruling: nettoPay must RISE relative to the same-gross non-ruling case, never fall.');

	}//end testGrantedRulingStampsExemptionAndRaisesNetto()

	/**
	 * 30-procent-regeling (design.md D5): a non-granted employee's payslip is
	 * byte-identical to the pre-change shape -- `thirtyPercentRulingExemption`
	 * is null and every engine component matches the base anchor exactly.
	 *
	 * @return void
	 */
	public function testNonGrantedRulingPayslipIsByteIdenticalWithNullExemption(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee(['thirtyPercentRulingGranted' => false])],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
			]
		);

		$result = $service->runFor('2026-02');
		$this->assertSame('calculated', $result['status']);

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);
		$payslip = $payslips[0];

		$this->assertNull($payslip['thirtyPercentRulingExemption'], 'no ruling: exemption must be null, not €0,00.');
		$this->assertSame(3800.00, $payslip['grossPay']);
		$this->assertSame(718.83, $payslip['loonheffing']);
		$this->assertSame(3081.17, $payslip['nettoPay']);

	}//end testNonGrantedRulingPayslipIsByteIdenticalWithNullExemption()

	/**
	 * hrmq#99 regression fix: `savePayslip()` places the AWR art. 52 lid 4
	 * statutory-retention legal hold on every sealed Payslip -- a plain NL
	 * Payslip has neither a populated `retainedUntil` nor an OpenRegister-
	 * computed `archiefactiedatum`, so without this call it was left fully
	 * erasable by the guarded DSAR erase (`PayrollRetentionGuardService`'s
	 * class docblock REGRESSION note).
	 *
	 * @return void
	 */
	public function testSavePayslipPlacesTheAwrStatutoryFloorHoldOnEverySeal(): void {
		$retentionGuard = $this->createMock(PayrollRetentionGuardService::class);
		$retentionGuard->expects($this->once())
			->method('placeStatutoryFloorHold')
			->with(
				$this->anything(),
				'Payslip',
				'period',
				PayrollRetentionGuardService::AWR_RETENTION_YEARS,
				PayrollRetentionGuardService::AWR_LAW_REFERENCE
			)
			->willReturn(['held' => true, 'ceiling' => '2033-12-31']);

		[$service] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
			],
			retentionGuard: $retentionGuard
		);

		$result = $service->runFor('2026-02');

		$this->assertSame('calculated', $result['status']);

	}//end testSavePayslipPlacesTheAwrStatutoryFloorHoldOnEverySeal()

	/**
	 * audit-trail-payroll REQ-AUDP-001 (fixing hrmq#98): every engine-
	 * produced payslip carries a decodable `engineInputSnapshot` naming the
	 * exact resolved `CalculationInput` fed to the engine for it -- so it
	 * stays reproducible after the underlying Employee/EmploymentContract is
	 * edited later. `engineVersion`/`calculatedAt` are stamped in the SAME
	 * write; the snapshot is the missing third leg of that traceability.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-001
	 */
	public function testGeneratedPayslipCarriesADecodableEngineInputSnapshotMatchingResolvedInputs(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
			]
		);

		$service->runFor('2026-02');

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);
		$payslip = $payslips[0];

		$this->assertArrayHasKey('engineInputSnapshot', $payslip);
		$decoded = $payslip['engineInputSnapshot'];
		// Stored as a structured object (not a JSON string): OpenRegister's
		// MagicMapper json_decodes any string column on read, so a string field
		// would read back as an array and fail its own `type: string` on the
		// next validated save (hrmq#98 follow-up).
		$this->assertIsArray($decoded);
		$this->assertNotSame([], $decoded);

		// Matches the employee/contract inputs that were actually resolved.
		$this->assertSame(380000, $decoded['grossMonthlySalaryCents']);
		$this->assertSame('wit', $decoded['taxTableColor']);
		$this->assertTrue($decoded['loonheffingskortingToegepast']);
		$this->assertSame('1990-04-12', $decoded['dateOfBirth']);
		$this->assertSame('2026-02', $decoded['period']);
		$this->assertSame('low', $decoded['awfTariff']);
		$this->assertSame('laag', $decoded['aofTariff']);
		$this->assertTrue($decoded['verzekeringsplichtig']);
		$this->assertSame('NL', $decoded['jurisdiction']);

	}//end testGeneratedPayslipCarriesADecodableEngineInputSnapshotMatchingResolvedInputs()

	/**
	 * A later edit to the Employee's data must NOT retroactively change an
	 * already-stamped `engineInputSnapshot` -- the whole point of the
	 * snapshot (fixing hrmq#98). Recalculating a draft run with the CURRENT
	 * (edited) Employee data replaces the snapshot wholesale with the NEW
	 * resolved inputs (mirroring `engineVersion`/`calculatedAt` -- never
	 * edited in place, but a draft recalculation is an explicit, deliberate
	 * regeneration, not a silent retroactive rewrite); the guarantee this
	 * change closes is that a SEALED (non-draft) run's payslip is never
	 * touched again, which `hrmq:payroll:reproduce`'s use of the SEALED
	 * value proves.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-001
	 */
	public function testHandEnteredPayslipHasNoEngineInputSnapshot(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [
					[
						'id' => 'hand-entered-1',
						'payrollRunId' => null,
						'employeeId' => 'emp-1',
						'period' => '2025-01',
						'grossPay' => 3000.00,
						'nettoPay' => 2500.00,
					],
				],
			]
		);

		$service->runFor('2026-02');

		$handEntered = null;
		foreach ($fake->rowsBySchema['Payslip'] as $row) {
			if ((string)($row['id'] ?? '') === 'hand-entered-1') {
				$handEntered = $row;
			}
		}

		$this->assertNotNull($handEntered);
		$this->assertArrayNotHasKey('engineInputSnapshot', $handEntered);

	}//end testHandEnteredPayslipHasNoEngineInputSnapshot()

	/**
	 * @return void
	 */
	public function testSecondRunWithoutRecalculateIsAnIdempotentNoOp(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [
					['id' => 'run-1', 'period' => '2026-02', 'administrationId' => 'ADM-001', 'jurisdiction' => 'NL', 'status' => 'draft'],
				],
				'Payslip' => [],
			]
		);

		$result = $service->runFor('2026-02');

		$this->assertSame('exists', $result['status']);
		$this->assertSame('run-1', $result['runId']);
		$this->assertSame([], $fake->saved, 'An idempotent no-op must write nothing.');
		$this->assertSame([], $fake->deleted);

	}//end testSecondRunWithoutRecalculateIsAnIdempotentNoOp()

	/**
	 * @return void
	 */
	public function testApprovedRunRefusesRecalculationAndChangesNothing(): void {
		$rows = [
			'Employee' => [$this->employee()],
			'EmploymentContract' => [$this->contract()],
			'PayrollRun' => [
				['id' => 'run-1', 'period' => '2026-02', 'administrationId' => 'ADM-001', 'jurisdiction' => 'NL', 'status' => 'approved', 'totalNet' => 3081.17],
			],
			'Payslip' => [],
		];

		[$service, $fake] = $this->service($rows);
		$viaPeriod = $service->runFor('2026-02', null, true);
		$this->assertSame('refused-not-draft', $viaPeriod['status']);
		$this->assertSame([], $fake->saved);
		$this->assertSame([], $fake->deleted);

		[$service2, $fake2] = $this->service($rows);
		$viaRunId = $service2->recalculateRun('run-1');
		$this->assertSame('refused-not-draft', $viaRunId['status']);
		$this->assertSame([], $fake2->saved);
		$this->assertSame([], $fake2->deleted);

	}//end testApprovedRunRefusesRecalculationAndChangesNothing()

	/**
	 * @return void
	 */
	public function testRecalculationUpsertsInPlaceAndDeletesOrphans(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [
					['id' => 'run-1', 'period' => '2026-02', 'administrationId' => 'ADM-001', 'jurisdiction' => 'NL', 'status' => 'draft'],
				],
				'Payslip' => [
					// The existing engine payslip for emp-1 (stale figures) — must be updated IN PLACE.
					['id' => 'ps-emp1', 'payrollRunId' => 'run-1', 'employeeId' => 'emp-1', 'period' => '2026-02', 'nettoPay' => 1.00],
					// An orphaned engine payslip of THIS run (its employee is gone) — must be deleted.
					['id' => 'ps-orphan', 'payrollRunId' => 'run-1', 'employeeId' => 'emp-gone', 'period' => '2026-02', 'nettoPay' => 2.00],
					// A hand-entered payslip (null payrollRunId) — must never be touched.
					['id' => 'ps-hand', 'payrollRunId' => null, 'employeeId' => 'emp-1', 'period' => '2026-02', 'nettoPay' => 3.00],
					// Another run's engine payslip — must never be touched.
					['id' => 'ps-other-run', 'payrollRunId' => 'run-2', 'employeeId' => 'emp-1', 'period' => '2026-01', 'nettoPay' => 4.00],
				],
			]
		);

		$result = $service->runFor('2026-02', 'ADM-001', true);

		$this->assertSame('calculated', $result['status']);

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);
		$this->assertSame('ps-emp1', $payslips[0]['id'], 'The (payrollRunId, employeeId)-keyed payslip must be updated in place.');
		$this->assertSame(3081.17, $payslips[0]['nettoPay']);

		$this->assertSame(['ps-orphan'], $fake->deleted, 'Exactly the orphaned engine payslip of this run is deleted.');

	}//end testRecalculationUpsertsInPlaceAndDeletesOrphans()

	/**
	 * @return void
	 */
	public function testEmployeesAreSkippedWithPerEmployeeReasons(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [
					$this->employee(),
					$this->employee(['id' => 'emp-2', 'employeeNumber' => 'EMP-NL-0002', 'firstName' => 'Geen', 'lastName' => 'Salaris', 'grossMonthlySalary' => null]),
					$this->employee(['id' => 'emp-3', 'employeeNumber' => 'EMP-NL-0003', 'firstName' => 'Geen', 'lastName' => 'Contract']),
					$this->employee(['id' => 'emp-4', 'employeeNumber' => 'EMP-NL-0004', 'firstName' => 'Geen', 'lastName' => 'Bsn', 'bsn' => '']),
					$this->employee(['id' => 'emp-5', 'employeeNumber' => 'EMP-NL-0005', 'firstName' => 'Niet', 'lastName' => 'NL', 'taxTableColor' => null]),
					$this->employee(['id' => 'emp-6', 'employeeNumber' => 'EMP-NL-0006', 'firstName' => 'Al', 'lastName' => 'Weg', 'endDate' => '2025-12-31']),
				],
				'EmploymentContract' => [
					$this->contract(),
					$this->contract(['id' => 'ct-2', 'employeeId' => 'emp-2']),
					$this->contract(['id' => 'ct-4', 'employeeId' => 'emp-4']),
					$this->contract(['id' => 'ct-5', 'employeeId' => 'emp-5']),
				],
				'PayrollRun' => [],
				'Payslip' => [],
			]
		);

		$result = $service->runFor('2026-02');

		$this->assertSame('calculated', $result['status']);
		$this->assertCount(1, $result['computed'], 'Only the fully-computable employee gets a payslip.');
		$this->assertCount(1, $this->savedFor($fake, 'Payslip'));

		$reasonsByEmployee = [];
		foreach ($result['skipped'] as $skip) {
			$reasonsByEmployee[(string)$skip['employee']] = (string)$skip['reason'];
		}

		$this->assertCount(4, $reasonsByEmployee, 'Every selected-but-uncomputable employee is reported; the inactive one is not selected at all.');
		$this->assertStringContainsString('no-monthly-salary', $reasonsByEmployee['Geen Salaris']);
		$this->assertStringContainsString('no-contract', $reasonsByEmployee['Geen Contract']);
		$this->assertStringContainsString('anoniementarief-precondition', $reasonsByEmployee['Geen Bsn']);
		$this->assertStringContainsString('non-nl', $reasonsByEmployee['Niet NL']);
		$this->assertArrayNotHasKey('Al Weg', $reasonsByEmployee);

	}//end testEmployeesAreSkippedWithPerEmployeeReasons()

	/**
	 * @return void
	 */
	public function testTotalsAreCentsExactSumsAcrossMultipleEmployees(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [
					$this->employee(),
					$this->employee(['id' => 'emp-2', 'employeeNumber' => 'EMP-NL-0002', 'firstName' => 'Piet', 'lastName' => 'Deeltijd', 'grossMonthlySalary' => 2280.00, 'nextcloudUserId' => 'piet']),
				],
				'EmploymentContract' => [
					$this->contract(),
					$this->contract(['id' => 'ct-2', 'employeeId' => 'emp-2', 'hoursPerWeek' => 21.6]),
				],
				'PayrollRun' => [],
				'Payslip' => [],
			]
		);

		$result = $service->runFor('2026-02');

		$this->assertSame('calculated', $result['status']);
		$this->assertCount(2, $result['computed']);

		// Anchor (3800: lh 718.83, net 3081.17, charges 650.94) + part-time
		// fixture (2280: lh 110.33, net 2169.67, charges 390.57).
		$totals = $result['totals'];
		$this->assertSame(6080.00, $totals['totalGross']);
		$this->assertSame(829.16, $totals['totalLoonheffing']);
		$this->assertSame(1041.51, $totals['totalEmployerCharges']);
		$this->assertSame(829.16, $totals['totalWithholdings']);
		$this->assertSame(5250.84, $totals['totalNet']);

	}//end testTotalsAreCentsExactSumsAcrossMultipleEmployees()

	/**
	 * retro-adjustments REQ-RETRO-004: an APPLIED PayrollAdjustment whose
	 * settlementPeriod equals the draft run's period folds its deltaNet into
	 * that employee's payslip retroAdjustment + nettoPay -- and the sealed
	 * historical payslip it corrects is never written by run generation.
	 *
	 * @return void
	 */
	public function testAppliedAdjustmentFoldsIntoCurrentRunPayslip(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [
					// The SEALED original this adjustment corrects (a Jan run) --
					// run generation for Feb must never touch it.
					['id' => 'ps-sealed', 'payrollRunId' => 'run-jan-sealed', 'employeeId' => 'emp-1', 'period' => '2026-01', 'nettoPay' => 2880.00],
				],
				'PayrollAdjustment' => [
					['id' => 'adj-1', 'employeeId' => 'emp-1', 'originalPeriod' => '2026-01', 'originalPayslipId' => 'ps-sealed', 'correctionRef' => 't1', 'status' => 'applied', 'settlementPeriod' => '2026-02', 'settlementLine' => 'nabetaling', 'engineVersion' => 'nl-2026', 'deltaNet' => 201.17],
				],
			]
		);

		$result = $service->runFor('2026-02');

		$this->assertSame('calculated', $result['status']);

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips, 'Only the new Feb payslip is written -- the sealed Jan payslip is untouched.');
		$payslip = $payslips[0];

		// Anchor net 3081.17 + applied delta 201.17 = 3282.34.
		$this->assertSame(201.17, $payslip['retroAdjustment'], 'The applied delta surfaces as a retroAdjustment component.');
		$this->assertSame(3282.34, $payslip['nettoPay'], 'nettoPay includes the retro delta.');

		// The sealed original (ps-sealed) is never saved or deleted.
		foreach ($fake->saved as $entry) {
			$this->assertNotSame('ps-sealed', (string)($entry['object']['id'] ?? ''), 'The sealed historical payslip is never written.');
		}
		$this->assertSame([], $fake->deleted);

		// The run total net reflects the folded delta.
		$this->assertSame(3282.34, $result['totals']['totalNet']);

	}//end testAppliedAdjustmentFoldsIntoCurrentRunPayslip()

	/**
	 * retro-adjustments REQ-RETRO-004: a DRAFT (unsettled) adjustment does not
	 * affect any run -- the payslip carries no retroAdjustment and nettoPay is
	 * the plain engine figure.
	 *
	 * @return void
	 */
	public function testDraftAdjustmentDoesNotAffectTheRun(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
				'PayrollAdjustment' => [
					['id' => 'adj-1', 'employeeId' => 'emp-1', 'originalPeriod' => '2026-01', 'correctionRef' => 't1', 'status' => 'draft', 'settlementPeriod' => '2026-02', 'deltaNet' => 201.17],
				],
			]
		);

		$result = $service->runFor('2026-02');

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);
		$this->assertNull($payslips[0]['retroAdjustment'], 'A draft adjustment adds no retroAdjustment component.');
		$this->assertSame(3081.17, $payslips[0]['nettoPay'], 'nettoPay is the plain engine figure.');

	}//end testDraftAdjustmentDoesNotAffectTheRun()

	/**
	 * leave-buy-sell REQ-BUYSELL-005: a settled `sell` LeaveTransaction whose
	 * settlementPeriod equals the draft run's period adds its settledAmount
	 * as a positive `leaveBuySell` component, folded into nettoPay.
	 *
	 * @return void
	 */
	public function testSettledSellLeaveTransactionAddsToNettoPay(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
				'LeaveTransaction' => [
					['id' => 'txn-1', 'employeeId' => 'emp-1', 'transactionType' => 'sell', 'status' => 'settled', 'settlementPeriod' => '2026-02', 'settledAmount' => 200.00],
				],
			]
		);

		$result = $service->runFor('2026-02');

		$this->assertSame('calculated', $result['status']);

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);

		// Anchor net 3081.17 + settled sell 200.00 = 3281.17.
		$this->assertSame(200.00, $payslips[0]['leaveBuySell'], 'The settled sell surfaces as a leaveBuySell component.');
		$this->assertSame(3281.17, $payslips[0]['nettoPay'], 'nettoPay includes the leaveBuySell delta.');
		$this->assertSame(3281.17, $result['totals']['totalNet']);

	}//end testSettledSellLeaveTransactionAddsToNettoPay()

	/**
	 * leave-buy-sell REQ-BUYSELL-005: a settled `buy` LeaveTransaction
	 * deducts its settledAmount from nettoPay (negative leaveBuySell).
	 *
	 * @return void
	 */
	public function testSettledBuyLeaveTransactionDeductsFromNettoPay(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
				'LeaveTransaction' => [
					['id' => 'txn-1', 'employeeId' => 'emp-1', 'transactionType' => 'buy', 'status' => 'settled', 'settlementPeriod' => '2026-02', 'settledAmount' => 150.00],
				],
			]
		);

		$result = $service->runFor('2026-02');

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);

		// Anchor net 3081.17 - settled buy 150.00 = 2931.17.
		$this->assertSame(-150.00, $payslips[0]['leaveBuySell']);
		$this->assertSame(2931.17, $payslips[0]['nettoPay']);

	}//end testSettledBuyLeaveTransactionDeductsFromNettoPay()

	/**
	 * leave-buy-sell REQ-BUYSELL-005: an unsettled (approved) or
	 * wrong-period LeaveTransaction does not fold -- the payslip carries no
	 * leaveBuySell component and nettoPay is the plain engine figure
	 * (byte-identical to before this change).
	 *
	 * @return void
	 */
	public function testUnsettledOrWrongPeriodLeaveTransactionDoesNotFold(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
				'LeaveTransaction' => [
					['id' => 'txn-1', 'employeeId' => 'emp-1', 'transactionType' => 'sell', 'status' => 'approved', 'settlementPeriod' => '2026-02', 'settledAmount' => null],
					['id' => 'txn-2', 'employeeId' => 'emp-1', 'transactionType' => 'sell', 'status' => 'settled', 'settlementPeriod' => '2026-01', 'settledAmount' => 200.00],
				],
			]
		);

		$result = $service->runFor('2026-02');

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);
		$this->assertNull($payslips[0]['leaveBuySell']);
		$this->assertSame(3081.17, $payslips[0]['nettoPay']);

	}//end testUnsettledOrWrongPeriodLeaveTransactionDoesNotFold()

	/**
	 * The loonbeslag fixture: an `actief` Loonbeslag covering 2026-02 for the
	 * anchor employee, overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function loonbeslag(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'lb-1',
				'employeeId' => 'emp-1',
				'creditor' => 'Gerechtsdeurwaarderskantoor Van Dijk',
				'dossierRef' => 'GDW-2026-00123',
				'totalClaim' => 4200.00,
				'orderedAmount' => 800.00,
				'beslagvrijeVoet' => 2950.00,
				'status' => 'actief',
				'effectiveFrom' => '2026-01-01',
				'effectiveTo' => null,
			],
			$overrides
		);

	}//end loonbeslag()

	/**
	 * loonbeslag REQ-BESLAG-002 Scenario 1 — a large ordered deduction is
	 * clamped at the beslagvrije voet: anchor nettoPay €3.081,17,
	 * orderedAmount €800,00, beslagvrijeVoet €2.950,00 -> loonbeslag
	 * €131,17 (not €800,00), nettoPay exactly €2.950,00.
	 *
	 * @return void
	 */
	public function testLargeOrderedDeductionIsClampedAtBeslagvrijeVoet(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
				'Loonbeslag' => [$this->loonbeslag()],
			]
		);

		$result = $service->runFor('2026-02');

		$this->assertSame('calculated', $result['status']);

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);

		$this->assertSame(131.17, $payslips[0]['loonbeslag'], 'The deduction is clamped at the headroom above the beslagvrije voet, not the full orderedAmount.');
		$this->assertSame(2950.00, $payslips[0]['nettoPay'], 'nettoPay lands EXACTLY on the beslagvrije voet — never below it.');
		$this->assertSame('lb-1', $payslips[0]['loonbeslagId']);
		$this->assertSame(2950.00, $result['totals']['totalNet']);

	}//end testLargeOrderedDeductionIsClampedAtBeslagvrijeVoet()

	/**
	 * loonbeslag REQ-BESLAG-002 Scenario 2 — a small ordered deduction is
	 * never clamped: the same employee/Loonbeslag but orderedAmount €50,00 ->
	 * the full €50,00 is deducted, nettoPay €3.031,17.
	 *
	 * @return void
	 */
	public function testSmallOrderedDeductionIsNeverClamped(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
				'Loonbeslag' => [$this->loonbeslag(['orderedAmount' => 50.00])],
			]
		);

		$result = $service->runFor('2026-02');

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);

		$this->assertSame(50.00, $payslips[0]['loonbeslag'], 'Headroom exceeds orderedAmount, so the full ordered amount is deducted.');
		$this->assertSame(3031.17, $payslips[0]['nettoPay']);

	}//end testSmallOrderedDeductionIsNeverClamped()

	/**
	 * loonbeslag REQ-BESLAG-002 Scenario 3 — zero headroom deducts nothing:
	 * beslagvrijeVoet set to the anchor's exact folded nettoPay (as if a
	 * large terugvordering retro-adjustment already ate the headroom) ->
	 * `loonbeslag` is null and `nettoPay` is unchanged by the garnishment.
	 * `loonbeslagId` is still stamped -- the Loonbeslag genuinely covers the
	 * period, only the deduction itself is zero.
	 *
	 * @return void
	 */
	public function testZeroHeadroomDeductsNothing(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
				'Loonbeslag' => [$this->loonbeslag(['beslagvrijeVoet' => 3081.17])],
			]
		);

		$result = $service->runFor('2026-02');

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);

		$this->assertNull($payslips[0]['loonbeslag'], 'Zero headroom -- no deduction, represented as null (not 0.00).');
		$this->assertSame(3081.17, $payslips[0]['nettoPay'], 'nettoPay is unchanged by the garnishment.');
		$this->assertSame('lb-1', $payslips[0]['loonbeslagId'], 'The covering Loonbeslag is still stamped even though the deduction is zero.');

	}//end testZeroHeadroomDeductsNothing()

	/**
	 * loonbeslag REQ-BESLAG-004 Scenario 1 — an unaffected payslip stays
	 * byte-identical: no `actief` Loonbeslag covers the period -> both
	 * `loonbeslag`/`loonbeslagId` are null and nettoPay is the plain engine
	 * figure.
	 *
	 * @return void
	 */
	public function testNoActiveLoonbeslagLeavesThePayslipByteIdentical(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
				'Loonbeslag' => [
					// A concept (not yet activated) Loonbeslag -- must not fold.
					$this->loonbeslag(['id' => 'lb-concept', 'status' => 'concept']),
					// An actief Loonbeslag but for a DIFFERENT employee.
					$this->loonbeslag(['id' => 'lb-other-emp', 'employeeId' => 'emp-2']),
					// An actief Loonbeslag whose effective range does not cover this period.
					$this->loonbeslag(['id' => 'lb-future', 'effectiveFrom' => '2027-01-01']),
				],
			]
		);

		$result = $service->runFor('2026-02');

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);

		$this->assertNull($payslips[0]['loonbeslag']);
		$this->assertNull($payslips[0]['loonbeslagId']);
		$this->assertSame(3081.17, $payslips[0]['nettoPay']);

	}//end testNoActiveLoonbeslagLeavesThePayslipByteIdentical()

	/**
	 * loonbeslag REQ-BESLAG-004 Scenario 2 — loonbeslag folds AFTER
	 * retro-adjustment and leave-buy-sell, not before: the floor-clamp
	 * arithmetic uses nettoPay after those two folds, so a same-period
	 * nabetaling widens the garnishable headroom instead of the bare
	 * engine-computed nettoPay being clamped in isolation.
	 *
	 * Anchor net €3.081,17 + retro nabetaling €100,00 + leave-buy-sell sell
	 * €50,00 = €3.231,17 folded-so-far; beslagvrijeVoet €2.950,00 and
	 * orderedAmount €800,00 -> headroom €281,17 (< orderedAmount) so the
	 * deduction is clamped at €281,17, landing nettoPay EXACTLY on the voet.
	 *
	 * @return void
	 */
	public function testLoonbeslagFoldsAfterRetroAdjustmentAndLeaveBuySell(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [
					['id' => 'ps-sealed', 'payrollRunId' => 'run-jan-sealed', 'employeeId' => 'emp-1', 'period' => '2026-01', 'nettoPay' => 2880.00],
				],
				'PayrollAdjustment' => [
					['id' => 'adj-1', 'employeeId' => 'emp-1', 'originalPeriod' => '2026-01', 'originalPayslipId' => 'ps-sealed', 'correctionRef' => 't1', 'status' => 'applied', 'settlementPeriod' => '2026-02', 'settlementLine' => 'nabetaling', 'engineVersion' => 'nl-2026', 'deltaNet' => 100.00],
				],
				'LeaveTransaction' => [
					['id' => 'txn-1', 'employeeId' => 'emp-1', 'transactionType' => 'sell', 'status' => 'settled', 'settlementPeriod' => '2026-02', 'settledAmount' => 50.00],
				],
				'Loonbeslag' => [$this->loonbeslag()],
			]
		);

		$result = $service->runFor('2026-02');

		$this->assertSame('calculated', $result['status']);

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips, 'Only the new Feb payslip is written -- the sealed Jan payslip is untouched.');
		$payslip = $payslips[0];

		$this->assertSame(100.00, $payslip['retroAdjustment']);
		$this->assertSame(50.00, $payslip['leaveBuySell']);
		// 3081.17 + 100.00 + 50.00 = 3231.17 folded-so-far; headroom above
		// 2950.00 is 281.17 (< 800.00 ordered) -> clamped deduction 281.17.
		$this->assertSame(281.17, $payslip['loonbeslag'], 'The floor-clamp arithmetic uses nettoPay AFTER retroAdjustment/leaveBuySell, not the bare engine figure.');
		$this->assertSame(2950.00, $payslip['nettoPay'], 'nettoPay lands exactly on the beslagvrije voet.');
		$this->assertSame(2950.00, $result['totals']['totalNet']);

	}//end testLoonbeslagFoldsAfterRetroAdjustmentAndLeaveBuySell()

	/**
	 * loonbeslag REQ-BESLAG-005 — recalculating a draft run reproduces the
	 * IDENTICAL deduction (no accumulator, no drift across repeated
	 * `--recalculate`).
	 *
	 * @return void
	 */
	public function testRecalculatingALoonbeslagAffectedRunIsIdempotent(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [
					['id' => 'run-1', 'period' => '2026-02', 'administrationId' => 'ADM-001', 'jurisdiction' => 'NL', 'status' => 'draft'],
				],
				'Payslip' => [],
				'Loonbeslag' => [$this->loonbeslag()],
			]
		);

		$first = $service->runFor('2026-02', 'ADM-001', true);
		$this->assertSame('calculated', $first['status']);

		$firstPayslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $firstPayslips);
		$this->assertSame(131.17, $firstPayslips[0]['loonbeslag']);
		$this->assertSame(2950.00, $firstPayslips[0]['nettoPay']);

		$second = $service->runFor('2026-02', 'ADM-001', true);
		$this->assertSame('calculated', $second['status']);

		$secondPayslips = $this->savedFor($fake, 'Payslip');
		// Both saves target the SAME upserted (payrollRunId, employeeId) row.
		$this->assertCount(2, $secondPayslips, 'Recalculation upserts the same row in place -- both saves are recorded, both against the same id.');
		$this->assertSame($secondPayslips[0]['id'], $secondPayslips[1]['id']);
		$this->assertSame(131.17, $secondPayslips[1]['loonbeslag'], 'Recalculating reproduces the IDENTICAL deduction -- no accumulator, no drift.');
		$this->assertSame(2950.00, $secondPayslips[1]['nettoPay']);

	}//end testRecalculatingALoonbeslagAffectedRunIsIdempotent()

	/**
	 * The Vehicle fixture (fleet-bijtelling design.md D4 anchor:
	 * cataloguswaarde €45.000,00, bijtellingCategorie standaard/22%),
	 * overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function vehicle(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'veh-1',
				'name' => 'Tesla Model Y',
				'kenteken' => '1-ABC-23',
				'cataloguswaarde' => 45000.00,
				'fuelType' => 'volledigElektrisch',
				'bijtellingCategorie' => 'standaard',
				'active' => true,
			],
			$overrides
		);

	}//end vehicle()

	/**
	 * The CarAssignment fixture covering 2026-02 for the anchor employee
	 * (fleet-bijtelling design.md D4 anchor: eigenBijdrage €325,00),
	 * overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function carAssignment(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'ca-1',
				'vehicleId' => 'veh-1',
				'employeeId' => 'emp-1',
				'effectiveFrom' => '2026-01-01',
				'effectiveTo' => null,
				'eigenBijdrage' => 325.00,
			],
			$overrides
		);

	}//end carAssignment()

	/**
	 * fleet-bijtelling REQ-FLEET-003 Scenario 1 — the design.md D4
	 * bijtelling-anchor case: the €3.800,00 anchor employee with a covering
	 * CarAssignment (cataloguswaarde €45.000,00 standaard 22%, eigenBijdrage
	 * €325,00) reproduces every hand-computed D4 figure digit-for-digit.
	 *
	 * @return void
	 */
	public function testBijtellingFoldsIntoTaxableGrossBeforeTheCalculatorRuns(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
				'Vehicle' => [$this->vehicle()],
				'CarAssignment' => [$this->carAssignment()],
			]
		);

		$result = $service->runFor('2026-02');

		$this->assertSame('calculated', $result['status']);

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);
		$payslip = $payslips[0];

		// The design.md D4 anchor figures, euro-denominated, digit-for-digit.
		$this->assertSame(500.00, $payslip['bijtelling']);
		$this->assertSame('ca-1', $payslip['carAssignmentId']);
		$this->assertSame(4300.00, $payslip['grossPay'], 'grossPay already includes the bijtelling -- the calculator received the larger tvl.');
		$this->assertSame(970.83, $payslip['loonheffing']);
		$this->assertSame(441.33, $payslip['arbeidskorting']);
		$this->assertSame(262.30, $payslip['zvw']);
		$this->assertSame(474.29, $payslip['werknemersverzekeringen']);
		$this->assertSame(344.00, $payslip['vakantiegeldReserved']);
		$this->assertSame(3329.17, $payslip['nettoPay']);

	}//end testBijtellingFoldsIntoTaxableGrossBeforeTheCalculatorRuns()

	/**
	 * fleet-bijtelling REQ-FLEET-003 Scenario 2 — no covering CarAssignment
	 * leaves the payslip byte-identical to the pre-change (no company car)
	 * shape: `bijtelling`/`carAssignmentId` both null, `grossPay`/`nettoPay`
	 * the plain D2 anchor figures.
	 *
	 * @return void
	 */
	public function testNoCoveringCarAssignmentLeavesThePayslipUnchanged(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
				'Vehicle' => [$this->vehicle()],
				'CarAssignment' => [],
			]
		);

		$result = $service->runFor('2026-02');

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);

		$this->assertNull($payslips[0]['bijtelling']);
		$this->assertNull($payslips[0]['carAssignmentId']);
		$this->assertSame(3800.00, $payslips[0]['grossPay']);
		$this->assertSame(3081.17, $payslips[0]['nettoPay']);

	}//end testNoCoveringCarAssignmentLeavesThePayslipUnchanged()

	/**
	 * fleet-bijtelling REQ-FLEET-003 Scenario 3 — a large eigen bijdrage
	 * floors the bijtelling at zero, never negative: `bijtelling` is €0,00
	 * (not null -- the CarAssignment genuinely covers the period, only the
	 * amount is zero) and the taxable gross is unchanged from the plain
	 * salary.
	 *
	 * @return void
	 */
	public function testLargeEigenBijdrageFloorsTheBijtellingAtZero(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
				'Vehicle' => [$this->vehicle()],
				// base/12 = €825,00; an eigenBijdrage of €900,00 exceeds it.
				'CarAssignment' => [$this->carAssignment(['eigenBijdrage' => 900.00])],
			]
		);

		$result = $service->runFor('2026-02');

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);

		$this->assertSame(0.00, $payslips[0]['bijtelling'], 'Floored at zero, never negative.');
		$this->assertSame('ca-1', $payslips[0]['carAssignmentId'], 'The covering assignment is still stamped even though the amount is zero.');
		$this->assertSame(3800.00, $payslips[0]['grossPay'], 'The taxable gross is unchanged from the plain salary.');
		$this->assertSame(3081.17, $payslips[0]['nettoPay']);

	}//end testLargeEigenBijdrageFloorsTheBijtellingAtZero()

	/**
	 * fleet-bijtelling design.md D3 — the two-tier `elektrischGeplafonneerd`
	 * blend: cataloguswaarde €45.000,00 with an evReducedCataloguswaardeCap
	 * of €30.000,00 (nl-2026) -> base = 30.000 x 18% + 15.000 x 22% =
	 * 5.400,00 + 3.300,00 = 8.700,00; monthly = 8.700,00 / 12 = 725,00,
	 * eigenBijdrage 0 -> bijtelling €725,00.
	 *
	 * @return void
	 */
	public function testElektrischGeplafonneerdAppliesTheTwoTierBlend(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
				'Vehicle' => [$this->vehicle(['bijtellingCategorie' => 'elektrischGeplafonneerd'])],
				'CarAssignment' => [$this->carAssignment(['eigenBijdrage' => 0.00])],
			]
		);

		$result = $service->runFor('2026-02');

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);

		$this->assertSame(725.00, $payslips[0]['bijtelling']);

	}//end testElektrischGeplafonneerdAppliesTheTwoTierBlend()

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
	 * dga-payroll-mode REQ-DGA-001/REQ-DGA-002: an Employee with `isDga: true`
	 * produces a Payslip with `werknemersverzekeringen: 0.00`, `isDga: true`,
	 * and a `nettoPay` UNCHANGED from the same-gross non-DGA anchor
	 * (design.md D2 grounding correction — werknemersverzekeringen never
	 * reduced net). The run's `totalEmployerCharges` roll-up reflects the
	 * zeroed premiums (Zvw only).
	 *
	 * @return void
	 */
	public function testDgaEmployeeProducesZeroWerknemersverzekeringenAndUnchangedNetto(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee(['isDga' => true])],
				'EmploymentContract' => [$this->contract()],
				'PayrollRun' => [],
				'Payslip' => [],
			]
		);

		$result = $service->runFor('2026-02');

		$this->assertSame('calculated', $result['status']);
		$this->assertCount(1, $result['computed']);

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);
		$payslip = $payslips[0];

		$this->assertTrue($payslip['isDga']);
		$this->assertSame(0.00, $payslip['werknemersverzekeringen']);
		// Unchanged: loonheffing/zvw/vakantiegeldReserved/nettoPay stay the
		// design.md D2 anchor figures.
		$this->assertSame(718.83, $payslip['loonheffing']);
		$this->assertSame(231.80, $payslip['zvw']);
		$this->assertSame(304.00, $payslip['vakantiegeldReserved']);
		$this->assertSame(3081.17, $payslip['nettoPay']);

		$final = $this->savedFor($fake, 'PayrollRun')[1];
		$this->assertSame(231.80, $final['totalEmployerCharges'], 'DGA: totalEmployerCharges must reduce to zvw only');
		$this->assertSame(3081.17, $final['totalNet']);

	}//end testDgaEmployeeProducesZeroWerknemersverzekeringenAndUnchangedNetto()

	/**
	 * dga-payroll-mode REQ-DGA-002 scenario: a run mixing a DGA employee and
	 * a regular employee (both €3.800,00 gross) totals correctly --
	 * `totalEmployerCharges` = €231,80 (DGA) + €650,94 (regular) = €882,74 --
	 * and neither payslip's `nettoPay` differs from the other's per-employee
	 * equivalent gross-only calculation (both €3.081,17 at the same gross).
	 *
	 * @return void
	 */
	public function testMixedDgaAndRegularEmployeeRunTotalsCorrectly(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [
					$this->employee(['isDga' => true]),
					$this->employee(['id' => 'emp-2', 'employeeNumber' => 'EMP-NL-0002', 'firstName' => 'Piet', 'lastName' => 'Regulier', 'nextcloudUserId' => 'piet']),
				],
				'EmploymentContract' => [
					$this->contract(),
					$this->contract(['id' => 'ct-2', 'employeeId' => 'emp-2']),
				],
				'PayrollRun' => [],
				'Payslip' => [],
			]
		);

		$result = $service->runFor('2026-02');

		$this->assertSame('calculated', $result['status']);
		$this->assertCount(2, $result['computed']);

		$totals = $result['totals'];
		$this->assertSame(882.74, $totals['totalEmployerCharges']);
		$this->assertSame(6162.34, $totals['totalNet']);

		$payslips = $this->savedFor($fake, 'Payslip');
		$byEmployeeId = [];
		foreach ($payslips as $payslip) {
			$byEmployeeId[$payslip['employeeId']] = $payslip;
		}

		$this->assertTrue($byEmployeeId['emp-1']['isDga']);
		$this->assertFalse($byEmployeeId['emp-2']['isDga']);
		$this->assertSame(0.00, $byEmployeeId['emp-1']['werknemersverzekeringen']);
		$this->assertSame(419.14, $byEmployeeId['emp-2']['werknemersverzekeringen']);
		// Same gross -> same nettoPay, DGA or not (werknemersverzekeringen
		// never reduce net).
		$this->assertSame($byEmployeeId['emp-2']['nettoPay'], $byEmployeeId['emp-1']['nettoPay']);
		$this->assertSame(3081.17, $byEmployeeId['emp-1']['nettoPay']);

	}//end testMixedDgaAndRegularEmployeeRunTotalsCorrectly()

}//end class
