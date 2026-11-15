<?php

/**
 * Characterisation tests for RuleAuditService's WKR, retro and roster context builders.
 *
 * Pins the exact index each builder hands the rule engine for a fixed set of
 * register rows: which rows are kept or skipped, how ids, administrations and
 * years resolve, how amounts sum per (administration, year), which roster
 * assignments count as published, and how the payroll config values parse.
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
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\RuleAuditService;
use OCA\Humaniq\Standards\RuleCatalogue;
use OCA\Humaniq\Standards\RuleEngine;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The context builders produce the same indexes.
 *
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-006
 */
class RuleAuditContextBuildersTest extends TestCase {

	/**
	 * Register rows by schema.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function rows(): array {
		return [
			'PayrollRun' => [
				['id' => 'run-1', 'administrationId' => 'ADM-1'],
				['@self' => ['id' => 'run-2'], 'administrationId' => 'ADM-2'],
				['id' => '', 'administrationId' => 'ADM-X'],
				['id' => 'run-3'],
			],
			'Payslip' => [
				['id' => 'ps-1', 'administrationId' => ' ADM-1 ', 'period' => '2026-03', 'grossPay' => 100, 'wkrUsed' => 5],
				['id' => 'ps-2', 'payrollRunId' => 'run-2', 'period' => '2026-04', 'grossPay' => '50.5'],
				['id' => 'ps-3', 'payrollRunId' => 'run-x', 'period' => '2026-01', 'grossPay' => 10],
				['id' => 'ps-4', 'administrationId' => 'ADM-1', 'period' => 'maart', 'grossPay' => 10],
				['@self' => ['id' => 'ps-5'], 'administrationId' => 'ADM-1', 'period' => '2026-12', 'grossPay' => 1.25, 'wkrUsed' => 1],
				['administrationId' => 'ADM-1', 'period' => '2025-01'],
				['id' => 'ps-6', 'payrollRunId' => 'run-3', 'period' => '2026-02', 'grossPay' => 3],
			],
			'WkrDeclaration' => [
				['administrationId' => 'ADM-1', 'year' => 2026, 'wkrCategory' => 'vrije-ruimte', 'amount' => 7],
				['administrationId' => ' ADM-1', 'year' => '2026', 'wkrCategory' => 'eindheffing', 'amount' => '3'],
				['administrationId' => 'ADM-3', 'year' => 2027, 'wkrCategory' => 'overig', 'amount' => 9],
				['administrationId' => 'ADM-1', 'year' => 2026, 'wkrCategory' => 'gericht-vrijgesteld', 'amount' => 100],
				['administrationId' => '', 'year' => 2026, 'wkrCategory' => 'vrije-ruimte', 'amount' => 100],
				['administrationId' => 'ADM-1', 'year' => 0, 'wkrCategory' => 'vrije-ruimte', 'amount' => 100],
				['administrationId' => 'ADM-2', 'year' => 2026, 'wkrCategory' => 'vrije-ruimte'],
			],
			'Employee' => [
				['id' => 'emp-1', 'dateOfBirth' => '1990-01-01', 'taxTableColor' => 'groen', 'loonheffingskortingToegepast' => false, 'bsn' => '1'],
				['@self' => ['id' => 'emp-2']],
				['id' => '', 'dateOfBirth' => '2000-01-01'],
				['id' => 'emp-3', 'loonheffingskortingToegepast' => 'ja'],
			],
			'EmploymentContract' => [
				['employeeId' => ' emp-1 ', 'type' => 'permanent', 'writtenContract' => true, 'awfTariff' => 'low'],
				['employeeId' => 'emp-2', 'type' => 'temp'],
				['employeeId' => 'emp-2', 'type' => 'bbl', 'writtenContract' => 'yes'],
				['employeeId' => '', 'type' => 'permanent'],
			],
			'Roster' => [
				['id' => 'r1', 'status' => 'gepubliceerd'],
				['@self' => ['id' => 'r2'], 'status' => 'gepubliceerd'],
				['id' => 'r3', 'status' => 'concept'],
				['id' => '', 'status' => 'gepubliceerd'],
			],
			'RosterAssignment' => [
				['rosterId' => 'r1', 'employeeId' => 'e1', 'date' => '2026-01-01', 'plannedStart' => '08:00', 'plannedEnd' => '16:00'],
				['rosterId' => 'r2', 'employeeId' => 'e1', 'date' => '2026-01-02'],
				['rosterId' => 'r3', 'employeeId' => 'e1', 'date' => '2026-01-03', 'plannedStart' => '07:00'],
				['rosterId' => '', 'employeeId' => 'e1', 'date' => '2026-01-04'],
				['rosterId' => 'r1', 'employeeId' => '', 'date' => '2026-01-05'],
				['rosterId' => 'r1', 'employeeId' => 'e2', 'date' => ''],
				['rosterId' => 'r1', 'employeeId' => 'e1', 'date' => '2026-01-01', 'plannedStart' => '09:00'],
			],
		];

	}//end rows()

	/**
	 * The WKR aggregate per administration and year.
	 *
	 * @return void
	 */
	public function testWkrContext(): void {
		$this->assertSame(
			[
				'ADM-1' => [
					2026 => ['loonsom' => 101.25, 'payslipWkrUsed' => 6.0, 'vrijeRuimteDeclared' => 7.0, 'eindheffingDeclared' => 3.0],
					2025 => ['loonsom' => 0.0, 'payslipWkrUsed' => 0.0, 'vrijeRuimteDeclared' => 0.0, 'eindheffingDeclared' => 0.0],
				],
				'ADM-2' => [
					2026 => ['loonsom' => 50.5, 'payslipWkrUsed' => 0.0, 'vrijeRuimteDeclared' => 0.0, 'eindheffingDeclared' => 0.0],
				],
				'ADM-3' => [
					2027 => ['loonsom' => 0.0, 'payslipWkrUsed' => 0.0, 'vrijeRuimteDeclared' => 0.0, 'eindheffingDeclared' => 0.0],
				],
			],
			$this->build('buildWkrContext', [])
		);

	}//end testWkrContext()

	/**
	 * The retro indexes and the parsed payroll config values.
	 *
	 * @return void
	 */
	public function testRetroContext(): void {
		$rows = self::rows();
		$retro = $this->build('buildRetroContext', ['payroll_aof_tariff' => ' HOOG ', 'payroll_whk_percentage' => ' 1.5 ']);

		$this->assertSame(
			[
				'ps-1' => $rows['Payslip'][0],
				'ps-2' => $rows['Payslip'][1],
				'ps-3' => $rows['Payslip'][2],
				'ps-4' => $rows['Payslip'][3],
				'ps-5' => $rows['Payslip'][4],
				'ps-6' => $rows['Payslip'][6],
			],
			$retro['payslipsById']
		);
		$this->assertSame(
			[
				'emp-1' => ['dateOfBirth' => '1990-01-01', 'taxTableColor' => 'groen', 'loonheffingskortingToegepast' => false],
				'emp-2' => ['dateOfBirth' => null, 'taxTableColor' => '', 'loonheffingskortingToegepast' => true],
				'emp-3' => ['dateOfBirth' => null, 'taxTableColor' => '', 'loonheffingskortingToegepast' => false],
			],
			$retro['employeesById']
		);
		$this->assertSame(
			[
				'emp-1' => ['type' => 'permanent', 'writtenContract' => true, 'awfTariff' => 'low'],
				'emp-2' => ['type' => 'bbl', 'writtenContract' => false, 'awfTariff' => ''],
			],
			$retro['contractsByEmployeeId']
		);
		$this->assertSame(['payslipsById', 'employeesById', 'contractsByEmployeeId', 'aofTariff', 'whkPercentageOverride'], array_keys($retro));
		$this->assertSame(['hoog', 1.5], [$retro['aofTariff'], $retro['whkPercentageOverride']]);

		foreach ([['laag', ''], ['x', '-1'], ['', 'veel'], ['Laag', '0']] as [$aof, $whk]) {
			$parsed = $this->build('buildRetroContext', ['payroll_aof_tariff' => $aof, 'payroll_whk_percentage' => $whk]);
			$expectedWhk = ($whk === '0') ? 0.0 : null;
			$this->assertSame(['laag', $expectedWhk], [$parsed['aofTariff'], $parsed['whkPercentageOverride']], $aof.'/'.$whk);
		}

	}//end testRetroContext()

	/**
	 * The planned clock index holds only assignments of published rosters, last one per day wins.
	 *
	 * @return void
	 */
	public function testRosterContext(): void {
		$this->assertSame(
			[
				'plannedClockByEmployeeDate' => [
					'e1' => [
						'2026-01-01' => ['clockIn' => '09:00', 'clockOut' => null],
						'2026-01-02' => ['clockIn' => null, 'clockOut' => null],
					],
				],
			],
			$this->build('buildRosterContext', [])
		);

	}//end testRosterContext()

	/**
	 * The audit report: its keys in order, one stat per supported type, and totals that add up.
	 *
	 * @return void
	 */
	public function testAuditReportShapeAndTotals(): void {
		RuleEngine::reset();
		$service = $this->service([]);
		$report  = $service->audit(['jurisdiction' => 'NL']);

		$this->assertSame(
			['catalogueVersion', 'corpusTotal', 'machineCheckable', 'enforceableRules', 'coveragePct', 'types', 'objectsChecked', 'objectsCompliant', 'objectsWithViolations', 'violationsBySeverity', 'topViolatedRules'],
			array_keys($report)
		);
		$this->assertSame(RuleEngine::supportedTypes(), array_keys($report['types']));
		$this->assertSame(RuleCatalogue::count(), $report['corpusTotal']);
		$this->assertSame(round((count(RuleEngine::checkedRuleIds()) / count(RuleCatalogue::machineCheckable())) * 100, 1), $report['coveragePct']);

		$sum = ['checked' => 0, 'compliant' => 0, 'withViolations' => 0, 'violations' => 0];
		foreach ($report['types'] as $stat) {
			$this->assertSame(array_keys($sum), array_keys($stat));
			foreach ($stat as $key => $value) {
				$sum[$key] += $value;
			}
		}

		$this->assertSame([7, 4], [$report['types']['Payslip']['checked'], $report['types']['PayrollRun']['checked']]);
		$this->assertSame(4, $report['types']['Employee']['checked']);
		$this->assertSame($sum['checked'], $report['objectsChecked']);
		$this->assertSame($sum['compliant'], $report['objectsCompliant']);
		$this->assertSame($sum['withViolations'], $report['objectsWithViolations']);
		$this->assertSame($sum['checked'], $sum['compliant'] + $sum['withViolations']);
		$this->assertSame(['mandatory', 'conditional', 'recommended'], array_slice(array_keys($report['violationsBySeverity']), 0, 3));
		$this->assertSame($sum['violations'], array_sum($report['violationsBySeverity']));
		$this->assertGreaterThan(0, $sum['violations']);

		$counts = array_column($report['topViolatedRules'], 'count');
		$this->assertLessThanOrEqual(15, count($counts));
		$sorted = $counts;
		rsort($sorted);
		$this->assertSame($sorted, $counts);
		foreach ($report['topViolatedRules'] as $entry) {
			$this->assertSame(['ruleId', 'count'], array_keys($entry));
		}

		RuleEngine::reset();

	}//end testAuditReportShapeAndTotals()

	/**
	 * Call one private context builder on a service backed by the fixed rows.
	 *
	 * @param string                $builder The builder method.
	 * @param array<string, string> $config  App config values by key.
	 *
	 * @return array<mixed>
	 */
	private function build(string $builder, array $config): array {
		$service = $this->service($config);

		return (new ReflectionMethod($service, $builder))->invoke($service);

	}//end build()

	/**
	 * A service backed by the fixed rows.
	 *
	 * @param array<string, string> $config App config values by key.
	 *
	 * @return RuleAuditService
	 */
	private function service(array $config): RuleAuditService {
		$objectService = new class(self::rows()) {

			/**
			 * The schema of the next findAll().
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, list<array<string, mixed>>> $rowsBySchema Rows by schema.
			 */
			public function __construct(
				private readonly array $rowsBySchema,
			) {

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
			 * @param array<string, mixed> $options Ignored.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? 'humaniq')
		);

		return new RuleAuditService($container, $appConfig, $this->createMock(LoggerInterface::class));

	}//end service()
}//end class
