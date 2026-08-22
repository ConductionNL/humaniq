<?php

/**
 * Unit tests for PayrollNetPayService.
 *
 * Pins the payroll-sepa-netpay-shillinq contract: the D2 line-collection math
 * (one line per payslip, employee resolution by id/slug/employeeNumber,
 * tenaamstelling fallback, zero-nettoPay exclusion, cents arithmetic), the
 * fail-closed path on a missing IBAN or negative/non-numeric nettoPay (no
 * partial batch, nothing created in shillinq), the D7 duck-typed skip path
 * when shillinq is absent, and the D6 idempotency pre-check (double
 * invocation, stale-pending recovery, runNumber adoption). Drives the service
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
 * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\PayrollNetPayService;
use OCA\Humaniq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PayrollNetPayService.
 *
 * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md
 */
class PayrollNetPayServiceTest extends TestCase {

	/**
	 * Build a fake ObjectService double: `findAll()` returns the seeded rows
	 * for the current schema, `saveObject()` records every write (assignable
	 * to a generated id when no uuid is given) and reflects it back into the
	 * seeded rows so a subsequent idempotency probe within the same test sees
	 * it. Optionally throws on the `PaymentRun` schema to simulate shillinq
	 * being unavailable (design.md D7).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 * @param bool $shillinqThrows Whether PaymentRun access throws.
	 *
	 * @return object The fake ObjectService.
	 */
	private function fakeObjectService(array $rowsBySchema = [], bool $shillinqThrows = false): object {
		return new class($rowsBySchema, $shillinqThrows) {
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
			 * @param bool $shillinqThrows Whether PaymentRun access throws.
			 */
			public function __construct(
				private array $rowsBySchema,
				private readonly bool $shillinqThrows,
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
			 *
			 * @throws \RuntimeException When simulating shillinq unavailability.
			 */
			public function findAll(array $options = []): array {
				if ($this->schema === 'PaymentRun' && $this->shillinqThrows === true) {
					throw new \RuntimeException('shillinq register unavailable');
				}

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
			 *
			 * @throws \RuntimeException When simulating shillinq unavailability.
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
				if ($targetSchema === 'PaymentRun' && $this->shillinqThrows === true) {
					throw new \RuntimeException('shillinq register unavailable');
				}

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
	 * Build a fully-wired PayrollNetPayService plus its fake ObjectService
	 * double (for assertions on what was saved).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 * @param bool $shillinqThrows Whether PaymentRun access throws.
	 * @param bool $shillinqInstalled Whether IAppManager::isInstalled('shillinq') returns true.
	 * @param string $debtorIban The configured netpay_debtor_iban.
	 *
	 * @return array{0: PayrollNetPayService, 1: object}
	 */
	private function service(array $rowsBySchema = [], bool $shillinqThrows = false, bool $shillinqInstalled = true, string $debtorIban = ''): array {
		$fake = $this->fakeObjectService($rowsBySchema, $shillinqThrows);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($shillinqInstalled);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getNetPayExecutionDay')->willReturn(25);
		$settings->method('getNetPayDebtorIban')->willReturn($debtorIban);

		$logger = $this->createMock(LoggerInterface::class);

		return [new PayrollNetPayService($container, $appManager, $settings, $logger), $fake];
	}//end service()

	/**
	 * The seeded 2026-05 payable-run fixture, overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function payrollRun(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'run-1',
				'period' => '2026-05',
				'administrationId' => 'ADM-001',
				'status' => 'approved',
			],
			$overrides
		);

	}//end payrollRun()

	/**
	 * The seeded Jansen employee (id resolvable, slug resolvable via @self).
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function employee(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'emp-1',
				'@self' => ['slug' => 'employee-jansen'],
				'employeeNumber' => 'EMP-0001',
				'firstName' => 'Sam',
				'lastName' => 'Jansen',
				'iban' => 'NL00BANK0123456789',
				'tenaamstelling' => 'S. Jansen',
			],
			$overrides
		);

	}//end employee()

	/**
	 * The seeded Jansen payslip (employeeId by slug, the hr-seed.json convention).
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function payslip(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'payslip-1',
				'employeeId' => 'employee-jansen',
				'period' => '2026-05',
				'grossPay' => 3800.00,
				'nettoPay' => 2698.00,
			],
			$overrides
		);

	}//end payslip()

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
	 * @return void
	 */
	public function testCollectLinesYieldsOneLinePerPayslipWithTheEmployeesIban(): void {
		$rows = [
			'Employee' => [$this->employee()],
			'Payslip' => [$this->payslip()],
		];
		[$service] = $this->service($rows);

		$collected = $service->collectLines($this->payrollRun());

		$this->assertNull($collected['error']);
		$this->assertCount(1, $collected['lines']);

		$line = $collected['lines'][0];
		$this->assertSame('S. Jansen', $line['payeeName']);
		$this->assertSame('NL00BANK0123456789', $line['creditorIban']);
		$this->assertEqualsWithDelta(2698.00, $line['amount'], 0.001);
		$this->assertSame('Salaris 2026-05', $line['remittanceInfo']);
		$this->assertSame('payslip-1', $line['apTransactionRef']);
		$this->assertSame('emp-1', $line['payeeId']);

		$this->assertEqualsWithDelta(2698.00, $collected['totalAmount'], 0.001);
		$this->assertSame(1, $collected['lineCount']);

	}//end testCollectLinesYieldsOneLinePerPayslipWithTheEmployeesIban()

	/**
	 * @return void
	 */
	public function testCollectLinesResolvesEmployeeByEmployeeNumber(): void {
		$rows = [
			'Employee' => [$this->employee()],
			'Payslip' => [$this->payslip(['employeeId' => 'EMP-0001'])],
		];
		[$service] = $this->service($rows);

		$collected = $service->collectLines($this->payrollRun());

		$this->assertNull($collected['error']);
		$this->assertCount(1, $collected['lines']);
		$this->assertSame('NL00BANK0123456789', $collected['lines'][0]['creditorIban']);

	}//end testCollectLinesResolvesEmployeeByEmployeeNumber()

	/**
	 * @return void
	 */
	public function testCollectLinesResolvesEmployeeById(): void {
		$rows = [
			'Employee' => [$this->employee()],
			'Payslip' => [$this->payslip(['employeeId' => 'emp-1'])],
		];
		[$service] = $this->service($rows);

		$collected = $service->collectLines($this->payrollRun());

		$this->assertNull($collected['error']);
		$this->assertCount(1, $collected['lines']);

	}//end testCollectLinesResolvesEmployeeById()

	/**
	 * @return void
	 */
	public function testCollectLinesFallsBackToFirstAndLastNameWhenTenaamstellingAbsent(): void {
		$employee = $this->employee(['tenaamstelling' => null]);
		$rows = [
			'Employee' => [$employee],
			'Payslip' => [$this->payslip()],
		];
		[$service] = $this->service($rows);

		$collected = $service->collectLines($this->payrollRun());

		$this->assertNull($collected['error']);
		$this->assertSame('Sam Jansen', $collected['lines'][0]['payeeName']);

	}//end testCollectLinesFallsBackToFirstAndLastNameWhenTenaamstellingAbsent()

	/**
	 * @return void
	 */
	public function testCollectLinesExcludesZeroNettoPayWithoutError(): void {
		$rows = [
			'Employee' => [$this->employee()],
			'Payslip' => [$this->payslip(['nettoPay' => 0.0])],
		];
		[$service] = $this->service($rows);

		$collected = $service->collectLines($this->payrollRun());

		// An empty line set (all payslips excluded) is itself a failed batch
		// ("nothing to pay") -- design.md D2.
		$this->assertNotNull($collected['error']);
		$this->assertSame([], $collected['lines']);

	}//end testCollectLinesExcludesZeroNettoPayWithoutError()

	/**
	 * @return void
	 */
	public function testCollectLinesFailsClosedOnMissingIban(): void {
		$employeeNoIban = $this->employee(['iban' => null]);
		$rows = [
			'Employee' => [$employeeNoIban],
			'Payslip' => [$this->payslip()],
		];
		[$service] = $this->service($rows);

		$collected = $service->collectLines($this->payrollRun());

		$this->assertNotNull($collected['error']);
		$this->assertSame([], $collected['lines']);
		$this->assertStringContainsString('IBAN', $collected['error']);

	}//end testCollectLinesFailsClosedOnMissingIban()

	/**
	 * @return void
	 */
	public function testCollectLinesFailsClosedOnUnresolvableEmployee(): void {
		$rows = [
			'Employee' => [],
			'Payslip' => [$this->payslip()],
		];
		[$service] = $this->service($rows);

		$collected = $service->collectLines($this->payrollRun());

		$this->assertNotNull($collected['error']);
		$this->assertStringContainsString('niet gevonden', $collected['error']);

	}//end testCollectLinesFailsClosedOnUnresolvableEmployee()

	/**
	 * @return void
	 */
	public function testCollectLinesFailsClosedOnNegativeNettoPay(): void {
		$rows = [
			'Employee' => [$this->employee()],
			'Payslip' => [$this->payslip(['nettoPay' => -100.0])],
		];
		[$service] = $this->service($rows);

		$collected = $service->collectLines($this->payrollRun());

		$this->assertNotNull($collected['error']);

	}//end testCollectLinesFailsClosedOnNegativeNettoPay()

	/**
	 * @return void
	 */
	public function testCollectLinesFailsClosedOnNonNumericNettoPay(): void {
		$rows = [
			'Employee' => [$this->employee()],
			'Payslip' => [$this->payslip(['nettoPay' => 'oops'])],
		];
		[$service] = $this->service($rows);

		$collected = $service->collectLines($this->payrollRun());

		$this->assertNotNull($collected['error']);

	}//end testCollectLinesFailsClosedOnNonNumericNettoPay()

	/**
	 * One missing IBAN among two payslips fails the WHOLE batch: no partial
	 * batch (REQ-PNP-003).
	 *
	 * @return void
	 */
	public function testCollectLinesFailsWholeBatchWhenAnyLineErrors(): void {
		$rows = [
			'Employee' => [
				$this->employee(),
				$this->employee(['id' => 'emp-2', '@self' => ['slug' => 'employee-devries'], 'employeeNumber' => 'EMP-0002', 'iban' => null]),
			],
			'Payslip' => [
				$this->payslip(),
				$this->payslip(['id' => 'payslip-2', 'employeeId' => 'employee-devries']),
			],
		];
		[$service] = $this->service($rows);

		$collected = $service->collectLines($this->payrollRun());

		$this->assertNotNull($collected['error']);
		$this->assertSame([], $collected['lines']);

	}//end testCollectLinesFailsWholeBatchWhenAnyLineErrors()

	/**
	 * @return void
	 */
	public function testProcessRunCreatesTheDraftPaymentRunAndTheBatch(): void {
		$rows = [
			'Employee' => [$this->employee()],
			'Payslip' => [$this->payslip()],
		];
		[$service, $fake] = $this->service($rows, false, true, 'NL91BANK9999999999');

		$result = $service->processRun($this->payrollRun());

		$this->assertSame('created', $result['status']);
		$this->assertNotNull($result['paymentRunId']);

		$paymentRunSaves = $this->savedFor($fake, 'PaymentRun');
		$this->assertCount(1, $paymentRunSaves);
		$this->assertSame('HRMQ-NETPAY-2026-05-ADM-001', $paymentRunSaves[0]['runNumber']);
		$this->assertSame('draft', $paymentRunSaves[0]['status']);
		$this->assertSame('draft', $paymentRunSaves[0]['lifecycleState']);
		$this->assertSame('EUR', $paymentRunSaves[0]['currency']);
		$this->assertSame('ADM-001', $paymentRunSaves[0]['administrationId']);
		$this->assertSame('NL91BANK9999999999', $paymentRunSaves[0]['debtorAccountIban']);
		$this->assertCount(1, $paymentRunSaves[0]['paymentLines']);
		$this->assertSame('2026-05-25', $paymentRunSaves[0]['executionDate']);

		$batchSaves = $this->savedFor($fake, 'PayrollPaymentBatch');
		$this->assertCount(1, $batchSaves);
		$this->assertSame('created', $batchSaves[0]['status']);
		$this->assertEqualsWithDelta(2698.00, $batchSaves[0]['totalAmount'], 0.001);
		$this->assertSame(1, $batchSaves[0]['lineCount']);

		// humaniq writes NOTHING back to the PayrollRun (design.md D4).
		$this->assertCount(0, $this->savedFor($fake, 'PayrollRun'));

	}//end testProcessRunCreatesTheDraftPaymentRunAndTheBatch()

	/**
	 * @return void
	 */
	public function testProcessRunOmitsDebtorIbanWhenNotConfigured(): void {
		$rows = [
			'Employee' => [$this->employee()],
			'Payslip' => [$this->payslip()],
		];
		[$service, $fake] = $this->service($rows);

		$service->processRun($this->payrollRun());

		$paymentRunSaves = $this->savedFor($fake, 'PaymentRun');
		$this->assertArrayNotHasKey('debtorAccountIban', $paymentRunSaves[0]);

	}//end testProcessRunOmitsDebtorIbanWhenNotConfigured()

	/**
	 * @return void
	 */
	public function testProcessRunFailsClosedWithoutTouchingShillinqOnMissingIban(): void {
		$rows = [
			'Employee' => [$this->employee(['iban' => null])],
			'Payslip' => [$this->payslip()],
		];
		[$service, $fake] = $this->service($rows);

		$result = $service->processRun($this->payrollRun());

		$this->assertSame('failed', $result['status']);
		$this->assertCount(0, $this->savedFor($fake, 'PaymentRun'));

		$batchSaves = $this->savedFor($fake, 'PayrollPaymentBatch');
		$this->assertCount(1, $batchSaves);
		$this->assertSame('failed', $batchSaves[0]['status']);
		$this->assertNotEmpty($batchSaves[0]['errorMessage']);

	}//end testProcessRunFailsClosedWithoutTouchingShillinqOnMissingIban()

	/**
	 * @return void
	 */
	public function testProcessRunRecordsSkippedNoShillinqWhenNotInstalled(): void {
		[$service, $fake] = $this->service([], false, false);

		$result = $service->processRun($this->payrollRun());

		$this->assertSame('skipped-no-shillinq', $result['status']);
		$this->assertCount(0, $this->savedFor($fake, 'PaymentRun'));

		$batchSaves = $this->savedFor($fake, 'PayrollPaymentBatch');
		$this->assertCount(1, $batchSaves);
		$this->assertSame('skipped-no-shillinq', $batchSaves[0]['status']);

	}//end testProcessRunRecordsSkippedNoShillinqWhenNotInstalled()

	/**
	 * @return void
	 */
	public function testProcessRunRecordsSkippedNoShillinqWhenRegisterUnresolvable(): void {
		[$service, $fake] = $this->service([], true);

		$result = $service->processRun($this->payrollRun());

		$this->assertSame('skipped-no-shillinq', $result['status']);
		$this->assertCount(0, $this->savedFor($fake, 'PaymentRun'));

	}//end testProcessRunRecordsSkippedNoShillinqWhenRegisterUnresolvable()

	/**
	 * @return void
	 */
	public function testProcessRunIsIdempotentOnDoubleInvocation(): void {
		$rows = [
			'Employee' => [$this->employee()],
			'Payslip' => [$this->payslip()],
		];
		[$service, $fake] = $this->service($rows);
		$run = $this->payrollRun();

		$first = $service->processRun($run);
		$second = $service->processRun($run);

		$this->assertSame('created', $first['status']);
		$this->assertSame('created', $second['status']);
		$this->assertSame($first['paymentRunId'], $second['paymentRunId']);

		// Exactly one shillinq PaymentRun ever gets created, despite two invocations.
		$this->assertCount(1, $this->savedFor($fake, 'PaymentRun'));

	}//end testProcessRunIsIdempotentOnDoubleInvocation()

	/**
	 * @return void
	 */
	public function testProcessRunAdoptsExistingPaymentRunByNumberInsteadOfDuplicating(): void {
		$rows = [
			'Employee' => [$this->employee()],
			'Payslip' => [$this->payslip()],
			'PaymentRun' => [
				['id' => 'pr-existing', 'runNumber' => 'HRMQ-NETPAY-2026-05-ADM-001'],
			],
		];
		[$service, $fake] = $this->service($rows);

		$result = $service->processRun($this->payrollRun());

		$this->assertSame('created', $result['status']);
		$this->assertSame('pr-existing', $result['paymentRunId']);
		$this->assertCount(0, $this->savedFor($fake, 'PaymentRun'));

	}//end testProcessRunAdoptsExistingPaymentRunByNumberInsteadOfDuplicating()

	/**
	 * @return void
	 */
	public function testStalePendingBatchIsSupersededThenAFreshAttemptSucceeds(): void {
		$rows = [
			'Employee' => [$this->employee()],
			'Payslip' => [$this->payslip()],
			'PayrollPaymentBatch' => [
				['id' => 'pb-1', 'payrollRunId' => 'run-1', 'period' => '2026-05', 'status' => 'pending'],
			],
		];
		[$service, $fake] = $this->service($rows);

		$result = $service->processRun($this->payrollRun());

		$this->assertSame('created', $result['status']);

		$batchSaves = $this->savedFor($fake, 'PayrollPaymentBatch');
		$statuses = array_column($batchSaves, 'status');
		$this->assertContains('failed', $statuses);
		$this->assertContains('created', $statuses);

	}//end testStalePendingBatchIsSupersededThenAFreshAttemptSucceeds()

	/**
	 * @return void
	 */
	public function testStalePendingBatchAdoptsExistingPaymentRunOnCrashRecovery(): void {
		$rows = [
			'PaymentRun' => [
				['id' => 'pr-existing', 'runNumber' => 'HRMQ-NETPAY-2026-05-ADM-001'],
			],
			'PayrollPaymentBatch' => [
				['id' => 'pb-1', 'payrollRunId' => 'run-1', 'period' => '2026-05', 'status' => 'pending'],
			],
		];
		[$service, $fake] = $this->service($rows);

		$result = $service->processRun($this->payrollRun());

		$this->assertSame('created', $result['status']);
		$this->assertSame('pr-existing', $result['paymentRunId']);

		// Adoption never creates a second PaymentRun.
		$this->assertCount(0, $this->savedFor($fake, 'PaymentRun'));

		$batchSaves = $this->savedFor($fake, 'PayrollPaymentBatch');
		$this->assertCount(1, $batchSaves);
		$this->assertSame('created', $batchSaves[0]['status']);
		$this->assertSame('pr-existing', $batchSaves[0]['shillinqPaymentRunRef']);

	}//end testStalePendingBatchAdoptsExistingPaymentRunOnCrashRecovery()

	/**
	 * @return void
	 */
	public function testAlreadyCreatedBatchIsANoOp(): void {
		$rows = [
			'PayrollPaymentBatch' => [
				['id' => 'pb-1', 'payrollRunId' => 'run-1', 'period' => '2026-05', 'status' => 'created', 'shillinqPaymentRunRef' => 'pr-1'],
			],
		];
		[$service, $fake] = $this->service($rows);

		$result = $service->processRun($this->payrollRun());

		$this->assertSame('created', $result['status']);
		$this->assertCount(0, $this->savedFor($fake, 'PaymentRun'));
		$this->assertCount(0, $this->savedFor($fake, 'PayrollPaymentBatch'));

	}//end testAlreadyCreatedBatchIsANoOp()

	/**
	 * @return void
	 */
	public function testSkippedNoShillinqIsSupersededByASuccessfulRetryOnceShillinqIsInstalled(): void {
		// First invocation: shillinq absent -> skipped-no-shillinq, run stays payable.
		[$serviceWithoutShillinq, $fakeWithoutShillinq] = $this->service([], false, false);
		$first = $serviceWithoutShillinq->processRun($this->payrollRun());

		$this->assertSame('skipped-no-shillinq', $first['status']);

		// Second invocation, same batch history carried over: shillinq is now
		// installed, so the retry supersedes the skip and creates successfully
		// (design.md D6/D7 -- a skip must not become permanent).
		$rowsBySchema = [
			'PayrollPaymentBatch' => $this->savedFor($fakeWithoutShillinq, 'PayrollPaymentBatch'),
			'Employee' => [$this->employee()],
			'Payslip' => [$this->payslip()],
		];
		[$serviceWithShillinq, $fakeWithShillinq] = $this->service($rowsBySchema, false, true);
		$second = $serviceWithShillinq->processRun($this->payrollRun());

		$this->assertSame('created', $second['status']);
		$this->assertCount(1, $this->savedFor($fakeWithShillinq, 'PaymentRun'));

	}//end testSkippedNoShillinqIsSupersededByASuccessfulRetryOnceShillinqIsInstalled()

	/**
	 * @return void
	 */
	public function testProcessPayableRunsSelectsApprovedAndPostedRunsForTheGivenPeriod(): void {
		$rows = [
			'PayrollRun' => [
				$this->payrollRun(['id' => 'run-a', 'period' => '2026-04']),
				$this->payrollRun(['id' => 'run-b', 'period' => '2026-05', 'status' => 'approved']),
				$this->payrollRun(['id' => 'run-c', 'period' => '2026-05', 'status' => 'posted']),
				$this->payrollRun(['id' => 'run-d', 'period' => '2026-05', 'status' => 'draft']),
			],
		];
		[$service] = $this->service($rows);

		$results = $service->processPayableRuns('2026-05');

		$this->assertCount(2, $results);
		$runIds = array_column($results, 'runId');
		$this->assertContains('run-b', $runIds);
		$this->assertContains('run-c', $runIds);

	}//end testProcessPayableRunsSelectsApprovedAndPostedRunsForTheGivenPeriod()

}//end class
