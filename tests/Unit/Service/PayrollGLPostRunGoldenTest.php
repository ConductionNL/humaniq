<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Posting an approved payroll run matches the recorded golden: the draft
 * shillinq JournalEntry (journal number, entry date, description, lines,
 * administration), an adopted JournalEntry found by its journal number, a
 * create that fails, the PayrollGLPost rows and the posted PayrollRun with
 * its GL amounts. Each case records the outcome and every write with its
 * register, schema, uuid and RBAC and multitenancy flags. The service
 * writes as the system (no acting user): every write must carry
 * `_rbac: false`.
 *
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\PayrollGLPostService;
use OCA\Humaniq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Golden test for {@see PayrollGLPostService::postRun()}.
 *
 * @covers \OCA\Humaniq\Service\PayrollGLPostService
 * @uses   \OCA\Humaniq\Support\ConvertsRowsToArrays
 * @uses   \OCA\Humaniq\Service\PayrollJournalTotals
 * @uses   \OCA\Humaniq\Service\PendingClaims
 * @uses   \OCA\Humaniq\Support\ResolvesObjectService
 * @uses   \OCA\Humaniq\Support\NormalisesRows
 * @uses   \OCA\Humaniq\Support\ChecksShillinqAvailability
 */
class PayrollGLPostRunGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/gl-post-run.json';

	/**
	 * Every case produces the recorded outcome and writes.
	 *
	 * @return void
	 */
	public function testPostRunMatchesTheGolden(): void {
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
	}//end testPostRunMatchesTheGolden()

	/**
	 * The cases: run shapes, worlds and settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$run = static fn (array $overrides = []): array => array_merge(
			[
				'id' => 'run-1',
				'period' => '2026-05',
				'administrationId' => 'ADM-001',
				'status' => 'approved',
				'totalGross' => 3800.00,
				'totalEmployerCharges' => 649.80,
				'totalLoonheffing' => 1102.00,
				'totalNet' => 2698.00,
				'@self' => ['id' => 'run-1', 'owner' => 'x'],
			],
			$overrides
		);
		$allocations = [
			['payrollRunId' => 'run-1', 'costCenter' => 'CC-1', 'costCenterCode' => '100', 'amountGross' => 2000.0, 'amountEmployerCharges' => 300.10],
			['payrollRunId' => 'run-1', 'costCenter' => 'CC-2', 'projectId' => 'P-7', 'amountGross' => 1000.005, 'amountEmployerCharges' => 0.1 + 0.2],
			['payrollRunId' => 'run-other', 'costCenter' => 'CC-9', 'amountGross' => 50.0, 'amountEmployerCharges' => 5.0],
		];

		$cases = [];
		foreach (['2026-05', '2026-02', '2028-02', '2026-12', '2026-04'] as $period) {
			$cases['post ' . $period] = ['run' => $run(['period' => $period]), 'rows' => []];
		}

		$cases['with cost allocations'] = ['run' => $run(), 'rows' => ['WageCostAllocation' => $allocations]];
		$cases['padded period and administration'] = ['run' => $run(['period' => ' 2026-05 ', 'administrationId' => ' ADM-001 ']), 'rows' => []];
		$cases['id only in @self'] = ['run' => $run(['id' => null, '@self' => ['id' => 'run-self']]), 'rows' => []];
		$cases['padded id'] = ['run' => $run(['id' => ' run-1 ']), 'rows' => []];
		$cases['other administration'] = ['run' => $run(['administrationId' => 'ADM-777']), 'rows' => []];
		$cases['unparseable period'] = ['run' => $run(['period' => 'mei']), 'rows' => []];
		$cases['padded unparseable period'] = ['run' => $run(['period' => ' mei ']), 'rows' => []];
		$cases['cents rounding'] = ['run' => $run(['totalGross' => 1234.565, 'totalEmployerCharges' => 0.1 + 0.2, 'totalLoonheffing' => 100.004, 'totalNet' => 1000.0]), 'rows' => []];
		$cases['adopts the JournalEntry with the journal number'] = [
			'run' => $run(),
			'rows' => ['JournalEntry' => [['id' => 'je-other', 'journalNumber' => 'HRMQ-LOON-2026-05-ADM-002'], ['id' => 'je-9', 'journalNumber' => 'HRMQ-LOON-2026-05-ADM-001']]],
		];
		$cases['adopts a JournalEntry whose id is in @self'] = [
			'run' => $run(),
			'rows' => ['JournalEntry' => [['@self' => ['id' => 'je-self'], 'journalNumber' => 'HRMQ-LOON-2026-05-ADM-001']]],
		];
		$cases['does not adopt a near journal number'] = ['run' => $run(), 'rows' => ['JournalEntry' => [['id' => 'je-near', 'journalNumber' => 'HRMQ-LOON-2026-05-ADM-001 ']]]];
		$cases['create fails'] = ['run' => $run(), 'rows' => [], 'saveThrows' => true];
		$cases['create answers an entity with its id in @self'] = ['run' => $run(), 'rows' => [], 'entity' => true];
		$cases['stale pending then a fresh create'] = [
			'run' => $run(),
			'rows' => ['PayrollGLPost' => [['id' => 'g-1', 'payrollRunId' => 'run-1', 'status' => 'pending', '@self' => ['id' => 'g-1']]]],
		];
		$cases['stale pending adopts the posted journal'] = [
			'run' => $run(),
			'rows' => [
				'PayrollGLPost' => [['id' => 'g-1', 'payrollRunId' => 'run-1', 'status' => 'pending']],
				'JournalEntry' => [['id' => 'je-9', 'journalNumber' => 'HRMQ-LOON-2026-05-ADM-001']],
			],
		];
		$cases['stale pending with a stored journalNumber, padded period and administration, adopts'] = [
			'run' => $run(['period' => ' 2026-05 ', 'administrationId' => ' ADM-001 ']),
			'rows' => [
				'PayrollGLPost' => [['id' => 'g-3', 'payrollRunId' => 'run-1', 'status' => 'pending', 'journalNumber' => 'HRMQ-LOON-2026-04-ADM-OLD']],
				'JournalEntry' => [['id' => 'je-old', 'journalNumber' => 'HRMQ-LOON-2026-04-ADM-OLD'], ['id' => 'je-9', 'journalNumber' => 'HRMQ-LOON-2026-05-ADM-001']],
			],
		];
		$cases['already posted'] = ['run' => $run(), 'rows' => ['PayrollGLPost' => [['id' => 'g-2', 'payrollRunId' => 'run-1', 'status' => 'posted', 'journalEntryId' => 'je-1']]]];
		$cases['shillinq not installed'] = ['run' => $run(), 'rows' => [], 'installed' => false];
		$cases['inconsistent totals'] = ['run' => $run(['totalNet' => 9999.0]), 'rows' => []];
		$cases['missing administration'] = ['run' => $run(['administrationId' => '  ']), 'rows' => []];

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
			 * @param bool $saveThrows Whether a JournalEntry create throws.
			 * @param bool $entity Whether a JournalEntry create answers an entity.
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
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 * @param string|null $uuid The uuid.
			 * @param bool $_rbac RBAC flag.
			 * @param bool $_multitenancy Multitenancy flag.
			 *
			 * @return mixed
			 */
			public function saveObject(
				array $object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): mixed {
				$recorded = $object;
				foreach (['postedAt'] as $key) {
					if (isset($recorded[$key]) === true) {
						$recorded[$key] = (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string)$recorded[$key]) === 1) ? '<now>' : $recorded[$key];
					}
				}

				$this->writes[] = ['register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'object' => $recorded];
				if ($schema === 'JournalEntry' && $this->saveThrows === true) {
					throw new \RuntimeException('shillinq refused the journal');
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
				if ($schema === 'JournalEntry' && $this->entity === true) {
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
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($store);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(fn (string $app): bool => $app === 'shillinq' && ($case['installed'] ?? true));
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getGlPostAccountGross')->willReturn('4001');
		$settings->method('getGlPostAccountEmployerCharges')->willReturn('4002');
		$settings->method('getGlPostAccountWageTaxLiability')->willReturn('1701');
		$settings->method('getGlPostAccountNetWagesLiability')->willReturn('1702');

		$outcome = (new PayrollGLPostService($container, $appManager, $settings, new NullLogger()))->postRun($case['run']);

		return ['outcome' => $outcome, 'writes' => $store->writes];
	}//end runCase()
}//end class
