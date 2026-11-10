<?php

/**
 * Golden test for PayrollRunService's run generation.
 *
 * Runs whole payroll runs over a fixed set of employees that between them
 * take every branch of the generation: each skip reason, the sick-pay,
 * bijtelling, leave buy/sell, CAO, claims and allowances, 30% ruling, retro
 * adjustment and loonbeslag folds, the hourly path, the upsert of an existing
 * payslip, orphan cleanup, and each failure exit. Every outcome, every object
 * written or deleted, every collaborator call and every log line is compared
 * with tests/Unit/Service/golden/payroll-run-generate.json. Set
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
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\SickPayCalculator;
use OCA\Humaniq\Service\AwfReviewService;
use OCA\Humaniq\Service\CaoComponentCalculator;
use OCA\Humaniq\Service\CaoComponentPayService;
use OCA\Humaniq\Service\CostAllocationService;
use OCA\Humaniq\Service\EmploymentTermsResolver;
use OCA\Humaniq\Service\HoursPayService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\PayrollExpenseFoldService;
use OCA\Humaniq\Service\PayrollRetentionGuardService;
use OCA\Humaniq\Service\PayrollRunCheckService;
use OCA\Humaniq\Service\PayrollRunService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\WorkingCalendarReader;
use OCA\OpenRegister\Service\Object\ObjectDeletion;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden cases for PayrollRunService::runFor() and recalculateRun().
 *
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-003
 */
class PayrollRunGenerateGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/payroll-run-generate.json';

	/**
	 * Every collaborator call of the current case, in call order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * The golden cases.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = [
			'recalculate with every fold',
			'recalculate without optional folds',
			'new run with every fold',
			'recalculateRun by id',
			'unknown jurisdiction fails before any write',
			'lower-case jurisdiction is normalised',
			'payslip save fails',
			'run totals save fails',
			'orphan delete fails and the run goes on',
			'run check and allocation fail',
			'no employee computes',
			'cao night premium on a holiday in a part month',
		];
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
		$actual = json_encode($this->observe($case), (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
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

		$this->assertArrayHasKey($case, $golden, 'No golden recording for this case');
		$this->assertSame(
			json_encode($golden[$case], (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
			$actual
		);

	}//end testGolden()

	/**
	 * Run a case and describe everything it did.
	 *
	 * @param string $case The case name.
	 *
	 * @return array<string, mixed>
	 */
	private function observe(string $case): array {
		$this->calls = [];
		$rows = $this->rows();
		$withFolds = in_array($case, ['recalculate without optional folds'], true) === false;

		if ($case === 'new run with every fold' || $case === 'no employee computes') {
			$rows['PayrollRun'] = [];
			$rows['Payslip'] = [];
		}

		if ($case === 'no employee computes') {
			$rows['Employee'] = [$this->employee('emp-x', ['startDate' => '2027-01-01'])];
		}

		if ($case === 'unknown jurisdiction fails before any write') {
			$rows['PayrollRun'][0]['jurisdiction'] = 'XX';
		}

		if ($case === 'lower-case jurisdiction is normalised') {
			$rows['PayrollRun'][0]['jurisdiction'] = ' nl ';
		}

		$calendar = ($case === 'cao night premium on a holiday in a part month');
		if ($calendar === true) {
			$rows = $this->caoNightRows($rows);
		}

		$fake = $this->fakeObjectService($rows);
		if ($case === 'payslip save fails') {
			$fake->failSave = static fn (string $schema, array $object): bool => ($schema === 'Payslip' && ($object['employeeId'] ?? '') === 'emp-sick');
		}

		if ($case === 'run totals save fails') {
			$fake->failSave = static fn (string $schema, array $object): bool => ($schema === 'PayrollRun' && array_key_exists('totalGross', $object) === true);
		}

		if ($case === 'orphan delete fails and the run goes on') {
			$fake->failDelete = static fn (string $uuid): bool => ($uuid === 'ps-orphan');
		}

		$service = $this->service($fake, $withFolds, ($case === 'run check and allocation fail'), $calendar);

		if ($case === 'recalculateRun by id') {
			$outcome = $service->recalculateRun('run-1');
		} else if ($case === 'new run with every fold' || $case === 'no employee computes') {
			$outcome = $service->runFor('2026-02');
		} else {
			$outcome = $service->runFor('2026-02', 'ADM-001', true);
		}

		return [
			'outcome' => $this->scrub($outcome),
			'saved' => $this->scrub($fake->saved),
			'deleted' => $fake->deleted,
			'calls' => $this->scrub($this->calls),
		];

	}//end observe()

	/**
	 * Replace the wall-clock stamp and objects by stable text.
	 *
	 * @param mixed $value The value to scrub.
	 *
	 * @return mixed
	 */
	private function scrub(mixed $value): mixed {
		if (is_object($value) === true) {
			return '<' . get_class($value) . '>';
		}

		if (is_array($value) === false) {
			return $value;
		}

		$out = [];
		foreach ($value as $key => $item) {
			$out[$key] = ($key === 'calculatedAt' && is_string($item) === true && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $item) === 1) ? '<now>' : $this->scrub($item);
		}

		return $out;

	}//end scrub()

	/**
	 * A mock of `$class` whose every public method logs its call and answers
	 * from `$answers`, or with an empty value of its return type.
	 *
	 * @param class-string        $class   The class to mock.
	 * @param array<string, mixed> $answers Return values (or closures) by method name.
	 *
	 * @return object
	 */
	private function recorder(string $class, array $answers = []): object {
		$mock = $this->createMock($class);
		$short = substr($class, (strrpos($class, '\\') + 1));
		foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->isConstructor() === true || $method->isStatic() === true) {
				continue;
			}

			$name = $method->getName();
			$type = (string)$method->getReturnType();
			$mock->method($name)->willReturnCallback(
				function (mixed ...$args) use ($short, $name, $type, $answers): mixed {
					if ($short === 'PayrollRetentionGuardService') {
						// The held payslip is already in the saved list; its id is enough.
						$args[0] = 'id:' . (string)($args[0]['id'] ?? '');
					}

					$this->calls[] = ['call' => $short . '::' . $name, 'args' => $args];
					if (array_key_exists($name, $answers) === true) {
						return ($answers[$name] instanceof \Closure) ? ($answers[$name])(...$args) : $answers[$name];
					}

					return match (ltrim($type, '?')) {
						'bool' => true,
						'int' => 0,
						'array' => [],
						'string' => '',
						default => null,
					};
				}
			);
		}//end foreach

		return $mock;

	}//end recorder()

	/**
	 * The service under test, wired to the fake ObjectService.
	 *
	 * @param object $fake       The fake ObjectService.
	 * @param bool   $withFolds  Whether the optional folds are wired.
	 * @param bool   $foldsThrow Whether the run check and allocation throw.
	 * @param bool   $calendar   Whether a working calendar names 16 February 2026 a public holiday.
	 *
	 * @return PayrollRunService
	 */
	private function service(object $fake, bool $withFolds, bool $foldsThrow, bool $calendar = false): PayrollRunService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($fake);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getPayrollAofTariff')->willReturn('laag');
		$settings->method('getPayrollWhkPercentage')->willReturnArgument(0);

		$logger = $this->recorder(LoggerInterface::class);
		$guard = $this->recorder(PayrollRetentionGuardService::class);
		if ($withFolds === false) {
			return new PayrollRunService($container, $settings, new PayrollCalculator(), new SickPayCalculator(), $guard, $logger);
		}

		$throw = static function (): never {
			throw new \RuntimeException('fold down');
		};
		$foldRows = $fake->rowsBySchema;
		$gateway = $this->recorder(HoursRegisterGateway::class, ['loadAll' => static fn (string $schema): array => ($foldRows[$schema] ?? []), 'save' => static fn (): object => new \stdClass()]);

		return new PayrollRunService(
			$container,
			$settings,
			new PayrollCalculator(),
			new SickPayCalculator(),
			$guard,
			$logger,
			hoursPay: new HoursPayService(new EmploymentTermsResolver(), $gateway, new InternalWriteMarker()),
			expenses: new PayrollExpenseFoldService($gateway, new InternalWriteMarker()),
			runCheck: $this->recorder(PayrollRunCheckService::class, ['check' => ($foldsThrow === true ? $throw : ['status' => 'checked'])]),
			caoComponents: new CaoComponentPayService(new EmploymentTermsResolver(), new CaoComponentCalculator(), $logger),
			costAllocation: $this->recorder(CostAllocationService::class, ['allocateRun' => ($foldsThrow === true ? $throw : 7)]),
			awfReview: $this->recorder(AwfReviewService::class, ['yearToDate' => static fn (string $employeeId, array $contracts, string $period, float $paidHours, bool $low = true): array => ['paid' => $paidHours, 'contract' => count($contracts), 'averageHoursPerWeek' => ($low === true ? 1.5 : 2.5), 'overrunPercent' => strlen($employeeId)]]),
			calendar: ($calendar === true ? $this->recorder(WorkingCalendarReader::class, ['nonWorkingDates' => ['dates' => ['2026-02-16'], 'source' => 'test']]) : null),
		);

	}//end service()

	/**
	 * The CAO employee paid by the hour from 10 February, with the shift
	 * allowance and the night premium, and an approved timesheet of night
	 * entries on a public holiday (16 February) and on a weekday.
	 *
	 * @param array<string, mixed> $rows The base rows.
	 *
	 * @return array<string, mixed>
	 */
	private function caoNightRows(array $rows): array {
		foreach ($rows['Employee'] as $i => $employee) {
			if ($employee['id'] === 'emp-cao') {
				$rows['Employee'][$i]['grossMonthlySalary'] = null;
			}
		}

		foreach ($rows['EmploymentContract'] as $i => $contract) {
			if ($contract['employeeId'] === 'emp-cao') {
				$rows['EmploymentContract'][$i] = $this->contract('emp-cao', ['cao' => 'cao-voorbeeld', 'caoComponents' => ['ploegentoeslag', 'nachttoeslag'], 'startDate' => '2026-02-10', 'hoursPerWeek' => 24.0, 'hourlyWage' => 18.5]);
			}
		}

		$rows['Timesheet'][] = ['id' => 'ts-cao', 'employeeId' => 'emp-cao', 'period' => '2026-02', 'hours' => 14, 'status' => 'approved'];
		$rows['TimeEntry'][] = ['id' => 'te-cao-1', 'timesheetId' => 'ts-cao', 'employeeId' => 'emp-cao', 'date' => '2026-02-16', 'startedAt' => '2026-02-16T00:00:00+01:00', 'endedAt' => '2026-02-16T06:00:00+01:00', 'hours' => 6, 'overtime' => false];
		$rows['TimeEntry'][] = ['id' => 'te-cao-2', 'timesheetId' => 'ts-cao', 'employeeId' => 'emp-cao', 'date' => '2026-02-17', 'startedAt' => '2026-02-16T22:00:00+01:00', 'endedAt' => '2026-02-17T06:00:00+01:00', 'hours' => 8, 'overtime' => false];

		return $rows;
	}//end caoNightRows()

	/**
	 * An employee, by id, with the anchor defaults.
	 *
	 * @param string               $id        The employee id.
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function employee(string $id, array $overrides = []): array {
		return array_merge(
			[
				'id' => $id,
				'employeeNumber' => 'NR-' . $id,
				'firstName' => 'First',
				'lastName' => $id,
				'dateOfBirth' => '1990-04-12',
				'startDate' => '2022-01-01',
				'endDate' => null,
				'grossMonthlySalary' => 3800.00,
				'taxTableColor' => 'wit',
				'loonheffingskortingToegepast' => true,
				'bsn' => '123456782',
				'identityDocumentVerified' => true,
				'nextcloudUserId' => 'u-' . $id,
			],
			$overrides
		);

	}//end employee()

	/**
	 * A covering contract for an employee.
	 *
	 * @param string               $employeeId The employee id.
	 * @param array<string, mixed> $overrides  Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function contract(string $employeeId, array $overrides = []): array {
		return array_merge(
			[
				'id' => 'ct-' . $employeeId,
				'employeeId' => $employeeId,
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
	 * The seed rows: one employee per branch of the generation.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function rows(): array {
		$employees = [
			$this->employee('emp-plain'),
			$this->employee('emp-future', ['startDate' => '2027-01-01']),
			$this->employee('emp-nocontract'),
			$this->employee('emp-blue', ['taxTableColor' => 'blauw']),
			$this->employee('emp-hourly', ['grossMonthlySalary' => null]),
			$this->employee('emp-nohours', ['grossMonthlySalary' => null]),
			$this->employee('emp-nowage', ['grossMonthlySalary' => 0]),
			$this->employee('emp-nobsn', ['bsn' => ' ']),
			$this->employee('emp-noid', ['identityDocumentVerified' => 'yes']),
			$this->employee('emp-sick'),
			$this->employee('emp-car'),
			$this->employee('emp-leave'),
			$this->employee('emp-beslag'),
			$this->employee('emp-30', ['thirtyPercentRulingGranted' => true, 'thirtyPercentRulingRate' => 30.0, 'taxTableColor' => 'groen', 'loonheffingskortingToegepast' => false, 'dateOfBirth' => null]),
			$this->employee('emp-dga', ['isDga' => true]),
			$this->employee('emp-cao'),
			$this->employee('emp-retro'),
			$this->employee('emp-cents', ['grossMonthlySalary' => 2999.995]),
			$this->employee('emp-byslug', ['id' => 'emp-byslug', '@self' => ['slug' => 'slug-x']]),
			$this->employee('emp-account', ['nextcloudUserId' => ' jan.jansen ']),
			$this->employee('emp-blankaccount', ['nextcloudUserId' => '   ']),
			$this->employee('emp-zerohours'),
			$this->employee('emp-texthours'),
		];

		$contracts = [];
		foreach ($employees as $employee) {
			if (in_array($employee['id'], ['emp-nocontract', 'emp-byslug'], true) === false) {
				$contracts[] = $this->contract($employee['id']);
			}
		}

		$contracts[] = $this->contract('slug-x', ['id' => 'ct-slug', 'hoursPerWeek' => 20.0]);
		$contracts[] = $this->contract('emp-nocontract', ['id' => 'ct-old', 'endDate' => '2025-12-31']);
		foreach ($contracts as $i => $contract) {
			if ($contract['employeeId'] === 'emp-hourly') {
				$contracts[$i] = $this->contract('emp-hourly', ['hoursPerWeek' => 32.0, 'hourlyWage' => 16.00]);
			}

			if ($contract['employeeId'] === 'emp-nohours') {
				$contracts[$i] = $this->contract('emp-nohours', ['hourlyWage' => 15.00]);
			}

			if ($contract['employeeId'] === 'emp-zerohours') {
				$contracts[$i] = $this->contract('emp-zerohours', ['hoursPerWeek' => 0]);
			}

			if ($contract['employeeId'] === 'emp-texthours') {
				$contracts[$i] = $this->contract('emp-texthours', ['hoursPerWeek' => 'veertig']);
			}

			if ($contract['employeeId'] === 'emp-cao') {
				$contracts[$i] = $this->contract('emp-cao', ['cao' => 'cao-voorbeeld', 'caoComponents' => ['ploegentoeslag']]);
			}
		}

		return [
			'hrAdministration' => [['administrationId' => 'ADM-001', 'payrollProcessing' => 'engine']],
			'PayrollRun' => [['id' => 'run-1', 'period' => '2026-02', 'administrationId' => 'ADM-001', 'status' => 'draft', '@self' => ['id' => 'run-1']]],
			'Employee' => $employees,
			'EmploymentContract' => $contracts,
			'Payslip' => [
				['id' => 'ps-plain', 'payrollRunId' => 'run-1', 'employeeId' => 'emp-plain', 'grossPay' => 1.0],
				['id' => 'ps-orphan', 'payrollRunId' => 'run-1', 'employeeId' => 'emp-nocontract'],
				['id' => 'ps-other', 'payrollRunId' => 'run-0', 'employeeId' => 'emp-dga'],
				['id' => 'ps-hand', 'employeeId' => 'emp-car'],
			],
			'Timesheet' => [
				['id' => 'ts-1', 'employeeId' => 'emp-hourly', 'period' => '2026-02', 'hours' => 128, 'status' => 'approved'],
				['id' => 'ts-2', 'employeeId' => 'emp-plain', 'period' => '2026-02', 'hours' => 160, 'overtimeHours' => 4, 'status' => 'approved'],
				['id' => 'ts-3', 'employeeId' => 'emp-sick', 'period' => '2026-02', 'hours' => 100, 'status' => 'approved'],
			],
			'TimeEntry' => [
				['id' => 'te-1', 'timesheetId' => 'ts-1', 'employeeId' => 'emp-hourly', 'date' => '2026-02-10', 'hours' => 3, 'overtime' => true, 'overtimeCompensation' => 'pay'],
				['id' => 'te-2', 'timesheetId' => 'ts-1', 'employeeId' => 'emp-hourly', 'date' => '2026-02-11', 'hours' => 2, 'overtime' => false],
				['id' => 'te-3', 'timesheetId' => 'ts-3', 'employeeId' => 'emp-sick', 'date' => '2026-02-05', 'hours' => 2, 'overtime' => true, 'overtimeCompensation' => 'pay'],
			],
			'SickLeaveCase' => [
				['id' => 'case-1', 'employeeId' => 'emp-sick', 'firstSickDay' => '2026-02-10', 'status' => 'gemeld', 'wachtdag' => false, 'loondoorbetalingPercentage' => 70],
				['id' => 'case-2', 'employeeId' => 'emp-plain', 'firstSickDay' => '2026-01-10', 'status' => 'hersteld', 'loondoorbetalingPercentage' => 70],
			],
			'Asset' => [
				['id' => 'veh-1', 'name' => 'Car', 'category' => 'vehicle', 'licencePlate' => '1-ABC-23', 'listPrice' => 45000.00, 'fuelType' => 'fullyElectric', 'companyCarTaxCategory' => 'standard', 'status' => 'available', 'active' => true],
				['id' => 'lap-1', 'name' => 'Laptop', 'category' => 'laptop', 'active' => true],
			],
			'AssetAssignment' => [
				['id' => 'ca-1', 'assetId' => 'veh-1', 'employeeId' => 'emp-car', 'issuedOn' => '2026-01-01', 'returnedOn' => null, 'employeeContribution' => 325.00],
				['id' => 'ca-2', 'assetId' => 'lap-1', 'employeeId' => 'emp-plain', 'issuedOn' => '2026-01-01', 'returnedOn' => null],
			],
			'LeaveTransaction' => [
				['id' => 'txn-1', 'employeeId' => 'emp-leave', 'transactionType' => 'sell', 'status' => 'settled', 'settlementPeriod' => '2026-02', 'settledAmount' => 200.00],
				['id' => 'txn-2', 'employeeId' => 'emp-leave', 'transactionType' => 'buy', 'status' => 'settled', 'settlementPeriod' => '2026-02', 'settledAmount' => 50.00],
				['id' => 'txn-3', 'employeeId' => 'emp-plain', 'transactionType' => 'sell', 'status' => 'approved', 'settlementPeriod' => '2026-02', 'settledAmount' => null],
			],
			'Loonbeslag' => [
				['id' => 'lb-1', 'employeeId' => 'emp-beslag', 'creditor' => 'Creditor', 'dossierRef' => 'D-1', 'totalClaim' => 4200.00, 'orderedAmount' => 800.00, 'beslagvrijeVoet' => 2950.00, 'status' => 'actief', 'effectiveFrom' => '2026-01-01', 'effectiveTo' => null],
				['id' => 'lb-2', 'employeeId' => 'emp-plain', 'orderedAmount' => 100.00, 'beslagvrijeVoet' => 1000.00, 'status' => 'concept', 'effectiveFrom' => '2026-01-01'],
			],
			'PayrollAdjustment' => [
				['id' => 'adj-1', 'employeeId' => 'emp-retro', 'originalPeriod' => '2026-01', 'correctionRef' => 't1', 'status' => 'applied', 'settlementPeriod' => '2026-02', 'settlementLine' => 'nabetaling', 'deltaGross' => 250.00, 'deltaLoonheffing' => 48.83, 'deltaNet' => 201.17, 'deltaZvw' => 16.00, 'deltaWerknemersverzekeringen' => 20.00],
				['id' => 'adj-2', 'employeeId' => 'emp-beslag', 'originalPeriod' => '2026-01', 'status' => 'applied', 'settlementPeriod' => '2026-02', 'deltaNet' => -40.00],
				['id' => 'adj-3', 'employeeId' => 'emp-plain', 'status' => 'draft', 'settlementPeriod' => '2026-02', 'deltaNet' => 99.00],
			],
			'Expense' => [
				['id' => 'exp-1', 'employeeId' => 'emp-beslag', 'title' => 'Train', 'amount' => 27.40, 'status' => 'approved', 'approvedAt' => '2026-02-12T10:00:00Z', 'reimbursementRoute' => 'payroll'],
			],
			'RecurringAllowance' => [
				['id' => 'alw-home', 'employeeId' => 'emp-plain', 'kind' => 'thuiswerk', 'amountPerDay' => 2.45, 'daysPerMonth' => 8, 'taxTreatment' => 'gericht-vrijgesteld', 'startDate' => '2026-01-01', 'status' => 'active'],
				['id' => 'alw-phone', 'employeeId' => 'emp-30', 'kind' => 'telefoon', 'amountPerMonth' => 20.00, 'taxTreatment' => 'belast', 'startDate' => '2026-01-01', 'status' => 'active'],
			],
		];

	}//end rows()

	/**
	 * A fake ObjectService over the seed rows that records every write and
	 * delete, and fails a save or delete when its hook says so.
	 *
	 * @param array<string, list<array<string, mixed>>> $rowsBySchema The seed rows.
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
			 * @var int
			 */
			private int $nextId = 1;

			/**
			 * @var list<array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * @var list<string>
			 */
			public array $deleted = [];

			/**
			 * @var \Closure|null
			 */
			public ?\Closure $failSave = null;

			/**
			 * @var \Closure|null
			 */
			public ?\Closure $failDelete = null;

			/**
			 * @param array<string, list<array<string, mixed>>> $rowsBySchema The seed rows.
			 */
			public function __construct(public array $rowsBySchema) {
			}//end __construct()

			/**
			 * @param string $register Unused.
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
			 * @param array<string, mixed> $options Unused.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return ($this->rowsBySchema[$this->schema] ?? []);
			}//end findAll()

			/**
			 * @param array<string, mixed> $object        The object.
			 * @param string|null          $register      Unused.
			 * @param string|null          $schema        The schema.
			 * @param string|null          $uuid          The id when updating.
			 * @param bool                 $_rbac         Recorded.
			 * @param bool                 $_multitenancy Recorded.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$target = ($schema ?? $this->schema);
				$this->saved[] = ['schema' => $target, 'register' => $register, 'uuid' => $uuid, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'object' => $object];
				if ($this->failSave !== null && ($this->failSave)($target, $object) === true) {
					throw new \RuntimeException('save refused');
				}

				$saved = array_merge($object, ['id' => ($uuid ?? ('new-' . $target . '-' . $this->nextId++))]);
				$this->rowsBySchema[$target][] = $saved;
				return $saved;
			}//end saveObject()

			/**
			 * @param ObjectDeletion $deletion        The id and the register and schema it is in.
			 * @param bool           $_rbac           Recorded.
			 * @param bool           $_multitenancy   Recorded.
			 * @param bool           $_retentionSweep Unused.
			 *
			 * @return bool
			 */
			public function deleteObject(ObjectDeletion $deletion, bool $_rbac = true, bool $_multitenancy = true, bool $_retentionSweep = false): bool {
				$uuid = $deletion->uuid;
				$schema = $deletion->target->schema;
				$this->deleted[] = $schema . ':' . $uuid . ':' . var_export($_rbac, true) . ':' . var_export($_multitenancy, true);
				if ($this->failDelete !== null && ($this->failDelete)($uuid) === true) {
					throw new \RuntimeException('delete refused');
				}

				return true;
			}//end deleteObject()
		};

	}//end fakeObjectService()
}//end class
