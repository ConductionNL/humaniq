<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two processes handing the same payroll run to shillinq at the same time
 * create one PaymentRun and one JournalEntry, not two. The fake register
 * runs a second process at the moment the first one is about to write
 * shillinq, or right after the first one read the run's batches, and the
 * test counts what reached shillinq.
 *
 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\PayrollGLPostService;
use OCA\Humaniq\Service\PayrollNetPayService;
use OCA\Humaniq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Concurrency of the net pay and GL post hand-offs.
 *
 * @covers \OCA\Humaniq\Service\PayrollNetPayService
 * @covers \OCA\Humaniq\Service\PayrollGLPostService
 * @uses   \OCA\Humaniq\Service\PayrollJournalTotals
 * @uses   \OCA\Humaniq\Service\PendingClaims
 */
class ShillinqHandoffConcurrencyTest extends TestCase {

	/**
	 * The two hand-offs: the service, its shillinq schema, its own record
	 * schema and the method that hands a run over.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function handoffs(): array {
		return [
			'net pay' => ['netpay', 'PaymentRun', 'PayrollPaymentBatch', 'processRun'],
			'GL post' => ['glpost', 'JournalEntry', 'PayrollGLPost', 'postRun'],
		];
	}//end handoffs()

	/**
	 * A second process that starts while the first is about to write
	 * shillinq finds the first one's fresh claim and writes nothing.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testASecondProcessDuringTheShillinqWriteCreatesNothing(string $kind, string $target, string $ownSchema, string $handOff): void {
		$store = $this->store();
		$second = null;
		$store->beforeSave = function (string $schema) use ($store, $kind, $target, $handOff, &$second): void {
			if ($schema === $target && $second === null) {
				$second = 'running';
				$second = $this->service($kind, $store)->$handOff($this->payrollRun());
			}
		};

		$first = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertCount(1, $store->savesOf($target), 'One record reaches shillinq.');
		$this->assertSame('pending', $second['status']);
		$this->assertContains($first['status'], ['created', 'posted']);
	}//end testASecondProcessDuringTheShillinqWriteCreatesNothing()

	/**
	 * A second process that read the run's records before the first one
	 * claimed it, and finishes first, makes the first one withdraw its claim.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testAClaimNextToAnotherActiveRecordWithdraws(string $kind, string $target, string $ownSchema, string $handOff): void {
		$store = $this->store();
		$ran = false;
		$store->afterFindAll = function (string $schema) use ($store, $kind, $ownSchema, $handOff, &$ran): void {
			if ($schema === $ownSchema && $ran === false) {
				$ran = true;
				$this->service($kind, $store)->$handOff($this->payrollRun());
			}
		};

		$first = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertCount(1, $store->savesOf($target), 'One record reaches shillinq.');
		$this->assertSame('pending', $first['status']);
		$own = $store->savesOf($ownSchema);
		$this->assertSame('failed', end($own)['status'], 'The withdrawn claim is closed as failed.');
	}//end testAClaimNextToAnotherActiveRecordWithdraws()

	/**
	 * A pending record older than the claim window is a crash left behind:
	 * it is superseded and the run is handed over.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testAStalePendingRecordDoesNotBlockTheRun(string $kind, string $target, string $ownSchema, string $handOff): void {
		$store = $this->store();
		$store->rows[$ownSchema] = [['id' => 'old', 'payrollRunId' => 'run-1', 'status' => 'pending', '@self' => ['id' => 'old', 'updated' => gmdate('c', time() - 3600)]]];

		$outcome = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertContains($outcome['status'], ['created', 'posted']);
		$this->assertCount(1, $store->savesOf($target));
		$this->assertSame('failed', $store->savesOf($ownSchema)[0]['status']);
	}//end testAStalePendingRecordDoesNotBlockTheRun()

	/**
	 * A fresh pending record of another process blocks the run without a
	 * write.
	 *
	 * @param string $kind       Which service.
	 * @param string $target     The shillinq schema.
	 * @param string $ownSchema  The service's own record schema.
	 * @param string $handOff    The hand-off method.
	 *
	 * @return void
	 *
	 * @dataProvider handoffs
	 */
	public function testAFreshPendingRecordBlocksTheRun(string $kind, string $target, string $ownSchema, string $handOff): void {
		$store = $this->store();
		$store->rows[$ownSchema] = [['id' => 'busy', 'payrollRunId' => 'run-1', 'status' => 'pending', '@self' => ['id' => 'busy', 'updated' => gmdate('c', time() - 60)]]];

		$outcome = $this->service($kind, $store)->$handOff($this->payrollRun());

		$this->assertSame('pending', $outcome['status']);
		$this->assertSame([], $store->saves);
	}//end testAFreshPendingRecordBlocksTheRun()

	/**
	 * The payroll run, approved, with totals and one payslip's period.
	 *
	 * @return array<string, mixed>
	 */
	private function payrollRun(): array {
		return [
			'id' => 'run-1',
			'period' => '2026-05',
			'administrationId' => 'ADM-001',
			'status' => 'approved',
			'totalGross' => 3800.00,
			'totalEmployerCharges' => 649.80,
			'totalLoonheffing' => 1102.00,
			'totalNet' => 2698.00,
		];
	}//end payrollRun()

	/**
	 * A shared in-memory register with hooks before a save and after a read.
	 * Every saved row carries `@self.updated`, as OpenRegister's do.
	 *
	 * @return object
	 */
	private function store(): object {
		return new class {

			/**
			 * @var array<string, list<array<string, mixed>>>
			 */
			public array $rows = [
				'Employee' => [['id' => 'emp-1', 'iban' => 'NL91ABNA0417164300', 'tenaamstelling' => 'J. de Vries']],
				'Payslip' => [['id' => 'ps-1', 'period' => '2026-05', 'employeeId' => 'emp-1', 'nettoPay' => 2698.0]],
			];

			/**
			 * @var list<array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * @var callable|null
			 */
			public $beforeSave = null;

			/**
			 * @var callable|null
			 */
			public $afterFindAll = null;

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @var int
			 */
			private int $nextId = 1;

			/**
			 * @param string $register The register.
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
			 * @param array<string, mixed> $options Query options.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				$schema = $this->schema;
				$rows = ($this->rows[$schema] ?? []);
				if ($this->afterFindAll !== null) {
					($this->afterFindAll)($schema);
				}

				return $rows;
			}//end findAll()

			/**
			 * @param array<string, mixed> $object The object.
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 * @param string|null $uuid The uuid.
			 * @param bool $_rbac RBAC flag.
			 * @param bool $_multitenancy Multitenancy flag.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->beforeSave !== null) {
					($this->beforeSave)((string)$schema);
				}

				$id = ($uuid ?? ($schema . '-' . $this->nextId++));
				$saved = array_merge($object, ['id' => $id, '@self' => ['id' => $id, 'updated' => gmdate('c')]]);
				$this->saves[] = ['schema' => $schema] + $saved;
				$rows = array_values(array_filter(($this->rows[(string)$schema] ?? []), static fn (array $row): bool => ($row['id'] ?? null) !== $id));
				$rows[] = $saved;
				$this->rows[(string)$schema] = $rows;

				return $saved;
			}//end saveObject()

			/**
			 * The saves of one schema, in order.
			 *
			 * @param string $schema The schema.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function savesOf(string $schema): array {
				return array_values(array_filter($this->saves, static fn (array $save): bool => $save['schema'] === $schema));
			}//end savesOf()

		};
	}//end store()

	/**
	 * One process's service over the shared register.
	 *
	 * @param string $kind  `netpay` or `glpost`.
	 * @param object $store The shared register.
	 *
	 * @return object
	 */
	private function service(string $kind, object $store): object {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getNetPayExecutionDay')->willReturn(25);
		$settings->method('getNetPayDebtorIban')->willReturn('');
		$settings->method('getGlPostAccountGross')->willReturn('4001');
		$settings->method('getGlPostAccountEmployerCharges')->willReturn('4002');
		$settings->method('getGlPostAccountWageTaxLiability')->willReturn('1701');
		$settings->method('getGlPostAccountNetWagesLiability')->willReturn('1702');

		if ($kind === 'netpay') {
			return new PayrollNetPayService($container, $apps, $settings, new NullLogger());
		}

		return new PayrollGLPostService($container, $apps, $settings, new NullLogger());
	}//end service()
}//end class
