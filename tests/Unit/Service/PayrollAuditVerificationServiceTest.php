<?php

/**
 * Unit tests for PayrollAuditVerificationService (audit-trail-payroll
 * REQ-AUDP-003, fixing hrmq#98).
 *
 * Pins the "reuse, never reimplement" contract: `verifyRun()` resolves the
 * audit-trail row id range covering a PayrollRun's and its Payslips' rows via
 * `AuditHandler::getLogs()` (the per-object read path — see the service's own
 * class docblock for why `AuditQueryService::query()`, this change's
 * design.md's original pointer, does NOT work here: it searches business
 * objects in a register/schema that LOOKS LIKE an audit schema by naming
 * convention, not the `openregister_audit_trails` table `PayrollRun`/
 * `Payslip` rows actually live in), then hands the resolved `[min, max]`
 * range straight to `AuditHashService::verifyChain()` UNMODIFIED. Drives the
 * service through fake ObjectService/AuditHandler/AuditHashService doubles
 * (fake collaborators, not fakes of the logic under test) since the real
 * OpenRegister services are a sibling-app dependency not available in this
 * standalone suite — the PayrollRunServiceTest precedent.
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
 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\PayrollAuditVerificationService;
use OCA\Humaniq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PayrollAuditVerificationService.
 *
 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-003
 */
class PayrollAuditVerificationServiceTest extends TestCase {

	/**
	 * A fake `AuditTrail`-shaped entry: only `getId()` is exercised by the
	 * service under test.
	 *
	 * @param int $id The row id.
	 *
	 * @return object
	 */
	private function fakeEntry(int $id): object {
		return new class($id) {
			/**
			 * @param int $id The row id.
			 */
			public function __construct(
				private int $id,
			) {
			}

			/**
			 * @return int
			 */
			public function getId(): int {
				return $this->id;
			}//end getId()
		};

	}//end fakeEntry()

	/**
	 * A fake `AuditHandler`: `getLogs($uuid)` returns whatever entries were
	 * seeded for that uuid.
	 *
	 * @param array<string, array<int, object>> $logsByUuid Fake `AuditTrail[]` keyed by object uuid.
	 *
	 * @return object
	 */
	private function fakeAuditHandler(array $logsByUuid): object {
		return new class($logsByUuid) {
			/**
			 * @param array<string, array<int, object>> $logsByUuid Fake entries keyed by uuid.
			 */
			public function __construct(
				private array $logsByUuid,
			) {
			}

			/**
			 * @param string $uuid Object uuid.
			 * @param array<string, mixed> $filters Unused by the fake.
			 *
			 * @return array<int, object>
			 */
			public function getLogs(string $uuid, array $filters = []): array {
				return $this->logsByUuid[$uuid] ?? [];
			}//end getLogs()
		};

	}//end fakeAuditHandler()

	/**
	 * A fake `AuditHashService`: `verifyChain()` records every call and
	 * returns the seeded result.
	 *
	 * @param array<string, mixed> $result The result `verifyChain()` should return.
	 *
	 * @return object
	 */
	private function fakeAuditHashService(array $result): object {
		return new class($result) {
			/**
			 * Every `verifyChain()` call, as `[from, to]`.
			 *
			 * @var array<int, array{0: int|null, 1: int|null}>
			 */
			public array $calls = [];

			/**
			 * @param array<string, mixed> $result The result to return.
			 */
			public function __construct(
				private array $result,
			) {
			}

			/**
			 * @param int|null $from Start row id.
			 * @param int|null $to End row id.
			 *
			 * @return array<string, mixed>
			 */
			public function verifyChain(?int $from = null, ?int $to = null): array {
				$this->calls[] = [$from, $to];
				return $this->result;
			}//end verifyChain()
		};

	}//end fakeAuditHashService()

	/**
	 * A fake `ObjectService`: `findAll()` returns the seeded rows for the
	 * current schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return object
	 */
	private function fakeObjectService(array $rowsBySchema): object {
		return new class($rowsBySchema) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
			 */
			public function __construct(
				private array $rowsBySchema,
			) {
			}

			/**
			 * @param string $register Unused by the fake.
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
			 * @param array<string, mixed> $options Unused by the fake.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()
		};

	}//end fakeObjectService()

	/**
	 * Build a fully-wired PayrollAuditVerificationService plus its
	 * AuditHashService fake (for call assertions).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed PayrollRun/Payslip rows.
	 * @param array<string, array<int, object>> $logsByUuid Fake audit entries keyed by object uuid.
	 * @param array<string, mixed> $chainResult The `verifyChain()` result to seed.
	 *
	 * @return array{0: PayrollAuditVerificationService, 1: object}
	 */
	private function service(array $rowsBySchema, array $logsByUuid, array $chainResult): array {
		$auditHandler = $this->fakeAuditHandler($logsByUuid);
		$auditHashService = $this->fakeAuditHashService($chainResult);
		$objectService = $this->fakeObjectService($rowsBySchema);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $auditHandler, $auditHashService) {
				return match ($id) {
					'OCA\OpenRegister\Service\ObjectService' => $objectService,
					'OCA\OpenRegister\Service\Object\AuditHandler' => $auditHandler,
					'OCA\OpenRegister\Service\AuditHashService' => $auditHashService,
					default => null,
				};
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);

		return [new PayrollAuditVerificationService($container, $settings, $logger), $auditHashService];
	}//end service()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-003
	 */
	public function testVerifyRunResolvesTheFullRowRangeAndDelegatesToAuditHashServiceUnmodified(): void {
		[$service, $auditHashService] = $this->service(
			[
				'PayrollRun' => [['id' => 'run-1', 'period' => '2026-02']],
				'Payslip' => [
					['id' => 'ps-1', 'payrollRunId' => 'run-1'],
					['id' => 'ps-2', 'payrollRunId' => 'run-1'],
					['id' => 'ps-3', 'payrollRunId' => 'run-OTHER'],
				],
			],
			[
				'run-1' => [$this->fakeEntry(50), $this->fakeEntry(10)],
				'ps-1' => [$this->fakeEntry(11), $this->fakeEntry(12)],
				'ps-2' => [$this->fakeEntry(51)],
			],
			['valid' => true, 'entriesVerified' => 4, 'brokenAt' => null]
		);

		$result = $service->verifyRun('run-1');

		// min(10,50,11,12,51) = 10, max = 51 -- ps-3's rows (a DIFFERENT run)
		// are never included.
		$this->assertSame([[10, 51]], $auditHashService->calls);
		$this->assertSame('run-1', $result['runId']);
		$this->assertTrue($result['valid']);
		$this->assertSame(4, $result['entriesVerified']);
		$this->assertNull($result['brokenAt']);

	}//end testVerifyRunResolvesTheFullRowRangeAndDelegatesToAuditHashServiceUnmodified()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-003
	 */
	public function testTamperedRowIsSurfacedUnmodifiedFromAuditHashService(): void {
		[$service] = $this->service(
			[
				'PayrollRun' => [['id' => 'run-1', 'period' => '2026-02']],
				'Payslip' => [['id' => 'ps-1', 'payrollRunId' => 'run-1']],
			],
			[
				'run-1' => [$this->fakeEntry(10)],
				'ps-1' => [$this->fakeEntry(11)],
			],
			['valid' => false, 'entriesVerified' => 1, 'brokenAt' => 11]
		);

		$result = $service->verifyRun('run-1');

		$this->assertFalse($result['valid']);
		$this->assertSame(11, $result['brokenAt']);
		$this->assertSame('run-1', $result['runId']);

	}//end testTamperedRowIsSurfacedUnmodifiedFromAuditHashService()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-003
	 */
	public function testUnknownRunReturnsAnErrorWithoutCallingAuditHashService(): void {
		[$service, $auditHashService] = $this->service(
			['PayrollRun' => [], 'Payslip' => []],
			[],
			['valid' => true, 'entriesVerified' => 0, 'brokenAt' => null]
		);

		$result = $service->verifyRun('does-not-exist');

		$this->assertFalse($result['valid']);
		$this->assertArrayHasKey('error', $result);
		$this->assertSame([], $auditHashService->calls);

	}//end testUnknownRunReturnsAnErrorWithoutCallingAuditHashService()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-003
	 */
	public function testNoAuditRowsYetIsVacuouslyValid(): void {
		[$service, $auditHashService] = $this->service(
			['PayrollRun' => [['id' => 'run-1', 'period' => '2026-02']], 'Payslip' => []],
			[],
			['valid' => true, 'entriesVerified' => 0, 'brokenAt' => null]
		);

		$result = $service->verifyRun('run-1');

		$this->assertTrue($result['valid']);
		$this->assertSame(0, $result['entriesVerified']);
		$this->assertSame([], $auditHashService->calls);

	}//end testNoAuditRowsYetIsVacuouslyValid()

	/**
	 * The "reuse, not reimplement" guard (tasks.md 3.2): no hash-computation
	 * or chain-storage literal exists in this service — every hash operation
	 * is a call into `AuditHashService`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-003
	 */
	public function testNoBespokeHashComputationExistsInThisService(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/Service/PayrollAuditVerificationService.php');

		$this->assertStringNotContainsString('hash(', $source);
		$this->assertStringNotContainsString('sha256', $source);
		$this->assertStringNotContainsString('SHA256', $source);
		$this->assertStringContainsString('AuditHashService', $source);

	}//end testNoBespokeHashComputationExistsInThisService()

}//end class
