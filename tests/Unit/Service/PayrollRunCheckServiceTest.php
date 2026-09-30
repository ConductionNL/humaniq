<?php

/**
 * Unit tests for PayrollRunCheckService.
 *
 * The gateway is a mock that answers loadAll()/findObjectData() per schema
 * and records every save and delete; the anomaly detector is the real class;
 * the rule audit is a mock returning a violation. Findings are validated
 * against the real PayrollRunFinding fragment.
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
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\PayAnomalyDetector;
use OCA\Humaniq\Service\PayrollRunCheckService;
use OCA\Humaniq\Service\RuleAuditService;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Skipped employees, unpaid inputs, rule violations and deviations, kept per run.
 */
class PayrollRunCheckServiceTest extends TestCase {

	/**
	 * Every save.
	 *
	 * @var list<array{payload: array<string, mixed>, schema: string, uuid: ?string}>
	 */
	private array $saves = [];

	/**
	 * Every delete.
	 *
	 * @var list<array{uuid: string, schema: string}>
	 */
	private array $deletes = [];

	/**
	 * A check over the given rows.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows       Rows keyed by schema.
	 * @param list<array<string, mixed>>                $violations What the rule audit reports.
	 * @param string                                    $thresholds The payroll_check_thresholds setting.
	 * @param bool                                      $auditFails Whether the rule audit throws.
	 *
	 * @return PayrollRunCheckService
	 */
	private function service(array $rows, array $violations = [], string $thresholds = '', bool $auditFails = false): PayrollRunCheckService {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('loadAll')->willReturnCallback(static fn (string $schema): array => ($rows[$schema] ?? []));
		$gateway->method('findObjectData')->willReturnCallback(static function (string $uuid, string $schema) use ($rows): ?array {
			foreach (($rows[$schema] ?? []) as $row) {
				if (($row['id'] ?? '') === $uuid) {
					return $row;
				}
			}

			return null;
		});
		$gateway->method('save')->willReturnCallback(function (array $payload, string $schema, ?string $uuid = null): object {
			$this->saves[] = ['payload' => $payload, 'schema' => $schema, 'uuid' => $uuid];
			return new \stdClass();
		});
		$gateway->method('delete')->willReturnCallback(function (string $uuid, string $schema): void {
			$this->deletes[] = ['uuid' => $uuid, 'schema' => $schema];
		});
		$audit = $this->createMock(RuleAuditService::class);
		if ($auditFails === true) {
			$audit->method('auditPayrollRunScope')->willThrowException(new \RuntimeException('rules unavailable'));
		} else {
			$audit->method('auditPayrollRunScope')->willReturn(['violations' => $violations]);
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => ($key === 'payroll_check_thresholds' ? $thresholds : $default));

		return new PayrollRunCheckService($gateway, $audit, new PayAnomalyDetector(), new InternalWriteMarker(), $config, new NullLogger());
	}//end service()

	/**
	 * The saved findings.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function findings(): array {
		return array_values(array_map(static fn (array $s): array => $s['payload'], array_filter($this->saves, static fn (array $s): bool => $s['schema'] === 'PayrollRunFinding')));
	}//end findings()

	/**
	 * The draft run and its rows.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function rows(): array {
		return [
			'PayrollRun' => [['id' => 'run-5', 'period' => '2026-05', 'status' => 'draft', 'administrationId' => 'ADM-001']],
			'Payslip' => [['id' => 'ps-1', 'payrollRunId' => 'run-5', 'employeeId' => 'emp-1', 'period' => '2026-05', 'grossPay' => 3800.0, 'nettoPay' => 2800.0]],
			'Timesheet' => [
				['id' => 'ts-late', 'employeeId' => 'emp-2', 'period' => '2026-05', 'status' => 'approved'],
				['id' => 'ts-paid', 'employeeId' => 'emp-1', 'period' => '2026-05', 'status' => 'approved', 'payrollRunId' => 'run-5'],
			],
			'Expense' => [['id' => 'exp-1', 'employeeId' => 'emp-2', 'status' => 'approved', 'reimbursementRoute' => 'payroll', 'approvedAt' => '2026-05-10T09:00:00Z', 'amount' => 12.0]],
		];
	}//end rows()

	/**
	 * A skipped employee is blocking, an unpaid timesheet and claim are
	 * warnings, a mandatory violation on the run's payslip is blocking, and
	 * the run gets its counts; every finding is valid against the schema.
	 *
	 * @return void
	 */
	public function testTheFourSourcesBecomeFindingsAndCounts(): void {
		$violations = [
			['objectType' => 'Payslip', 'objectId' => 'ps-1', 'ruleId' => 'nl-wml', 'severity' => 'mandatory', 'statement' => 'Onder het minimumloon.'],
			['objectType' => 'Payslip', 'objectId' => 'ps-other', 'ruleId' => 'nl-wml', 'severity' => 'mandatory', 'statement' => 'Andere run.'],
		];
		$summary = $this->service($this->rows(), $violations)->check('run-5', [['employeeId' => 'emp-3', 'employee' => 'Visser', 'reason' => 'no-salary']]);

		$byKind = [];
		foreach ($this->findings() as $finding) {
			$byKind[$finding['kind']][] = $finding['severity'];
			$this->assertSame([], RegisterSchemaValidator::errors('PayrollRunFinding', $finding), json_encode($finding));
			$this->assertSame('run-5', $finding['payrollRunId']);
		}

		$this->assertSame(['blocking'], $byKind['skipped']);
		$this->assertSame(['warning', 'warning'], $byKind['unpaid-input']);
		$this->assertSame(['blocking'], $byKind['rule-violation']);
		$this->assertSame(2, $summary['blocking']);

		$run = array_values(array_filter($this->saves, static fn (array $s): bool => $s['schema'] === 'PayrollRun'))[0];
		$this->assertSame('run-5', $run['uuid']);
		$this->assertSame(2, $run['payload']['blockingFindings']);
		$this->assertSame(2, $run['payload']['warningFindings']);
		$this->assertNotEmpty($run['payload']['checkedAt']);
		$this->assertArrayNotHasKey('id', $run['payload']);
	}//end testTheFourSourcesBecomeFindingsAndCounts()

	/**
	 * An acknowledged finding that comes back keeps its acknowledgement and
	 * gets the new values; an open one and a resolved acknowledged one are
	 * deleted.
	 *
	 * @return void
	 */
	public function testAnAcknowledgedFindingSurvivesARecheck(): void {
		$rows = $this->rows();
		$rows['PayrollRunFinding'] = [
			['id' => 'f-ack', 'payrollRunId' => 'run-5', 'employeeId' => 'emp-3', 'kind' => 'skipped', 'severity' => 'blocking', 'message' => 'oud', 'status' => 'acknowledged', 'acknowledgementNote' => 'Start volgende maand', 'acknowledgedBy' => 'hr-demo'],
			['id' => 'f-open', 'payrollRunId' => 'run-5', 'employeeId' => 'emp-2', 'kind' => 'unpaid-input', 'ruleId' => 'timesheet', 'severity' => 'warning', 'message' => 'oud', 'status' => 'open'],
			['id' => 'f-resolved', 'payrollRunId' => 'run-5', 'employeeId' => 'emp-9', 'kind' => 'skipped', 'severity' => 'blocking', 'message' => 'oud', 'status' => 'acknowledged'],
			['id' => 'f-other-run', 'payrollRunId' => 'run-4', 'employeeId' => 'emp-3', 'kind' => 'skipped', 'severity' => 'blocking', 'message' => 'oud', 'status' => 'open'],
		];

		$this->service($rows)->check('run-5', [['employeeId' => 'emp-3', 'employee' => 'Visser', 'reason' => 'no-salary']]);

		$kept = array_values(array_filter($this->saves, static fn (array $s): bool => $s['uuid'] === 'f-ack'));
		$this->assertCount(1, $kept);
		$this->assertSame('acknowledged', $kept[0]['payload']['status']);
		$this->assertSame('Start volgende maand', $kept[0]['payload']['acknowledgementNote']);
		$this->assertNotSame('oud', $kept[0]['payload']['message']);
		$this->assertSame(['f-open', 'f-resolved'], array_column($this->deletes, 'uuid'));
	}//end testAnAcknowledgedFindingSurvivesARecheck()

	/**
	 * A payslip far from the employee's paid history is a deviation; draft
	 * runs do not count as history.
	 *
	 * @return void
	 */
	public function testADeviationFromPaidHistoryIsFound(): void {
		$rows = $this->rows();
		$rows['PayrollRun'][] = ['id' => 'run-old', 'status' => 'paid', 'period' => '2026-01'];
		$rows['PayrollRun'][] = ['id' => 'run-draft', 'status' => 'draft', 'period' => '2026-04'];
		$rows['Payslip'][0]['nettoPay'] = 5600.0;
		foreach (['2026-01', '2026-02', '2026-03'] as $period) {
			$rows['Payslip'][] = ['id' => 'h-' . $period, 'payrollRunId' => ($period === '2026-01' ? 'run-old' : null), 'employeeId' => 'emp-1', 'period' => $period, 'grossPay' => 3800.0, 'nettoPay' => 2800.0];
		}

		$rows['Payslip'][] = ['id' => 'h-draft', 'payrollRunId' => 'run-draft', 'employeeId' => 'emp-1', 'period' => '2026-04', 'grossPay' => 3800.0, 'nettoPay' => 5600.0];

		$this->service($rows)->check('run-5');

		$deviations = array_values(array_filter($this->findings(), static fn (array $f): bool => $f['kind'] === 'deviation'));
		$this->assertSame(['nettoPay'], array_column($deviations, 'component'));
		$this->assertSame('emp-1', $deviations[0]['employeeId']);
	}//end testADeviationFromPaidHistoryIsFound()

	/**
	 * The thresholds are a setting: a wider net threshold lets the same
	 * doubling pass, and a setting that is not valid JSON keeps the defaults.
	 *
	 * @return void
	 */
	public function testTheThresholdsAreASetting(): void {
		$rows = $this->rows();
		$rows['PayrollRun'][] = ['id' => 'run-old', 'status' => 'paid', 'period' => '2026-01'];
		$rows['Payslip'][0]['nettoPay'] = 5600.0;
		foreach (['2026-01', '2026-02', '2026-03'] as $period) {
			$rows['Payslip'][] = ['id' => 'h-' . $period, 'payrollRunId' => null, 'employeeId' => 'emp-1', 'period' => $period, 'grossPay' => 3800.0, 'nettoPay' => 2800.0];
		}

		$deviations = fn (): array => array_values(array_filter($this->findings(), static fn (array $f): bool => $f['kind'] === 'deviation'));

		$this->service(rows: $rows, thresholds: '{"nettoPay":{"relative":1.5,"absolute":50}}')->check('run-5');
		$this->assertSame([], $deviations());

		$this->saves = [];
		$this->service(rows: $rows, thresholds: 'not json')->check('run-5');
		$this->assertSame(['nettoPay'], array_column($deviations(), 'component'));

		$this->saves = [];
		$this->service(rows: $rows, thresholds: '{"nettoPay":{"relative":"wide"},"unknown":{"relative":1,"absolute":1}}')->check('run-5');
		$this->assertSame(['nettoPay'], array_column($deviations(), 'component'));
	}//end testTheThresholdsAreASetting()

	/**
	 * An unknown run checks nothing and writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownRunChecksNothing(): void {
		$summary = $this->service([])->check('run-x');

		$this->assertSame([], $this->saves);
		$this->assertSame(0, $summary['blocking']);
	}//end testAnUnknownRunChecksNothing()

	/**
	 * A rule audit that cannot run leaves the other sources' findings and
	 * the counts in place.
	 *
	 * @return void
	 */
	public function testAFailingRuleAuditKeepsTheOtherFindings(): void {
		$summary = $this->service(rows: $this->rows(), auditFails: true)->check('run-5', [['employeeId' => 'emp-3', 'reason' => 'no-salary']]);

		$kinds = array_column($this->findings(), 'kind');
		$this->assertNotContains('rule-violation', $kinds);
		$this->assertContains('skipped', $kinds);
		$this->assertSame(1, $summary['blocking']);
	}//end testAFailingRuleAuditKeepsTheOtherFindings()

	/**
	 * A CAO component a payslip of the run could not pay is a warning that
	 * names the component (payroll-cao-components D4).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-003
	 */
	public function testAnUnresolvedCaoComponentIsAWarning(): void {
		$rows = $this->rows();
		$rows['Payslip'][0]['caoComponentsUnresolved'] = ['ort'];
		$this->service($rows)->check('run-5');

		$cao = array_values(array_filter($this->findings(), static fn (array $f): bool => ($f['ruleId'] ?? '') === 'cao:ort'));
		$this->assertCount(1, $cao);
		$this->assertSame('warning', $cao[0]['severity']);
		$this->assertSame('unpaid-input', $cao[0]['kind']);
		$this->assertSame([], RegisterSchemaValidator::errors('PayrollRunFinding', array_merge($cao[0], ['payrollRunId' => 'run-5', 'status' => 'open'])));
	}//end testAnUnresolvedCaoComponentIsAWarning()

}//end class
