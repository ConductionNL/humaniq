<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Processing a payable payroll run matches the recorded golden: the draft
 * shillinq PaymentRun (run number, administration, execution date, total,
 * lines, debtor IBAN), an adopted PaymentRun found by its run number, a
 * create that fails, and the PayrollPaymentBatch rows written around it.
 * Each case records the outcome and every write with its register, schema,
 * uuid and RBAC and multitenancy flags. The service writes as the system
 * (no acting user): every write must carry `_rbac: false`.
 *
 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\PayrollNetPayService;
use OCA\Humaniq\Service\SettingsService;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use OCA\Humaniq\Tests\Unit\Service\Support\InMemoryLocks;
use OCP\Lock\ILockingProvider;

/**
 * Golden test for {@see PayrollNetPayService::processRun()}.
 *
 * @covers \OCA\Humaniq\Service\PayrollNetPayService
 * @uses   \OCA\Humaniq\Support\ConvertsRowsToArrays
 * @uses   \OCA\Humaniq\Service\PendingClaims
 * @uses   \OCA\Humaniq\Service\HandoffLock
 * @uses   \OCA\Humaniq\Service\WagePeriod
 * @uses   \OCA\Humaniq\Support\ResolvesObjectService
 * @uses   \OCA\Humaniq\Support\NormalisesRows
 * @uses   \OCA\Humaniq\Support\ChecksShillinqAvailability
 */
class PayrollNetPayRunGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/netpay-process-run.json';

	/**
	 * Every case produces the recorded outcome and writes.
	 *
	 * @return void
	 */
	public function testProcessRunMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase($case);
		}

		if (getenv('HUMANIQ_GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($golden as $name => $expected) {
			self::assertSame(
				json_encode($expected, JSON_PRESERVE_ZERO_FRACTION),
				json_encode($actual[$name], JSON_PRESERVE_ZERO_FRACTION),
				$name
			);
		}
	}//end testProcessRunMatchesTheGolden()

	/**
	 * The cases: run shapes, worlds and settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$employees = [
			['id' => 'emp-1', 'iban' => 'NL91ABNA0417164300', 'tenaamstelling' => 'J. de Vries'],
			['id' => 'emp-2', 'iban' => ' NL02RABO0123456789 ', 'firstName' => 'Anna', 'lastName' => 'Bakker', 'employeeNumber' => 'E-2'],
			['@self' => ['id' => 'emp-3', 'slug' => 'emp-slug-3'], 'iban' => 'NL20INGB0001234567', 'firstName' => 'Kees'],
		];
		$payslips = static fn (string $period): array => [
			['id' => 'ps-1', 'period' => $period, 'employeeId' => 'emp-1', 'nettoPay' => 2698.0],
			['id' => 'ps-2', 'period' => $period, 'employeeId' => 'E-2', 'nettoPay' => '1234.565'],
			['id' => 'ps-3', 'period' => $period, 'employeeId' => 'emp-slug-3', 'nettoPay' => 0.1 + 0.2],
			['id' => 'ps-4', 'period' => $period, 'employeeId' => 'emp-1', 'nettoPay' => 0],
		];
		$run = static fn (array $overrides = []): array => array_merge(
			['id' => 'run-1', 'period' => '2026-05', 'administrationId' => 'ADM-001', 'status' => 'approved'],
			$overrides
		);

		$cases = [];
		foreach (['2026-05', '2026-02', '2028-02', '2026-12', '2026-04'] as $period) {
			foreach ([25, 31, 1] as $day) {
				$cases['create ' . $period . ' day ' . $day] = [
					'run' => $run(['period' => $period]),
					'rows' => ['Employee' => $employees, 'Payslip' => $payslips($period)],
					'day' => $day,
				];
			}
		}

		$cases['padded period and administration'] = ['run' => $run(['period' => ' 2026-05 ', 'administrationId' => ' ADM-001 ']), 'rows' => ['Employee' => $employees, 'Payslip' => $payslips('2026-05')]];
		$cases['id only in @self'] = ['run' => $run(['id' => null, '@self' => ['id' => 'run-self']]), 'rows' => ['Employee' => $employees, 'Payslip' => $payslips('2026-05')]];
		$cases['other administration'] = ['run' => $run(['administrationId' => 'ADM-777']), 'rows' => ['Employee' => $employees, 'Payslip' => $payslips('2026-05')]];
		$cases['padded unparseable period'] = ['run' => $run(['period' => ' mei ']), 'rows' => ['Employee' => $employees, 'Payslip' => $payslips('mei')]];
		$cases['unparseable period'] = ['run' => $run(['period' => 'mei']), 'rows' => ['Employee' => $employees, 'Payslip' => $payslips('mei')]];
		foreach (['2026-5', '202605', 'now', '2026-00', '2026-13', 'x2026-05', '12026-05', '2026-05x'] as $period) {
			$cases['period ' . $period] = ['run' => $run(['period' => $period]), 'rows' => ['Employee' => $employees, 'Payslip' => $payslips($period)]];
		}

		foreach (['', '   ', 'NL69INGB0123456789', ' NL69INGB0123456789 '] as $i => $iban) {
			$cases['debtor iban ' . $i] = ['run' => $run(), 'rows' => ['Employee' => $employees, 'Payslip' => $payslips('2026-05')], 'iban' => $iban];
		}

		$cases['adopts the PaymentRun with the run number'] = [
			'run' => $run(),
			'rows' => [
				'Employee' => $employees,
				'Payslip' => $payslips('2026-05'),
				'PaymentRun' => [['id' => 'pr-other', 'runNumber' => 'HRMQ-NETPAY-2026-05-ADM-002'], ['id' => 'pr-9', 'runNumber' => 'HRMQ-NETPAY-2026-05-ADM-001']],
			],
		];
		$cases['adopts a PaymentRun whose id is in @self'] = [
			'run' => $run(),
			'rows' => [
				'Employee' => $employees,
				'Payslip' => $payslips('2026-05'),
				'PaymentRun' => [['@self' => ['id' => 'pr-self'], 'runNumber' => 'HRMQ-NETPAY-2026-05-ADM-001']],
			],
		];
		$cases['does not adopt a near run number'] = [
			'run' => $run(),
			'rows' => ['Employee' => $employees, 'Payslip' => $payslips('2026-05'), 'PaymentRun' => [['id' => 'pr-near', 'runNumber' => 'HRMQ-NETPAY-2026-05-ADM-001 ']]],
		];
		$cases['create fails'] = ['run' => $run(), 'rows' => ['Employee' => $employees, 'Payslip' => $payslips('2026-05')], 'saveThrows' => true];
		$cases['create answers an entity with its id in @self'] = ['run' => $run(), 'rows' => ['Employee' => $employees, 'Payslip' => $payslips('2026-05')], 'entity' => true];
		$cases['stale pending then a fresh create'] = [
			'run' => $run(),
			'rows' => ['Employee' => $employees, 'Payslip' => $payslips('2026-05'), 'PayrollPaymentBatch' => [['id' => 'b-1', 'payrollRunId' => 'run-1', 'status' => 'pending', '@self' => ['id' => 'b-1']]]],
		];
		$cases['stale pending adopts the PaymentRun'] = [
			'run' => $run(['period' => ' 2026-05 ']),
			'rows' => [
				'Employee' => $employees,
				'Payslip' => $payslips('2026-05'),
				'PayrollPaymentBatch' => [['id' => 'b-1', 'payrollRunId' => 'run-1', 'status' => 'pending']],
				'PaymentRun' => [['id' => 'pr-9', 'runNumber' => 'HRMQ-NETPAY-2026-05-ADM-001']],
			],
		];
		$cases['stale pending, run id only in @self, adopts'] = [
			'run' => $run(['id' => null, '@self' => ['id' => 'run-self']]),
			'rows' => [
				'PayrollPaymentBatch' => [['id' => 'b-3', 'payrollRunId' => 'run-self', 'status' => 'pending']],
				'PaymentRun' => [['id' => 'pr-9', 'runNumber' => 'HRMQ-NETPAY-2026-05-ADM-001']],
			],
		];
		$cases['stale pending with a different stored runNumber, padded administration, adopts'] = [
			'run' => $run(['administrationId' => ' ADM-001 ']),
			'rows' => [
				'PayrollPaymentBatch' => [['id' => 'b-4', 'payrollRunId' => 'run-1', 'status' => 'pending', 'runNumber' => 'HRMQ-NETPAY-2026-04-ADM-OLD']],
				'PaymentRun' => [['id' => 'pr-old', 'runNumber' => 'HRMQ-NETPAY-2026-04-ADM-OLD'], ['id' => 'pr-9', 'runNumber' => 'HRMQ-NETPAY-2026-05-ADM-001']],
			],
		];
		$cases['already created'] = ['run' => $run(), 'rows' => ['PayrollPaymentBatch' => [['id' => 'b-2', 'payrollRunId' => 'run-1', 'status' => 'created', 'shillinqPaymentRunRef' => 'pr-1']]]];
		$cases['shillinq not installed'] = ['run' => $run(), 'rows' => ['Employee' => $employees, 'Payslip' => $payslips('2026-05')], 'installed' => false];
		$cases['a line error'] = ['run' => $run(), 'rows' => ['Employee' => $employees, 'Payslip' => [['id' => 'ps-x', 'period' => '2026-05', 'employeeId' => 'ghost', 'nettoPay' => 10]]]];
		$cases['missing administration'] = ['run' => $run(['administrationId' => '  ']), 'rows' => ['Employee' => $employees, 'Payslip' => $payslips('2026-05')]];

		return $cases;
	}//end cases()

	/**
	 * Run one case and record its outcome and writes.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$store = new class($case['rows'], (bool)($case['saveThrows'] ?? false), (bool)($case['entity'] ?? false)) {

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @var int
			 */
			private int $nextId = 1;

			/**
			 * @var list<array<string, mixed>>
			 */
			public array $writes = [];

			/**
			 * @param array<string, list<array<string, mixed>>> $rows Seed rows keyed by schema.
			 * @param bool $saveThrows Whether a PaymentRun create throws.
			 * @param bool $entity Whether a PaymentRun create answers an entity.
			 */
			public function __construct(private array $rows, private readonly bool $saveThrows, private readonly bool $entity) {
			}//end __construct()

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
				return $this->rows[$this->schema] ?? [];
			}//end findAll()

			/**
			 * @param array<string, mixed> $object The object.
			 * @param ObjectSaveOptions $options The register, schema, uuid and the rest of the save settings.
			 * @param bool $_rbac RBAC flag.
			 * @param bool $_multitenancy Multitenancy flag.
			 *
			 * @return mixed
			 */
			public function saveObject(
				array $object,
				ObjectSaveOptions $options = new ObjectSaveOptions(),
				bool $_rbac = true,
				bool $_multitenancy = true,
			): mixed {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$recorded = $object;
				foreach (['createdAt'] as $key) {
					if (isset($recorded[$key]) === true) {
						$recorded[$key] = (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string)$recorded[$key]) === 1) ? '<now>' : $recorded[$key];
					}
				}

				$this->writes[] = ['register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'object' => $recorded];
				if ($schema === 'PaymentRun' && $this->saveThrows === true) {
					throw new \RuntimeException('shillinq refused the run');
				}

				$id = ($uuid ?? ($schema . '-new-' . $this->nextId++));
				$saved = array_merge($object, ['id' => $id]);
				$kept = [];
				foreach (($this->rows[(string)$schema] ?? []) as $row) {
					if ((string)($row['id'] ?? $row['@self']['id'] ?? '') !== $id) {
						$kept[] = $row;
					}
				}

				$kept[] = $saved;
				$this->rows[(string)$schema] = $kept;
				if ($schema === 'PaymentRun' && $this->entity === true) {
					unset($saved['id']);
					$saved['@self'] = ['id' => $id];
					return new class($saved) implements \JsonSerializable {

						/**
						 * @param array<string, mixed> $row The row.
						 */
						public function __construct(private readonly array $row) {
						}//end __construct()

						/**
						 * @return array<string, mixed>
						 */
						public function jsonSerialize(): array {
							return $this->row;
						}//end jsonSerialize()

					};
				}

				return $saved;
			}//end saveObject()

		};

		$container = $this->createMock(ContainerInterface::class);
		$locks = new InMemoryLocks();
		$container->method('get')->willReturnCallback(static fn (string $id): object => match ($id) {
			'OCA\OpenRegister\Service\ObjectService' => $store,
			ILockingProvider::class => $locks,
		});
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(fn (string $app): bool => $app === 'shillinq' && ($case['installed'] ?? true));
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getNetPayExecutionDay')->willReturn((int)($case['day'] ?? 25));
		$settings->method('getNetPayDebtorIban')->willReturn((string)($case['iban'] ?? 'NL69INGB0123456789'));

		$outcome = (new PayrollNetPayService($container, $appManager, $settings, new NullLogger()))->processRun($case['run']);

		return ['outcome' => $outcome, 'writes' => $store->writes];
	}//end runCase()
}//end class
