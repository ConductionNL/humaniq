<?php

/**
 * Unit tests for WkrService.
 *
 * Pins the wkr-administration service contract (design.md D5, spec.md
 * REQ-WKR-003/-WKR-005): the cross-object fiscale-loonsom + vrije-ruimte-used
 * roll-up (Σ Payslip.grossPay/wkrUsed + Σ vrije-ruimte WkrDeclaration.amount),
 * the tranche arithmetic read from the REAL `nl-2026.json` `wkr` table group
 * (never a hardcoded percentage — REQ-WKR-002), the within-budget and
 * over-budget outcomes, the idempotent (administrationId, year) upsert, the
 * exclusion of gericht-vrijgesteld/eindheffing declarations from
 * vrijeRuimteUsed, a Payslip's administrationId resolved via its
 * `payrollRunId` -> PayrollRun.administrationId when the Payslip carries no
 * denormalized administrationId of its own, and the `--all` distinct-pair
 * iteration. Drives the service through a fake ObjectService double (the
 * PayrollMutationServiceTest fake shape) since the real OpenRegister
 * ObjectService is a sibling-app dependency not available in this standalone
 * suite.
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
 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\WkrService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for WkrService.
 *
 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-002
 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-003
 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-005
 */
class WkrServiceTest extends TestCase {

	/**
	 * Build a fake ObjectService double (the PayrollMutationServiceTest
	 * shape): `findAll()` returns the seeded rows for the current schema,
	 * `saveObject()` records every write (assigning a generated id when no
	 * uuid is given) and reflects it back into the seeded rows so a re-assess
	 * upsert can be asserted as a no-duplicate in place.
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
	 * Build a fully-wired WkrService plus its fake ObjectService double (for
	 * assertions on what was saved).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return array{0: WkrService, 1: object}
	 */
	private function service(array $rowsBySchema = []): array {
		$fake = $this->fakeObjectService($rowsBySchema);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);

		return [new WkrService($container, $settings, $logger), $fake];
	}//end service()

	/**
	 * REQ-WKR-003 Scenario 1 — an administration within its vrije ruimte is
	 * assessed clean: fiscale loonsom €200.000 (below the €400.000 grens) ->
	 * vrije ruimte 2,00% = €4.000,00; a €300,00 vrije-ruimte declaration stays
	 * well within it. The Payslip carries no own `administrationId` -- it
	 * resolves via `payrollRunId` -> PayrollRun.administrationId (design.md
	 * D3's parent-resolution idiom).
	 *
	 * @return void
	 */
	public function testWithinBudgetAssessmentIsClean(): void {
		[$service] = $this->service(
			[
				'PayrollRun' => [
					['id' => 'run-1', 'administrationId' => 'ADM-001'],
				],
				'Payslip' => [
					['id' => 'ps-1', 'payrollRunId' => 'run-1', 'period' => '2026-01', 'grossPay' => 200000.00, 'wkrUsed' => 0.00],
				],
				'WkrDeclaration' => [
					['id' => 'wd-1', 'administrationId' => 'ADM-001', 'year' => 2026, 'amount' => 300.00, 'wkrCategory' => 'vrije-ruimte'],
				],
			]
		);

		$outcome = $service->assess('ADM-001', 2026);

		self::assertSame('ok', $outcome['status']);
		$assessment = $outcome['assessment'];
		self::assertSame(200000.00, $assessment['fiscaleLoonsom']);
		self::assertSame(4000.00, $assessment['vrijeRuimte']);
		self::assertSame(300.00, $assessment['vrijeRuimteUsed']);
		self::assertSame(3700.00, $assessment['vrijeRuimteRemaining']);
		self::assertSame(0.00, $assessment['excess']);
		self::assertNull($assessment['eindheffingRate']);
		self::assertSame(0.00, $assessment['eindheffingDue']);
		self::assertSame('binnen-vrije-ruimte', $assessment['status']);
		self::assertSame('nl-2026', $assessment['engineVersion']);

	}//end testWithinBudgetAssessmentIsClean()

	/**
	 * REQ-WKR-004 exposure scenario — used exceeds available: fiscale loonsom
	 * €200.000 -> vrije ruimte €4.000,00; declarations totalling €4.500,00
	 * exceed it by €500,00, so the assessment records the 80% eindheffing
	 * exposure (€400,00) and flips status.
	 *
	 * @return void
	 */
	public function testOverBudgetAssessmentRecordsEindheffingExposure(): void {
		[$service] = $this->service(
			[
				'PayrollRun' => [
					['id' => 'run-1', 'administrationId' => 'ADM-001'],
				],
				'Payslip' => [
					['id' => 'ps-1', 'payrollRunId' => 'run-1', 'period' => '2026-01', 'grossPay' => 200000.00, 'wkrUsed' => 0.00],
				],
				'WkrDeclaration' => [
					['id' => 'wd-1', 'administrationId' => 'ADM-001', 'year' => 2026, 'amount' => 4500.00, 'wkrCategory' => 'vrije-ruimte'],
				],
			]
		);

		$outcome = $service->assess('ADM-001', 2026);
		$assessment = $outcome['assessment'];

		self::assertSame('ok', $outcome['status']);
		self::assertSame(4000.00, $assessment['vrijeRuimte']);
		self::assertSame(4500.00, $assessment['vrijeRuimteUsed']);
		self::assertSame(0.00, $assessment['vrijeRuimteRemaining']);
		self::assertSame(500.00, $assessment['excess']);
		self::assertSame(80.0, $assessment['eindheffingRate']);
		self::assertSame(400.00, $assessment['eindheffingDue']);
		self::assertSame('eindheffing-verschuldigd', $assessment['status']);

	}//end testOverBudgetAssessmentRecordsEindheffingExposure()

	/**
	 * Tranche-2 arithmetic — a fiscale loonsom above the €400.000 grens
	 * splits into 2,00% of the first €400.000 (€8.000,00) plus 1,18% of the
	 * excess (€100.000 -> €1.180,00), totalling €9.180,00 (spec.md
	 * REQ-WKR-002).
	 *
	 * @return void
	 */
	public function testTranche2ArithmeticAboveGrens(): void {
		[$service] = $this->service(
			[
				'PayrollRun' => [
					['id' => 'run-1', 'administrationId' => 'ADM-002'],
				],
				'Payslip' => [
					['id' => 'ps-1', 'payrollRunId' => 'run-1', 'period' => '2026-03', 'grossPay' => 500000.00, 'wkrUsed' => 0.00],
				],
			]
		);

		$outcome = $service->assess('ADM-002', 2026);
		$assessment = $outcome['assessment'];

		self::assertSame('ok', $outcome['status']);
		self::assertSame(500000.00, $assessment['fiscaleLoonsom']);
		self::assertSame(9180.00, $assessment['vrijeRuimte']);
		self::assertSame('binnen-vrije-ruimte', $assessment['status']);

	}//end testTranche2ArithmeticAboveGrens()

	/**
	 * gericht-vrijgesteld and eindheffing declarations never count toward
	 * vrijeRuimteUsed (spec.md REQ-WKR-001) — only `vrije-ruimte` amounts do.
	 *
	 * @return void
	 */
	public function testGerichtVrijgestelldAndEindheffingDeclarationsAreExcluded(): void {
		[$service] = $this->service(
			[
				'PayrollRun' => [
					['id' => 'run-1', 'administrationId' => 'ADM-001'],
				],
				'Payslip' => [
					['id' => 'ps-1', 'payrollRunId' => 'run-1', 'period' => '2026-01', 'grossPay' => 200000.00, 'wkrUsed' => 0.00],
				],
				'WkrDeclaration' => [
					['id' => 'wd-1', 'administrationId' => 'ADM-001', 'year' => 2026, 'amount' => 300.00, 'wkrCategory' => 'vrije-ruimte'],
					['id' => 'wd-2', 'administrationId' => 'ADM-001', 'year' => 2026, 'amount' => 900.00, 'wkrCategory' => 'gericht-vrijgesteld'],
					['id' => 'wd-3', 'administrationId' => 'ADM-001', 'year' => 2026, 'amount' => 700.00, 'wkrCategory' => 'eindheffing'],
				],
			]
		);

		$outcome = $service->assess('ADM-001', 2026);
		$assessment = $outcome['assessment'];

		// Only the €300,00 vrije-ruimte declaration counts -- the €900,00
		// gerichte vrijstelling and the €700,00 already-eindheffingsloon
		// declaration are excluded from vrijeRuimteUsed.
		self::assertSame(300.00, $assessment['vrijeRuimteUsed']);
		self::assertSame('binnen-vrije-ruimte', $assessment['status']);

	}//end testGerichtVrijgestelldAndEindheffingDeclarationsAreExcluded()

	/**
	 * REQ-WKR-003 Scenario 2 — re-assessing the same (administrationId, year)
	 * upserts the existing WkrAssessment in place; no second assessment for
	 * that pair exists.
	 *
	 * @return void
	 */
	public function testReassessingSameAdministrationYearIsIdempotent(): void {
		[$service, $fake] = $this->service(
			[
				'PayrollRun' => [
					['id' => 'run-1', 'administrationId' => 'ADM-001'],
				],
				'Payslip' => [
					['id' => 'ps-1', 'payrollRunId' => 'run-1', 'period' => '2026-01', 'grossPay' => 200000.00, 'wkrUsed' => 0.00],
				],
				'WkrDeclaration' => [
					['id' => 'wd-1', 'administrationId' => 'ADM-001', 'year' => 2026, 'amount' => 300.00, 'wkrCategory' => 'vrije-ruimte'],
				],
			]
		);

		$first = $service->assess('ADM-001', 2026);
		$second = $service->assess('ADM-001', 2026);

		self::assertSame('ok', $first['status']);
		self::assertSame('ok', $second['status']);
		self::assertSame($first['assessment']['id'], $second['assessment']['id']);

		$rows = $fake->rowsBySchema['WkrAssessment'];
		self::assertCount(1, $rows);

	}//end testReassessingSameAdministrationYearIsIdempotent()

	/**
	 * REQ-WKR-005 — `assessAll()` assesses every distinct (administrationId,
	 * year) pair found across the payslips.
	 *
	 * @return void
	 */
	public function testAssessAllIteratesDistinctAdministrationYearPairs(): void {
		[$service] = $this->service(
			[
				'PayrollRun' => [
					['id' => 'run-1', 'administrationId' => 'ADM-001'],
					['id' => 'run-2', 'administrationId' => 'ADM-002'],
				],
				'Payslip' => [
					['id' => 'ps-1', 'payrollRunId' => 'run-1', 'period' => '2026-01', 'grossPay' => 200000.00, 'wkrUsed' => 0.00],
					['id' => 'ps-2', 'payrollRunId' => 'run-2', 'period' => '2026-01', 'grossPay' => 100000.00, 'wkrUsed' => 0.00],
				],
			]
		);

		$outcomes = $service->assessAll();

		self::assertCount(2, $outcomes);
		$pairs = array_map(
			static fn (array $o): string => $o['administrationId'] . '/' . $o['year'],
			$outcomes
		);
		self::assertContains('ADM-001/2026', $pairs);
		self::assertContains('ADM-002/2026', $pairs);
		foreach ($outcomes as $outcome) {
			self::assertSame('ok', $outcome['status']);
		}

	}//end testAssessAllIteratesDistinctAdministrationYearPairs()

	/**
	 * `assess()` refuses a blank administrationId or a non-positive year
	 * without ever reaching the register.
	 *
	 * @return void
	 */
	public function testAssessRefusesInvalidInput(): void {
		[$service] = $this->service([]);

		self::assertSame('failed', $service->assess('', 2026)['status']);
		self::assertSame('failed', $service->assess('ADM-001', 0)['status']);

	}//end testAssessRefusesInvalidInput()

}//end class
