<?php

/**
 * Golden test for RetroAdjustmentService's corrections.
 *
 * Runs adjustFor() and recomputeAdjustment() over fixed employees,
 * contracts, payroll runs, sealed payslips and stored adjustments, with the
 * real PayrollCalculator and the nl-2026 tables, against a recording
 * ObjectService and logger. The cases take every exit of a correction: a
 * malformed period, a blank employee or reference, a missing, zero or
 * negative corrected gross, a missing payslip, run or employee, a draft
 * original run, missing historical tables, an invalid tax table colour, no
 * covering contract, no draft run to settle into, a failed save, and the
 * computed, applied, idempotent, back-payment and recovery outcomes, on
 * white and green tables, with and without the heffingskorting, a stamped
 * and a resolved Awf tariff, and a recompute of a draft, an applied and an
 * unknown adjustment. Every outcome, every saved payload and every log
 * line is compared with tests/fixtures/golden/retro-adjustment.json. Set
 * RECORD_GOLDEN=1 to rewrite that file.
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
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Service\EmployerPremiumSettings;
use OCA\Humaniq\Service\RetroAdjustmentService;
use OCA\Humaniq\Service\RetroCorrection;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Golden cases for retro payroll corrections. No coverage annotations, like
 * RetroAdjustmentServiceTest: a case runs the whole payroll engine.
 *
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
 */
class RetroAdjustmentGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/retro-adjustment.json';

	/**
	 * Every save and log line of the current case, in order.
	 *
	 * @var list<mixed>
	 */
	public array $events = [];

	/**
	 * The golden cases.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = array_keys(self::scenarios());
		return array_combine($names, array_map(static fn (string $n): array => [$n], $names));

	}//end cases()

	/**
	 * Run one case and compare it with the golden file.
	 *
	 * @param string $case The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testGolden(string $case): void {
		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		$actual = json_encode($this->free(self::scenarios()[$case]), $flags);
		$golden = (is_file(self::GOLDEN) === true ? json_decode((string)file_get_contents(self::GOLDEN), true) : []);

		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$case] = json_decode((string)$actual, true);
			ksort($golden);
			$lines = [];
			foreach ($golden as $name => $recorded) {
				$lines[] = json_encode((string)$name) . ': ' . json_encode($recorded, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
			}

			file_put_contents(self::GOLDEN, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('recorded');
		}

		$this->assertArrayHasKey($case, $golden);
		$this->assertSame(json_encode($golden[$case], $flags), $actual);

	}//end testGolden()

	/**
	 * Call adjustFor() with one correction.
	 *
	 * @param RetroAdjustmentService $service The service.
	 * @param array<int, mixed> $a Period, employee, reference, gross, type, settlement period, apply.
	 *
	 * @return array<string, mixed>
	 */
	private function adjust(RetroAdjustmentService $service, array $a): array {
		return $service->adjustFor(new RetroCorrection($a[0], $a[1], $a[2], ($a[3] ?? null)), ($a[4] ?? null), ($a[5] ?? null), ($a[6] ?? false));

	}//end adjust()

	/**
	 * Run one scenario: each call's outcome, then every event.
	 *
	 * @param array<string, mixed> $s The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function free(array $s): array {
		$this->events = [];
		$store = $this->store(array_replace(self::rows(), ($s['rows'] ?? [])), ($s['failSave'] ?? false));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$service = new RetroAdjustmentService($container, $settings, new EmployerPremiumSettings($this->createMock(IAppConfig::class)), new PayrollCalculator(), $this->logger());
		$results = [];
		foreach ($s['calls'] as $call) {
			$results[] = ($call[0] === 'recompute') ? $service->recomputeAdjustment($call[1]) : $this->adjust($service, $call[1]);
		}

		$json = (string)json_encode(['results' => $results, 'events' => $this->events], (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
		return json_decode((string)preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/', '(now)', $json), true);

	}//end free()

	/**
	 * A logger that records every line as an event.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		return new class($this) extends AbstractLogger {
			/**
			 * @param RetroAdjustmentGoldenTest $test The test recording events.
			 */
			public function __construct(private readonly RetroAdjustmentGoldenTest $test) {

			}//end __construct()

			/**
			 * @param mixed $level The level.
			 * @param string|Stringable $message The message.
			 * @param array<mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->test->events[] = ['log' => $level, 'message' => (string)$message];
			}//end log()
		};

	}//end logger()

	/**
	 * A recording ObjectService over seeded rows.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows Rows by schema.
	 * @param bool $failSave Whether a save throws.
	 *
	 * @return object
	 */
	private function store(array $rows, bool $failSave): object {
		return new class($rows, $failSave, $this) {
			/**
			 * The schema of the next read.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, list<array<string, mixed>>> $rows Rows by schema.
			 * @param bool $failSave Whether a save throws.
			 * @param RetroAdjustmentGoldenTest $test The test recording events.
			 */
			public function __construct(private array $rows, private readonly bool $failSave, private readonly RetroAdjustmentGoldenTest $test) {

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
			 * @param array<string, mixed> $options The options.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return ($this->rows[$this->schema] ?? []);
			}//end findAll()

			/**
			 * @param array<string, mixed> $object The object.
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 * @param string|null $uuid The id.
			 * @param bool $_rbac Unused.
			 * @param bool $_multitenancy Unused.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->events[] = ['save' => [$register, $schema, $uuid, $_rbac, $_multitenancy, $object]];
				if ($this->failSave === true) {
					throw new RuntimeException('save refused');
				}

				$saved = array_merge($object, ['id' => ($uuid ?? 'adj-new-' . count($this->test->events))]);
				$this->rows[(string)$schema][] = $saved;
				return $saved;
			}//end saveObject()
		};

	}//end store()

	/**
	 * The register rows every scenario starts from.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function rows(): array {
		$payslip = ['id' => 'ps-1', 'payrollRunId' => 'run-feb', 'employeeId' => 'e1', 'period' => '2026-02', 'grossPay' => 3500.00, 'loonheffing' => 620.00, 'nettoPay' => 2880.00, 'werknemersverzekeringen' => 386.00, 'zvw' => 213.50, 'volksverzekeringen' => 400.00, 'vakantiegeldReserved' => 280.00];

		return [
			'Employee' => [
				['id' => 'e1', 'employeeNumber' => 'EMP-1', 'dateOfBirth' => '1990-04-12', 'taxTableColor' => 'wit', 'loonheffingskortingToegepast' => true],
				['@self' => ['id' => 'e2', 'slug' => 'jan'], 'taxTableColor' => 'groen', 'loonheffingskortingToegepast' => false],
				['id' => 'e3', 'dateOfBirth' => '2007-05-01', 'taxTableColor' => 'paars'],
				['id' => 'e4', 'taxTableColor' => 'wit'],
			],
			'EmploymentContract' => [
				['id' => 'c1', 'employeeId' => 'e1', 'startDate' => '2022-01-01', 'endDate' => null, 'hoursPerWeek' => 36.0, 'awfTariff' => 'low', 'type' => 'permanent', 'writtenContract' => true],
				['id' => 'c2', 'employeeId' => 'jan', 'startDate' => '2025-01-01', 'endDate' => '2026-06-30', 'hoursPerWeek' => 20.0, 'type' => 'temporary'],
				['id' => 'c4', 'employeeId' => 'e4', 'startDate' => '2026-01-01', 'hoursPerWeek' => 24.0, 'type' => 'temporary', 'writtenContract' => false],
			],
			'PayrollRun' => [
				['id' => 'run-feb', 'period' => '2026-02', 'administrationId' => 'ADM-1', 'status' => 'approved'],
				['id' => 'run-jan', 'period' => '2026-01', 'administrationId' => 'ADM-1', 'status' => 'draft'],
				['id' => 'run-apr', 'period' => '2026-04', 'administrationId' => 'ADM-1', 'status' => 'draft'],
				['id' => 'run-mar', 'period' => '2026-03', 'administrationId' => 'ADM-1', 'status' => 'draft'],
				['id' => 'run-apr-2', 'period' => '2026-04', 'administrationId' => 'ADM-2', 'status' => 'draft'],
				['id' => 'run-2019', 'period' => '2019-02', 'administrationId' => 'ADM-1', 'status' => 'approved'],
				['id' => '', 'period' => '', 'status' => 'draft'],
			],
			'Payslip' => [
				$payslip,
				array_merge($payslip, ['id' => 'ps-2', 'employeeId' => 'e2', 'awfTariff' => 'high', 'grossPay' => 2000.00, 'nettoPay' => 1800.00]),
				array_merge($payslip, ['id' => 'ps-3', 'employeeId' => 'e3']),
				array_merge($payslip, ['id' => 'ps-4', 'employeeId' => 'e4', 'hoursWorked' => 80, 'overtimeHours' => 4]),
				array_merge($payslip, ['id' => 'ps-9', 'employeeId' => 'e9']),
				array_merge($payslip, ['id' => 'ps-jan', 'period' => '2026-01', 'payrollRunId' => 'run-jan']),
				array_merge($payslip, ['id' => 'ps-dec', 'period' => '2025-12', 'payrollRunId' => 'run-gone']),
				array_merge($payslip, ['id' => 'ps-nov', 'period' => '2025-11', 'payrollRunId' => ' ']),
				array_merge($payslip, ['id' => 'ps-2019', 'period' => '2019-02', 'payrollRunId' => 'run-2019']),
			],
			'PayrollAdjustment' => [
				['id' => 'adj-1', 'originalPeriod' => '2026-02', 'employeeId' => 'e1', 'correctionRef' => 't1', 'correctedGrossMonthlySalary' => 3800.00, 'correctionType' => 'backdated-raise', 'status' => 'draft', 'settlementPeriod' => '2026-03'],
				['id' => 'adj-2', 'originalPeriod' => '2026-02', 'employeeId' => 'e1', 'correctionRef' => 't2', 'correctedGrossMonthlySalary' => 'onbekend', 'status' => 'applied', 'settlementPayrollRunId' => 'run-mar'],
				['@self' => ['id' => 'adj-3'], 'originalPeriod' => '2026-02', 'employeeId' => 'e1', 'correctionRef' => 't3', 'correctedGrossMonthlySalary' => 3000.00, 'status' => 'draft'],
			],
		];

	}//end rows()

	/**
	 * Every scenario, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		return [
			'malformed period' => ['calls' => [['adjust', ['2026-13', 'e1', 'x', 3800.0]], ['adjust', [' 2026-2 ', 'e1', 'x', 3800.0]], ['adjust', ['', 'e1', 'x']]]],
			'blank employee or reference' => ['calls' => [['adjust', [' 2026-02 ', ' ', 'x', 3800.0]], ['adjust', ['2026-02', 'e1', '  ', 3800.0]]]],
			'gross required' => ['calls' => [['adjust', ['2026-02', 'e1', 'new']], ['adjust', ['2026-02', 'e1', 'new', 0.0]], ['adjust', ['2026-02', 'e1', 'new', -10.0]], ['adjust', ['2026-02', 'e1', 't2']]]],
			'computed back-payment' => ['calls' => [['adjust', ['2026-02', ' e1 ', ' new ', 3800.0, 'backdated-raise', '2026-05']]]],
			'computed recovery' => ['calls' => [['adjust', ['2026-02', 'e1', 'new', 3000.0, null, '2026-04']]]],
			'zero net delta' => ['rows' => ['Payslip' => [['id' => 'ps-1', 'payrollRunId' => 'run-feb', 'employeeId' => 'e1', 'period' => '2026-02', 'grossPay' => 3800.00, 'loonheffing' => 620.00, 'nettoPay' => 3081.17, 'werknemersverzekeringen' => 386.00, 'zvw' => 213.50, 'volksverzekeringen' => 400.00, 'vakantiegeldReserved' => 280.00]]], 'calls' => [['adjust', ['2026-02', 'e1', 'z', 3800.0, null, '2026-04']]]],
			'auto settlement period' => ['calls' => [['adjust', ['2026-02', 'e1', 'new', 3800.0]], ['adjust', ['2026-02', 'e1', 'other', 3800.0, null, ' ']]]],
			'no draft to settle into' => ['rows' => ['PayrollRun' => [['id' => 'run-feb', 'period' => '2026-02', 'administrationId' => 'ADM-1', 'status' => 'approved']]], 'calls' => [['adjust', ['2026-02', 'e1', 'new', 3800.0]]]],
			'applied' => ['calls' => [['adjust', ['2026-02', 'e1', 'new', 3800.0, null, '2026-04', true]], ['adjust', ['2026-02', 'e1', 'other', 3800.0, null, '2026-07', true]]]],
			'idempotent reuse' => ['calls' => [['adjust', ['2026-02', 'e1', 't1']], ['adjust', ['2026-02', 'e1', 't1', 3900.0, 'correctie']], ['adjust', ['2026-02', 'e1', 't2', 3700.0, null, '2026-04']], ['adjust', ['2026-02', 'e1', 't3', null, null, '2026-04', true]]]],
			'green table without heffingskorting' => ['calls' => [['adjust', ['2026-02', 'e2', 'g', 2400.0, null, '2026-04']]]],
			'resolved awf tariff' => ['calls' => [['adjust', ['2026-02', 'e4', 'r', 3600.0, null, '2026-04']]]],
			'refusals' => ['calls' => [
				['adjust', ['2026-02', 'e3', 'x', 3800.0, null, '2026-04']],
				['adjust', ['2026-02', 'e9', 'x', 3800.0, null, '2026-04']],
				['adjust', ['2026-02', 'nobody', 'x', 3800.0, null, '2026-04']],
				['adjust', ['2026-01', 'e1', 'x', 3800.0, null, '2026-04']],
				['adjust', ['2025-12', 'e1', 'x', 3800.0, null, '2026-04']],
				['adjust', ['2025-11', 'e1', 'x', 3800.0, null, '2026-04']],
				['adjust', ['2019-02', 'e1', 'x', 3800.0, null, '2026-04']],
			]],
			'no covering contract' => ['rows' => ['EmploymentContract' => [['id' => 'c1', 'employeeId' => 'e1', 'startDate' => '2026-03-01'], ['id' => 'c9', 'employeeId' => 'EMP-1', 'startDate' => '2020-01-01', 'endDate' => '2026-01-31']]], 'calls' => [['adjust', ['2026-02', 'e1', 'x', 3800.0, null, '2026-04']]]],
			'contract by employee number' => ['rows' => ['EmploymentContract' => [['id' => 'c9', 'employeeId' => 'EMP-1', 'startDate' => '2020-01-01', 'endDate' => 'onbekend', 'awfTariff' => 'high']]], 'calls' => [['adjust', ['2026-02', 'e1', 'x', 3800.0, null, '2026-04']]]],
			'save fails' => ['failSave' => true, 'calls' => [['adjust', ['2026-02', 'e1', 'new', 3800.0, null, '2026-04']], ['adjust', ['2026-02', 'e1', 't1', null, null, null, true]]]],
			'recompute' => ['calls' => [['recompute', 'adj-1'], ['recompute', 'adj-2'], ['recompute', 'adj-3'], ['recompute', 'nope']]],
		];

	}//end scenarios()

}//end class
